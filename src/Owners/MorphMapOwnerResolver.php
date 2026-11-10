<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Owners;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Simtabi\Laranail\Settings\Contracts\OwnerResolver;
use Simtabi\Laranail\Settings\Exceptions\UnknownOwner;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;

/**
 * The default {@see OwnerResolver}: the alias must be in the morph map (or in `http.owners`). A
 * model that is {@see Settable} is loaded, so its parents take part in reads; a missing record is
 * UnknownOwner. Any other mapped class, or a model whose own morph alias differs (mapped only in
 * `http.owners`), is addressed by alias and id alone.
 */
final readonly class MorphMapOwnerResolver implements OwnerResolver
{
    /** @param Closure(string): ?string $morphed the class an alias maps to */
    public function __construct(
        private Closure $morphed,
    ) {}

    public function resolve(string $alias, string $id): Settable|OwnerRef
    {
        $class = ($this->morphed)($alias);

        if (! is_string($class) || ! class_exists($class)) {
            throw new UnknownOwner(sprintf('Owner alias [%s] is not in the morph map.', $alias));
        }

        try {
            $ref = new OwnerRef($alias, $id);
        } catch (SettingsException $exception) {
            throw new UnknownOwner($exception->getMessage(), 0, $exception);
        }

        if (! is_subclass_of($class, Settable::class) || ! is_subclass_of($class, Model::class)) {
            return $ref;
        }

        $model = $class::query()->find($id);

        if (! $model instanceof Settable || ! $model instanceof Model) {
            throw new UnknownOwner(sprintf('No [%s] owner with id [%s].', $alias, $id));
        }

        // Its parents count only when the alias is the model's own morph alias; otherwise the
        // owner is addressed by alias and id alone, as the URL named it.
        return $model->getMorphClass() === $alias ? $model : $ref;
    }
}
