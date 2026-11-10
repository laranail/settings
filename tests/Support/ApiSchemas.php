<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Support;

/**
 * The response shapes of the REST API, as JSON Schema: what each route answers on success, by
 * route name, and one schema per resource. The REST tests validate every response against them,
 * so a resource cannot change its shape without a test noticing.
 */
final class ApiSchemas
{
    /** route name (without `laranail-settings.api.` and `owners.`) => [schema, list?, status] */
    private const array RESPONSES = [
        'stores.index'          => ['Store', true, 200],
        'definitions.index'     => ['Definition', true, 200],
        'settings.index'        => ['Setting', true, 200],
        'settings.show'         => ['Setting', false, 200],
        'settings.update'       => ['Setting', false, 200],
        'settings.lock'         => ['Setting', false, 200],
        'settings.unlock'       => ['Setting', false, 200],
        'settings.rollback'     => ['Setting', false, 200],
        'settings.destroy'      => [null, false, 204],
        'categories.destroy'    => [null, false, 204],
        'configuration.destroy' => [null, false, 204],
        'definitions.destroy'   => [null, false, 204],
        'settings.explain'      => ['Explanation', false, 200],
        'settings.history'      => ['HistoryEntry', true, 200],
        'audit.index'           => ['AuditEntry', true, 200],
        'changes.index'         => ['ChangeRequest', true, 200],
        'changes.store'         => ['ChangeRequest', false, 202],
        'changes.show'          => ['ChangeRequest', false, 200],
        'changes.approve'       => ['ChangeRequest', false, 200],
        'changes.reject'        => ['ChangeRequest', false, 200],
        'changes.cancel'        => ['ChangeRequest', false, 200],
        'categories.index'      => ['Category', true, 200],
        'categories.store'      => ['Category', false, 201],
        'categories.update'     => ['Category', false, 200],
        'configuration.index'   => ['ConfigurationEntry', true, 200],
        'configuration.update'  => ['ConfigurationEntry', false, 200],
        'definitions.update'    => ['Definition', false, 200],
        'transfer.export'       => ['ExportDocument', false, 200],
        'transfer.import'       => ['ImportReport', false, 200],
        'transfer.diff'         => ['StoreDiff', false, 200],
    ];

    /** The routes whose write may open a change request instead (202). */
    private const array MAY_PROPOSE = ['settings.update', 'settings.destroy', 'settings.lock', 'settings.unlock', 'settings.rollback'];

    /** @return list<string> every route name the shapes cover */
    public static function routes(): array
    {
        return array_keys(self::RESPONSES);
    }

    /**
     * The schema a route's response with this status must match: its success shape, a change
     * request (202 from a write that may propose), or a problem (any 4xx and 5xx); null when the
     * route does not answer this status with a body.
     *
     * @return array<string, mixed>|null
     */
    public static function response(string $route, int $status): ?array
    {
        $name = (string) preg_replace('/^laranail-settings\.api\.(owners\.)?/', '', $route);
        [$schema, $list, $success] = self::RESPONSES[$name] ?? [null, false, 0];

        return match (true) {
            $status >= 400                                              => ['$ref' => '#/components/schemas/Problem'],
            $status === 202 && in_array($name, self::MAY_PROPOSE, true) => self::dataOf('ChangeRequest'),
            $status === $success && $schema !== null                    => $list ? self::listOf($schema) : self::dataOf($schema),
            default                                                     => null,
        };
    }

    /** @return array<string, mixed> the document the schemas' `$ref`s resolve in */
    public static function document(): array
    {
        return ['components' => ['schemas' => self::schemas()]];
    }

    /** @return array<string, mixed> */
    public static function schemas(): array
    {
        $string = ['type' => 'string'];
        $nullableString = ['type' => ['string', 'null']];
        $nullableInteger = ['type' => ['integer', 'null']];
        $boolean = ['type' => 'boolean'];
        $strings = ['type' => 'array', 'items' => $string];
        $object = static fn (array $properties, array $required): array => ['type' => 'object', 'required' => $required, 'properties' => $properties];

        return [
            'Problem' => $object([
                'type'     => $string,
                'title'    => $string,
                'status'   => ['type' => 'integer'],
                'detail'   => $string,
                'instance' => $nullableString,
                'errors'   => ['type' => 'object'],
            ], ['type', 'title', 'status', 'detail']),
            'Setting' => $object([
                'store'    => $string,
                'scope'    => $string,
                'key'      => $string,
                'locale'   => $string,
                'found'    => $boolean,
                'layer'    => $nullableString,
                'from'     => $nullableString,
                'version'  => ['type' => 'integer'],
                'redacted' => $boolean,
                'value'    => [],
            ], ['store', 'scope', 'key', 'locale', 'found', 'version', 'redacted']),
            'Store' => $object([
                'name'      => $string,
                'mode'      => ['type' => 'string', 'enum' => ['polymorphic', 'dedicated']],
                'owners'    => $strings,
                'layers'    => $strings,
                'strict'    => $boolean,
                'inherits'  => $boolean,
                'fallback'  => $nullableString,
                'approvals' => $strings,
            ], ['name', 'mode', 'owners', 'layers', 'strict', 'inherits']),
            'Definition' => $object([
                'store'                 => $string,
                'key'                   => $string,
                'group'                 => $string,
                'category'              => $nullableString,
                'type'                  => $nullableString,
                'has_default'           => $boolean,
                'default'               => [],
                'rules'                 => $strings,
                'encrypted'             => $boolean,
                'sensitive'             => $boolean,
                'public'                => $boolean,
                'locked'                => $boolean,
                'localised'             => $boolean,
                'inherits'              => ['type' => ['boolean', 'null']],
                'overridable_by'        => $strings,
                'precedence'            => $string,
                'label'                 => $nullableString,
                'description'           => $nullableString,
                'requires_verification' => $boolean,
                'requires_approval'     => $boolean,
            ], ['store', 'key', 'group', 'has_default', 'encrypted', 'sensitive']),
            'Explanation' => $object([
                'found'    => $boolean,
                'layer'    => $nullableString,
                'from'     => $nullableString,
                'redacted' => $boolean,
                'steps'    => $strings,
                'value'    => [],
            ], ['found', 'redacted', 'steps']),
            'HistoryEntry' => $object([
                'id'            => $string,
                'store'         => $string,
                'setting_ref'   => $nullableString,
                'scope'         => $string,
                'key'           => $string,
                'locale'        => $string,
                'operation'     => $string,
                'old_value'     => $nullableString,
                'new_value'     => $nullableString,
                'old_type'      => $nullableString,
                'new_type'      => $nullableString,
                'old_encrypted' => $boolean,
                'new_encrypted' => $boolean,
                'version'       => ['type' => 'integer'],
                'causer_type'   => $string,
                'causer_alias'  => $nullableString,
                'causer_id'     => $nullableString,
                'reason'        => $nullableString,
                'created_at'    => $string,
            ], ['id', 'store', 'scope', 'key', 'operation', 'version', 'created_at']),
            'AuditEntry' => $object([
                'id'           => $string,
                'occurred_at'  => $string,
                'action'       => $string,
                'outcome'      => ['type' => 'string', 'enum' => ['allowed', 'denied', 'pending', 'failed']],
                'store'        => $nullableString,
                'scope'        => $nullableString,
                'key'          => $nullableString,
                'locale'       => $nullableString,
                'version_from' => $nullableInteger,
                'version_to'   => $nullableInteger,
                'actor_type'   => $string,
                'actor_alias'  => $nullableString,
                'actor_id'     => $nullableString,
                'channel'      => $string,
            ], ['id', 'occurred_at', 'action', 'outcome', 'actor_type', 'channel']),
            'ChangeRequest' => $object([
                'id'              => $string,
                'store'           => $string,
                'scope'           => $string,
                'key'             => $string,
                'locale'          => $string,
                'operation'       => ['type' => 'string', 'enum' => ['set', 'forget', 'lock', 'unlock']],
                'encrypted'       => $boolean,
                'base_version'    => ['type' => 'integer'],
                'status'          => ['type' => 'string', 'enum' => ['pending', 'approved', 'applied', 'rejected', 'cancelled', 'stale', 'expired']],
                'requested_by'    => ['type' => 'object'],
                'approvals'       => $strings,
                'decided_by'      => ['type' => ['object', 'null']],
                'decided_at'      => $nullableString,
                'reason'          => $nullableString,
                'decision_reason' => $nullableString,
                'expires_at'      => $nullableString,
                'created_at'      => $nullableString,
            ], ['id', 'store', 'scope', 'key', 'operation', 'status', 'base_version']),
            'Category' => $object([
                'slug'        => $string,
                'store'       => $string,
                'title'       => $string,
                'titles'      => ['type' => 'object'],
                'parent'      => $nullableString,
                'description' => $nullableString,
                'icon'        => $nullableString,
                'sort_order'  => ['type' => 'integer'],
                'system'      => $boolean,
            ], ['slug', 'store', 'title', 'sort_order', 'system']),
            'ConfigurationEntry' => $object([
                'kind'   => ['type' => 'string', 'enum' => ['store', 'layer']],
                'name'   => $string,
                'config' => ['type' => 'object'],
            ], ['kind', 'name', 'config']),
            'ExportDocument' => $object([
                'format'     => ['type' => 'string', 'const' => 'laranail/settings'],
                'version'    => ['type' => 'integer'],
                'store'      => $string,
                'mode'       => ['type' => 'string', 'enum' => ['redacted', 'ciphertext', 'plaintext']],
                'categories' => ['type' => 'array', 'items' => ['type' => 'object']],
                'settings'   => ['type' => 'array', 'items' => ['type' => 'object']],
            ], ['format', 'version', 'store', 'mode', 'categories', 'settings']),
            'ImportReport' => $object([
                'applied'    => $boolean,
                'created'    => $strings,
                'updated'    => $strings,
                'unchanged'  => $strings,
                'skipped'    => ['type' => ['object', 'array']],
                'categories' => $strings,
                'proposed'   => $strings,
            ], ['applied', 'created', 'updated', 'unchanged', 'skipped']),
            'StoreDiff' => $object([
                'added'     => $strings,
                'removed'   => $strings,
                'changed'   => $strings,
                'unchanged' => $strings,
                'unknown'   => $strings,
            ], ['added', 'removed', 'changed', 'unchanged', 'unknown']),
        ];
    }

    /** @return array<string, mixed> */
    private static function dataOf(string $schema): array
    {
        return ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['$ref' => '#/components/schemas/' . $schema]]];
    }

    /** @return array<string, mixed> */
    private static function listOf(string $schema): array
    {
        return [
            'type'       => 'object',
            'required'   => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $schema]],
                'meta' => ['type' => 'object', 'required' => ['next_cursor'], 'properties' => ['next_cursor' => ['type' => ['string', 'null']]]],
            ],
        ];
    }
}
