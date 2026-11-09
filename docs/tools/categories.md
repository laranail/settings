# Categories

Categories group a store's settings for people and interfaces; they nest, belong to one store, and are managed through `$settings->categories()`.

## Managing categories

```php
use Simtabi\Laranail\Settings\Data\NewCategory;

$categories = $settings->as($user)->categories();          // the default store
$categories = $settings->store('billing')->categories();   // another store

$categories->create(new NewCategory('mail', 'Mail', description: 'Outgoing mail', icon: 'envelope'));
$categories->create(new NewCategory('smtp', 'SMTP', parent: 'mail'));
$categories->rename('mail', 'Email');
$categories->move('smtp', null);                           // to the top level
$categories->delete('mail');

$categories->get('smtp');                                  // Category
$categories->all();                                        // list<Category>, by sort order
```

Slugs are `[a-z0-9]` words joined by hyphens, at most 128 characters, unique within a store.
Categories live in the database, so a store without an active database layer raises
`StoreNotWritable`.

## Rules

| Operation | Refused with |
|---|---|
| a parent, or a definition's category, that belongs to another store | `CategoryStoreMismatch` |
| moving a category under itself or a descendant | `CategoryCycle` |
| a slug the store does not have | `UnknownCategory` |

Deleting a category moves its children to the top level and leaves its settings in place, without
a category. A definition naming a category (`->inCategory('mail')`) links each row it writes to
that category of the same store; a slug no store has is left unlinked.

## Access and audit

Changes need `manage_categories`; reading needs `view`. Each change dispatches `CategorySaving` /
`CategorySaved` or `CategoryDeleting` / `CategoryDeleted` and is audited as `manage_categories`
with the operation and slug, in the same transaction as the change.

---

[← Docs index](../../README.md#documentation)
