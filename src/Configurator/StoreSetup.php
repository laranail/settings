<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Configurator;

/** One store's `stores.<name>.*` keys, inside `SettingsSetup::store()`. */
final readonly class StoreSetup
{
    public function __construct(private SetupValues $values, private string $path) {}

    /** `layers`: the layers read in order; writes go to the first writable one. */
    public function layers(string ...$layers): self
    {
        foreach ($layers as $layer) {
            SetupValues::name('store()->layers()', $this->path.'.layers', $layer);
        }

        $this->values->set($this->path.'.layers', array_values($layers));

        return $this;
    }

    /** `owners`: the morph aliases this store holds values for. */
    public function owners(string ...$owners): self
    {
        foreach ($owners as $owner) {
            SetupValues::filled('store()->owners()', $this->path.'.owners', $owner);
        }

        $this->values->set($this->path.'.owners', array_values($owners));

        return $this;
    }

    /** `strict`: reject keys that have no definition. */
    public function strict(bool $strict = true): self
    {
        $this->values->set($this->path.'.strict', $strict);

        return $this;
    }

    /** `inherits`: owners fall back to their parents and the global value. */
    public function inherits(bool $inherits = true): self
    {
        $this->values->set($this->path.'.inherits', $inherits);

        return $this;
    }

    /** `bootstrap`: skip this store's database layer while the application installs. */
    public function bootstrap(bool $installing = true): self
    {
        $this->values->set($this->path.'.bootstrap', $installing);

        return $this;
    }

    /** `promote`: `manual` or `auto`. */
    public function promote(string $mode): self
    {
        $this->values->set($this->path.'.promote', SetupValues::oneOf('store()->promote()', $this->path.'.promote', $mode, ['manual', 'auto']));

        return $this;
    }

    /** `table`: the values table name (default: the store name). */
    public function table(string $table): self
    {
        $this->values->set($this->path.'.table', SetupValues::name('store()->table()', $this->path.'.table', $table));

        return $this;
    }

    /** `soft_deletes`: keep forgotten rows trashed and restore them on write. */
    public function softDeletes(bool $enabled = true): self
    {
        $this->values->set($this->path.'.soft_deletes', $enabled);

        return $this;
    }
}
