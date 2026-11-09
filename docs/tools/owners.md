# Owners and inheritance

A setting can belong to the application (global) or to an owner identified by morph alias and id.

## Declaring an owner

```php
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Contracts\Settable;

class User extends Model implements Settable
{
    public function settingsOwner(): OwnerRef
    {
        return new OwnerRef('user', $this->id);
    }

    public function settingsParents(): array
    {
        return OwnerRef::list(['team' => $this->team_id, 'tenant' => $this->tenant_id]);
    }
}
```

Parents are alias and id pairs, nearest first, so resolving them loads no models. A pair whose id is null is dropped.

## Stores per owner

Each store lists the aliases it holds in `owners`, and each alias belongs to one store. `for($owner)` without `store()` routes to that store; an alias no store lists raises `OwnerNotAllowed`.

## Inheritance

Off by default. When a definition says `inherits()`, or the store sets `inherits => true` for keys without a definition, a read for an owner tries:

1. the owner's own value;
2. each parent's value, in the parent's own store;
3. the store's global value, then its lower layers;
4. the definition default.

Without inheritance, an owner with no value gets the definition default, never another owner's or the global value, so a platform secret cannot leak into a tenant that never set one. Absence moves on; a stored `null` is an answer.

`explain($key)` lists every step tried and the one that answered.

---

[← Docs index](../../README.md#documentation)
