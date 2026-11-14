<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Owners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Contracts\Settable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Simtabi\Laranail\Settings\Exceptions\UnknownOwner;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;

/**
 * Resolves the owner a REST URL names from `http.owners` or the morph map. A {@see Settable}
 * model is loaded so its parents take part in reads.
 */
#[Scoped]
final readonly class MorphMapOwnerResolver
{
    /** @param array<array-key, mixed> $owners alias => class, for aliases outside the morph map */
    public function __construct(
        #[Config('laranail.settings.http.owners', [])]
        private array $owners = [],
    ) {}

    public function resolve(string $alias, string $id): Settable|OwnerRef
    {
        $class = is_string($this->owners[$alias] ?? null) ? $this->owners[$alias] : Relation::getMorphedModel($alias);

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

        // Parents count only when the alias is the model's own morph alias.
        return $model->getMorphClass() === $alias ? $model : $ref;
    }
}
