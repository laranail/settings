<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Typed;

use ReflectionClass;
use ReflectionProperty;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\Definition;
use Simtabi\Laranail\Settings\Contracts\Settings;
use Simtabi\Laranail\Settings\Typed\Attributes\Group;
use Simtabi\Laranail\Settings\Typed\Attributes\Store;
use Simtabi\Laranail\Settings\Exceptions\TypeMismatch;
use Simtabi\Laranail\Settings\Exceptions\InvalidDefinition;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/**
 * Typed settings classes over the key-value engine (Phase 5; the idea is spatie/laravel-settings'):
 * a class whose public typed properties map to keys, loaded and saved through a settings handle,
 * so the handle's store, owner, locale and actor apply, and every save goes through the same
 * write actions as `set()` (validation, encryption, locks, approvals, history, audit).
 *
 *     #[Group('mail')]
 *     final class MailSettings extends TypedSettings
 *     {
 *         public string $fromAddress = 'hello@example.com';
 *         #[Encrypted] public ?string $password = null;
 *     }
 *
 *     $mail = MailSettings::load($settings->as($user));
 *     $mail->fromAddress = 'ops@example.com';
 *     $mail->save();
 *
 * A property keeps its declared default until a value is stored for its key. `save()` writes only
 * the properties that changed since `load()`. `register()` turns the properties into definitions
 * (type, default, encryption), so the key-value API reads them the same way.
 */
abstract class TypedSettings
{
    private ?Settings $handle = null;

    /** @var array<string, mixed> stored form of each property as loaded */
    private array $loaded = [];

    final public function __construct() {}

    /**
     * Read every property's key through the handle (scoped to the class's store, when it names
     * one); a key with no value keeps the property's default.
     *
     * @throws TypeMismatch when a stored value cannot be the property's type
     */
    public static function load(Settings $settings): static
    {
        $instance = new static;
        $handle = self::scoped($settings);
        $instance->handle = $handle;

        foreach (static::properties() as $name => $property) {
            $lookup = $handle->lookup($property->key);

            if ($lookup->isFound()) {
                $property->property->setValue($instance, $property->fromStored($lookup->value()));
            }

            if ($property->property->isInitialized($instance)) {
                $instance->loaded[$name] = $property->toStored($property->property->getValue($instance));
            }
        }

        return $instance;
    }

    /**
     * Register a definition for every property: its key, the caster alias its type implies (or
     * `#[Type]`), its declared default and `#[Encrypted]`, in the class's store. A key already
     * defined in code or configuration is left as it is.
     *
     * @return list<Definition>
     */
    public static function register(DefinitionRegistry $registry): array
    {
        $definitions = [];
        $defaults = new ReflectionClass(static::class)->getDefaultProperties();
        $store = self::attribute(Store::class)?->name;

        foreach (static::properties() as $name => $property) {
            if ($registry->find($store === null ? $registry->defaultStore() : new StoreName($store), new Key($property->key)) instanceof Definition) {
                continue;
            }

            $builder = $registry->define($property->key);

            if ($store !== null) {
                $builder->inStore($store);
            }

            if ($property->casterAlias() !== null) {
                $builder->ofType((string) $property->casterAlias());
            }

            if (array_key_exists($name, $defaults) && $property->property->hasDefaultValue() && ($defaults[$name] !== null || $property->nullable)) {
                $builder->withDefault($property->toStored($defaults[$name]));
            }

            if ($property->encrypted) {
                $builder->encrypted();
            }

            $definitions[] = $builder->register();
        }

        return $definitions;
    }

    /**
     * Every mapped property, by name: the public, non-static properties the class declares.
     *
     * @return array<string, TypedProperty>
     *
     * @throws InvalidDefinition
     */
    public static function properties(): array
    {
        $group = self::attribute(Group::class)?->prefix;
        $properties = [];

        foreach (new ReflectionClass(static::class)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (! $property->isStatic() && $property->getDeclaringClass()->getName() !== self::class) {
                $properties[$property->getName()] = TypedProperty::of($property, $group);
            }
        }

        return $properties;
    }

    /**
     * Write every property that changed since `load()` through the handle it was loaded with; given
     * a handle, write every property through that one instead (copying settings to an owner).
     */
    public function save(?Settings $settings = null): void
    {
        $given = $settings instanceof Settings;
        $handle = $given ? self::scoped($settings) : ($this->handle ?? throw new TypeMismatch(static::class . ' was not loaded; pass a settings handle to save().'));

        foreach (static::properties() as $name => $property) {
            if (! $property->property->isInitialized($this)) {
                continue;
            }

            $stored = $property->toStored($property->property->getValue($this));

            if (! $given && array_key_exists($name, $this->loaded) && $this->loaded[$name] === $stored) {
                continue;
            }

            $handle->set($property->key, $stored);
            $this->loaded[$name] = $stored;
        }

        $this->handle = $handle;
    }

    /** The handle narrowed to the class's store, when it names one. */
    private static function scoped(Settings $settings): Settings
    {
        $store = self::attribute(Store::class)?->name;

        return $store === null ? $settings : $settings->store($store);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $attribute
     *
     * @return ?T
     */
    private static function attribute(string $attribute): ?object
    {
        $found = new ReflectionClass(static::class)->getAttributes($attribute)[0] ?? null;

        return $found?->newInstance();
    }
}
