<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Transfer;

use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Stores\Store;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Enums\ExportMode;
use Simtabi\Laranail\Settings\Data\CategorySlug;
use Simtabi\Laranail\Settings\Data\SettingWrite;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Actions\SetSetting;
use Simtabi\Laranail\Settings\Data\ChangeRequest;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Actions\LockSetting;
use Simtabi\Laranail\Settings\Support\ValueEncoder;
use Simtabi\Laranail\Settings\Data\Transfer\ImportReport;
use Simtabi\Laranail\Settings\Data\Transfer\ImportRequest;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Data\Transfer\ExportedSetting;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;
use Simtabi\Laranail\Settings\Data\Transfer\ExportedCategory;
use Simtabi\Laranail\Settings\Services\Definitions\Definitions;
use Simtabi\Laranail\Settings\Services\Transfer\StoreSnapshots;
use Simtabi\Laranail\Settings\Actions\Categories\CreateCategory;
use Simtabi\Laranail\Settings\Services\Database\CategoryRepository;

/**
 * Import an export file (G-42) or an importer's document (G-43): needs `import` (step-up
 * verification applies by default). A dry run unless the request says apply. Categories are
 * created first, by slug, parents before children; then each setting is written through the
 * normal write pipeline as the actor (validation, casting, encryption, history, one audit row per
 * key), so a setting that needs approval opens a change request instead. All of it runs in one
 * transaction: one failed write leaves the store as it was. Redacted values are skipped; locks and
 * system categories are applied only when the request is trusted (G-10).
 */
final readonly class ImportStore
{
    public function __construct(
        private AccessGuard $access,
        private StoreSnapshots $snapshots,
        private ValuePipeline $pipeline,
        private ValueEncoder $encoder,
        private SetSetting $set,
        private LockSetting $lock,
        private CreateCategory $createCategory,
        private Definitions $definitions,
    ) {}

    public function handle(ImportRequest $request, ?Actor $actor = null): ImportReport
    {
        $store = $request->store;
        $subject = new Subject($store->name);

        $this->access->authorize($actor, Ability::Import, $subject);

        $current = $this->snapshots->export($store, ExportMode::Ciphertext)->settingsByIdentity();
        $existingCategories = array_map(static fn (Category $category): string => $category->slug->value, CategoryRepository::of($store)->all());
        $plan = $this->plan($request, $current);
        $categories = $this->categoriesToCreate($request->document->categories, $existingCategories);

        if (! $request->apply) {
            return new ImportReport(false, $plan['created'], $plan['updated'], $plan['unchanged'], $plan['skipped'], array_map(static fn (ExportedCategory $category): string => $category->slug->value, $categories));
        }

        return $store->transaction(function () use ($request, $store, $subject, $actor, $plan, $categories): ImportReport {
            foreach ($categories as $category) {
                $this->createCategory->handle($store, $category->toNewCategory($request->trusted), $actor);
            }

            $proposed = [];

            foreach ($plan['write'] as $setting) {
                $target = $this->target($store, $setting, $request->reason);
                $value = $this->pipeline->fromRecord(new Record(
                    $setting->scope,
                    $setting->key,
                    $setting->locale,
                    $setting->ciphertext ?? $this->encoder->encode($setting->key, $setting->value),
                    $setting->type,
                    $setting->ciphertext !== null,
                ), $target->definition);
                $change = $this->set->handle(new SettingWrite($target, $value), $actor);

                if ($change instanceof ChangeRequest) {
                    $proposed[] = $change->id;
                } elseif ($request->trusted && $setting->locked) {
                    $this->lock->handle($target, $actor);
                }
            }

            $this->access->auditor()->record(AuditAction::Import, AuditOutcome::Allowed, $subject, $actor, $request->reason, meta: [
                'created'    => count($plan['created']),
                'updated'    => count($plan['updated']),
                'skipped'    => count($plan['skipped']),
                'categories' => count($categories),
                'trusted'    => $request->trusted,
                'source'     => $request->document->source,
            ]);

            return new ImportReport(true, $plan['created'], $plan['updated'], $plan['unchanged'], $plan['skipped'], array_map(static fn (ExportedCategory $category): string => $category->slug->value, $categories), $proposed);
        });
    }

    /**
     * What each setting of the document would do.
     *
     * @param array<string, ExportedSetting> $current
     *
     * @return array{created: list<string>, updated: list<string>, unchanged: list<string>, skipped: array<string, string>, write: list<ExportedSetting>}
     */
    private function plan(ImportRequest $request, array $current): array
    {
        $plan = ['created' => [], 'updated' => [], 'unchanged' => [], 'skipped' => [], 'write' => []];
        $store = $request->store;

        foreach ($request->document->settings as $setting) {
            $identity = $setting->identity();
            $owner = $setting->scope->owner();

            $skip = match (true) {
                $setting->redacted                                             => 'the export left its value out (encrypted); export it with --with-secrets or --decrypt',
                ! $setting->hasValue                                           => 'it has no value (a lock without a value)',
                $owner instanceof OwnerRef && ! $store->accepts($owner->alias) => sprintf('store [%s] does not accept owner alias [%s]', $store->name, $owner->alias),
                ! $owner instanceof OwnerRef && $store->isDedicated()          => sprintf('store [%s] is dedicated and holds no global values', $store->name),
                default                                                        => null,
            };

            if ($skip !== null) {
                $plan['skipped'][$identity] = $skip;

                continue;
            }

            $existing = $current[$identity] ?? null;

            if ($existing instanceof ExportedSetting && StoreSnapshots::same($existing, $setting)) {
                $plan['unchanged'][] = $identity;

                continue;
            }

            $plan[$existing instanceof ExportedSetting ? 'updated' : 'created'][] = $identity;
            $plan['write'][] = $setting;
        }

        return $plan;
    }

    /**
     * The categories the store lacks, parents before children.
     *
     * @param list<ExportedCategory> $incoming
     * @param list<string> $existing slugs
     *
     * @return list<ExportedCategory>
     */
    private function categoriesToCreate(array $incoming, array $existing): array
    {
        $known = array_fill_keys($existing, true);
        $pending = array_values(array_filter($incoming, static fn (ExportedCategory $category): bool => ! isset($known[$category->slug->value])));
        $ordered = [];

        while ($pending !== []) {
            $ready = array_values(array_filter($pending, static fn (ExportedCategory $category): bool => ! $category->parent instanceof CategorySlug || isset($known[$category->parent->value])));

            // A parent the file names but does not hold: create the child at the top level.
            $ready = $ready === [] ? [array_shift($pending)] : $ready;

            foreach ($ready as $category) {
                $known[$category->slug->value] = true;
                $ordered[] = $category->parent === null || isset($known[$category->parent->value]) ? $category : new ExportedCategory($category->slug, $category->title, null, $category->description, $category->icon, $category->sortOrder, $category->system, $category->titles);
            }

            $pending = array_values(array_filter($pending, static fn (ExportedCategory $category): bool => ! in_array($category, $ready, true)));
        }

        return $ordered;
    }

    private function target(Store $store, ExportedSetting $setting, ?string $reason): SettingTarget
    {
        try {
            $definition = $this->definitions->find($store, $setting->key);
        } catch (SettingsException) {
            $definition = null;
        }

        return new SettingTarget($store, $setting->key, $setting->locale, $setting->scope->owner(), $definition, $reason);
    }
}
