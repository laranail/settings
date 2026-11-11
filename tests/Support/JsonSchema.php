<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Tests\Support;

use stdClass;
use LogicException;

/**
 * The subset of JSON Schema (2020-12) the REST API response schemas in `ApiSchemas`
 * use: `type` (one or a list, `null` included), `enum`, `const`, `required`, `properties`,
 * `items`, `minimum`, `maximum` and `$ref` into `#/components/schemas`. Small on purpose, and in
 * the repository, so validating responses needs no dependency. Unknown keywords are ignored.
 */
final readonly class JsonSchema
{
    /** @param array<string, mixed> $document the response schemas, keyed under `components.schemas` */
    public function __construct(private array $document) {}

    /**
     * Every way `$value` breaks `$schema`, as `path: problem`; empty when it conforms.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    public function errors(mixed $value, array $schema, string $path = '$'): array
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            return $this->errors($value, $this->resolve($schema['$ref']), $path);
        }

        $errors = [];

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];

            if (! array_any($types, fn (mixed $type): bool => is_string($type) && $this->is($value, $type))) {
                return [sprintf('%s: expected %s, got %s', $path, implode('|', $types), get_debug_type($value))];
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = sprintf('%s: %s is not one of %s', $path, json_encode($value), json_encode($schema['enum']));
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = sprintf('%s: expected %s', $path, json_encode($schema['const']));
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = sprintf('%s: below %s', $path, json_encode($schema['minimum']));
            }

            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = sprintf('%s: above %s', $path, json_encode($schema['maximum']));
            }
        }

        if (is_array($value) && ! array_is_list($value) || $value instanceof stdClass) {
            $object = (array) $value;

            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists((string) $required, $object)) {
                    $errors[] = sprintf('%s: missing %s', $path, $required);
                }
            }

            foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
                if (array_key_exists((string) $name, $object) && is_array($property)) {
                    $errors = [...$errors, ...$this->errors($object[$name], $property, $path . '.' . $name)];
                }
            }
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                $errors = [...$errors, ...$this->errors($item, $schema['items'], $path . '[' . $index . ']')];
            }
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    private function resolve(string $ref): array
    {
        $name = substr($ref, strlen('#/components/schemas/'));
        $schemas = $this->document['components']['schemas'] ?? [];

        return is_array($schemas) && is_array($schemas[$name] ?? null) ? $schemas[$name] : throw new LogicException('Unknown schema reference ' . $ref);
    }

    private function is(mixed $value, string $type): bool
    {
        return match ($type) {
            'null'    => $value === null,
            'string'  => is_string($value),
            'integer' => is_int($value),
            'number'  => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            // JSON decoded as arrays: an empty array is an empty object or an empty list.
            'array'  => is_array($value) && array_is_list($value),
            'object' => $value instanceof stdClass || (is_array($value) && ($value === [] || ! array_is_list($value))),
            default  => false,
        };
    }
}
