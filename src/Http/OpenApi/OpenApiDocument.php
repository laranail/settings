<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\OpenApi;

use Illuminate\Routing\Route;
use Simtabi\Laranail\Settings\Http\ApiRoutes;
use Simtabi\Laranail\Settings\Enums\Capability;

/**
 * An OpenAPI 3.1 document for the REST API (§16.12), built from the routes actually registered
 * (so a group switched off in `http.routes` is absent), with one schema per resource. A test
 * validates real responses against it, so the document cannot drift from what the API returns.
 */
final readonly class OpenApiDocument
{
    /**
     * @param iterable<Route> $routes the application's routes; only the API's are read
     *
     * @return array<string, mixed>
     */
    public static function build(iterable $routes, string $version = '0.1.0'): array
    {
        $paths = [];

        foreach ($routes as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, ApiRoutes::NAME_PREFIX)) {
                continue;
            }

            $capability = Capability::from(preg_replace('/^owners\./', '', substr($name, strlen(ApiRoutes::NAME_PREFIX))) ?? '');

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths['/' . $route->uri()][strtolower($method)] = self::operation($name, $capability, $route);
            }
        }

        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info'    => [
                'title'       => 'laranail/settings',
                'version'     => $version,
                'description' => 'The settings REST API. Values the caller may not view are left out; errors are RFC 9457 problem details.',
            ],
            'paths'      => $paths,
            'components' => [
                'schemas'         => self::schemas(),
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
            ],
            'security' => [['bearer' => []]],
        ];
    }

    /**
     * The schema name a capability's success response holds, and whether it is a list.
     *
     * @return array{?string, bool, int}
     */
    public static function response(Capability $capability): array
    {
        return match ($capability) {
            Capability::StoresIndex      => ['Store', true, 200],
            Capability::DefinitionsIndex => ['Definition', true, 200],
            Capability::SettingsIndex    => ['Setting', true, 200],
            Capability::SettingsShow, Capability::SettingsUpdate, Capability::SettingsLock,
            Capability::SettingsUnlock, Capability::SettingsRollback => ['Setting', false, 200],
            Capability::SettingsDestroy, Capability::CategoriesDestroy, Capability::ConfigurationDestroy,
            Capability::DefinitionsDestroy                                                                            => [null, false, 204],
            Capability::SettingsExplain                                                                               => ['Explanation', false, 200],
            Capability::SettingsHistory                                                                               => ['HistoryEntry', true, 200],
            Capability::AuditIndex                                                                                    => ['AuditEntry', true, 200],
            Capability::ChangesIndex                                                                                  => ['ChangeRequest', true, 200],
            Capability::ChangesStore                                                                                  => ['ChangeRequest', false, 202],
            Capability::ChangesShow, Capability::ChangesApprove, Capability::ChangesReject, Capability::ChangesCancel => ['ChangeRequest', false, 200],
            Capability::CategoriesIndex                                                                               => ['Category', true, 200],
            Capability::CategoriesStore                                                                               => ['Category', false, 201],
            Capability::CategoriesUpdate                                                                              => ['Category', false, 200],
            Capability::ConfigurationIndex                                                                            => ['ConfigurationEntry', true, 200],
            Capability::ConfigurationUpdate                                                                           => ['ConfigurationEntry', false, 200],
            Capability::DefinitionsUpdate                                                                             => ['Definition', false, 200],
            Capability::TransferExport                                                                                => ['ExportDocument', false, 200],
            Capability::TransferImport                                                                                => ['ImportReport', false, 200],
            Capability::TransferDiff                                                                                  => ['StoreDiff', false, 200],
        };
    }

    /** @return array<string, mixed> */
    private static function operation(string $name, Capability $capability, Route $route): array
    {
        [$schema, $list, $status] = self::response($capability);
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[] = ['name' => $parameter, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
        }

        if (in_array($capability, [Capability::SettingsShow, Capability::SettingsIndex, Capability::SettingsExplain, Capability::SettingsHistory, Capability::SettingsUpdate], true)) {
            $parameters[] = ['name' => 'locale', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
        }

        if ($list) {
            $parameters[] = ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]];
            $parameters[] = ['name' => 'cursor', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
        }

        $problem = ['description' => 'A problem (RFC 9457).', 'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]];
        $responses = [(string) $status => $schema === null
            ? ['description' => 'Done.']
            : ['description' => 'OK.', 'content' => ['application/json' => ['schema' => $list ? self::listOf($schema) : self::dataOf($schema)]]]];

        if (in_array($capability, [Capability::SettingsUpdate, Capability::SettingsDestroy, Capability::SettingsLock, Capability::SettingsUnlock, Capability::SettingsRollback], true)) {
            $responses['202'] = ['description' => 'The change needs approval: a change request was opened.', 'content' => ['application/json' => ['schema' => self::dataOf('ChangeRequest')]]];
        }

        foreach (['401', '403', '404', '409', '412', '422', '423', '428', '429'] as $code) {
            $responses[$code] = $problem;
        }

        return array_filter([
            'operationId' => $name,
            'summary'     => $capability->label(),
            'tags'        => [$capability->group()],
            'parameters'  => $parameters,
            'requestBody' => in_array('GET', $route->methods(), true) ? null : ['required' => false, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            'responses'   => $responses,
        ], static fn (mixed $value): bool => $value !== null);
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

    /** @return array<string, mixed> */
    private static function schemas(): array
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
}
