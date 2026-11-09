<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Data;

use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;

/**
 * An owner of settings by morph alias and id, never by class name, so renaming a model class
 * does not orphan its settings and resolving parents needs no model loading.
 */
final readonly class OwnerRef
{
    public string $alias;

    public string $id;

    public function __construct(string $alias, int|string $id)
    {
        if ($alias === Scope::GLOBAL || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $alias) !== 1) {
            throw new InvalidIdentifier(sprintf('Invalid owner alias [%s]: use [a-z][a-z0-9_]*, at most 64 characters, not "global".', $alias));
        }

        $id = (string) $id;

        if ($id === '' || strlen($id) > 36 || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            throw new InvalidIdentifier(sprintf('Invalid owner id for [%s]: use 1-36 characters of [A-Za-z0-9_-].', $alias));
        }

        $this->alias = $alias;
        $this->id = $id;
    }

    /**
     * Build an ordered list of owners from alias => id pairs, dropping pairs whose id is null.
     *
     * @param array<string, int|string|null> $pairs
     *
     * @return list<self>
     */
    public static function list(array $pairs): array
    {
        $owners = [];

        foreach ($pairs as $alias => $id) {
            if ($id !== null) {
                $owners[] = new self($alias, $id);
            }
        }

        return $owners;
    }

    public function scope(): Scope
    {
        return Scope::forOwner($this);
    }
}
