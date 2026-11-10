<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions;

use JsonException;
use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Drivers\DatabaseDriver;
use Simtabi\Laranail\Settings\Enums\TranslationsMode;
use Simtabi\Laranail\Settings\Exceptions\DriverFailure;
use Simtabi\Laranail\Settings\Authorization\AccessGuard;
use Simtabi\Laranail\Settings\Definitions\DefinitionRegistry;

/**
 * Copy every code and config definition into the definitions table as an `is_system` row (D-6,
 * §4.4), so UIs can list them; remove system rows whose definition is gone. Rows edited at
 * runtime (not `is_system`) are never touched; one that shares a key with a code definition is
 * reported as a conflict, because code wins in the merge. Needs `manage_definitions` when an
 * actor is present; audited. Stores without an active database layer, or a schema without the
 * table, are skipped.
 *
 * @phpstan-type SyncReport array{synced: int, removed: int, conflicts: list<string>, skipped: list<string>}
 */
final readonly class SyncDefinitions
{
    public function __construct(
        private StoreRegistry $stores,
        private DefinitionRegistry $definitions,
        private ValueEncoder $encoder,
        private AccessGuard $access,
    ) {}

    /** @return SyncReport */
    public function handle(?Actor $actor = null): array
    {
        $subject = new Subject($this->stores->default()->name);
        $this->access->authorize($actor, Ability::ManageDefinitions, $subject);

        $report = ['synced' => 0, 'removed' => 0, 'conflicts' => [], 'skipped' => []];
        $byStore = [];

        foreach ($this->definitions->all() as $definition) {
            $byStore[$definition->store->value][] = $definition;
        }

        foreach ($this->stores->all() as $name => $store) {
            $database = $store->database();

            if (! $database instanceof DatabaseDriver || ! $database->shape()->definitionsTable) {
                $report['skipped'][] = $name;

                continue;
            }

            $result = $database->attempt($store->name, fn (): array => $database->transaction(fn (): array => $this->sync($store, $database, $byStore[$name] ?? []), $store->name));
            $report['synced'] += $result['synced'];
            $report['removed'] += $result['removed'];
            $report['conflicts'] = [...$report['conflicts'], ...$result['conflicts']];
        }

        $this->access->auditor()->record(AuditAction::ManageDefinitions, AuditOutcome::Allowed, $subject, $actor, meta: [
            'sync'      => true,
            'synced'    => $report['synced'],
            'removed'   => $report['removed'],
            'conflicts' => count($report['conflicts']),
        ]);

        return $report;
    }

    /**
     * @param list<Definition> $definitions
     *
     * @return array{synced: int, removed: int, conflicts: list<string>}
     */
    private function sync(Store $store, DatabaseDriver $database, array $definitions): array
    {
        $table = static fn () => $database->connection()->table($database->names()->definitions())->where('store', $store->name->value);
        $now = CarbonImmutable::now();
        $json = $database->shape()->translations === TranslationsMode::Json;
        $synced = 0;
        $conflicts = [];
        $keys = [];

        foreach ($definitions as $definition) {
            $keys[] = $definition->key->value;
            $row = $table()->where('key', $definition->key->value)->first(['is_system']);

            if ($row !== null && ! in_array($row->is_system ?? false, [true, 1, '1'], true)) {
                $conflicts[] = $store->name->value . '.' . $definition->key->value;

                continue;
            }

            $columns = $this->columns($database, $store, $definition, $json) + ['updated_at' => $now];

            if ($row === null) {
                $table()->insert($columns + DatabaseDriver::newId($database->shape()->ids) + [
                    'store'      => $store->name->value,
                    'key'        => $definition->key->value,
                    'is_system'  => true,
                    'created_at' => $now,
                ]);
            } else {
                $table()->where('key', $definition->key->value)->update($columns);
            }

            $synced++;
        }

        $removed = $table()->where('is_system', true)->whereNotIn('key', $keys === [] ? [''] : $keys)->delete();

        return ['synced' => $synced, 'removed' => $removed, 'conflicts' => $conflicts];
    }

    /** @return array<string, mixed> */
    private function columns(DatabaseDriver $database, Store $store, Definition $definition, bool $json): array
    {
        $category = null;

        if ($definition->category !== null) {
            $categories = $database->connection()->table($database->names()->categories());

            // Definitions are shared by every tenant, so they link to the central categories ('').
            if ($database->shape()->tenancy->hasColumn()) {
                $categories->where('tenant', '');
            }

            $category = $categories->where('store', $store->name->value)->where('slug', $definition->category)->value('id');
        }

        return [
            'slug'        => $definition->key->slug(),
            'group'       => $definition->group(),
            'category_id' => is_int($category) || is_string($category) ? $category : null,
            'type'        => $definition->type,
            // An encrypted setting's default stays in code: the table must not hold a readable copy.
            'default'        => $definition->hasDefault && ! $definition->encrypted ? $this->encoder->encode($definition->key, $definition->default) : null,
            'label'          => $this->text($definition->label, $json),
            'description'    => $this->text($definition->description, $json),
            'rules'          => $this->encode($definition->rules),
            'is_encrypted'   => $definition->encrypted,
            'is_public'      => $definition->public,
            'is_locked'      => $definition->locked,
            'inherits'       => $definition->inherits,
            'overridable_by' => $this->encode($definition->overridableBy),
        ];
    }

    private function text(?string $text, bool $json): ?string
    {
        return $text === null || ! $json ? $text : $this->encode(['' => $text]);
    }

    /** @param array<array-key, mixed> $value */
    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new DriverFailure('A definition could not be encoded for the definitions table.', 0, $exception);
        }
    }
}
