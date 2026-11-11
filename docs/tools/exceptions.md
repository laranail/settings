# Exceptions

Thirty exception classes in `Simtabi\Laranail\Settings\Exceptions`, all extending `SettingsException`, with when each is raised and how the REST API answers it.

## Catching them

```php
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Exceptions\LockedSetting;
use Simtabi\Laranail\Settings\Exceptions\SettingsException;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;

try {
    $settings->as($user)->set('mail.from.address', $input);
} catch (InvalidSettingValue $e) {
    return back()->withErrors(['value' => $e->getMessage()]);
} catch (AccessDenied|LockedSetting $e) {
    abort(403, $e->getMessage());
} catch (SettingsException $e) {
    report($e);
    throw $e;
}
```

Messages name the store, key, scope and ability involved, never a value, so they are safe to log and to show an administrator. A write that opens a change request does not throw; it returns normally (PHP) or answers `202` (REST).

## Reference

| Exception | Extends | Raised when | REST |
|---|---|---|---|
| `SettingsException` | `RuntimeException` | the base of every exception below; catch it to catch them all | `500 settings-error` for a subtype not listed |
| `AccessDenied` | `SettingsException` | an actor may not perform the ability on the subject; carries the ability, store and key | `403 access-denied` |
| `ActorRequired` | `SettingsException` | a call has no actor while `authorization.require_actor` is on, or `asCurrentActor()` finds nobody | `401 actor-required` |
| `VerificationRequired` | `SettingsException` | the actor is allowed but must verify again (step-up), for an ability in `verification.abilities` or a definition that `requiresVerification()` | `428 verification-required` |
| `ApprovalUnavailable` | `SettingsException` | a write needs approval but the store cannot hold change requests (no active database layer), or an authorizer answered that the ability needs approval | `409 approval-unavailable` |
| `ChangeRequestClosed` | `SettingsException` | a change request was already applied, rejected, cancelled, stale or expired | `409 change-request-closed` |
| `UnknownChangeRequest` | `SettingsException` | no change request with this id exists in the store (for the current tenant) | `404 unknown-change-request` |
| `ConcurrentModification` | `SettingsException` | another writer changed the row between read and update (the version check failed), or `If-Match` did not match; re-read and retry | `412 precondition-failed` |
| `LockedSetting` | `SettingsException` | the row is locked, or the definition's precedence forbids store writes | `423 locked` |
| `OwnerNotAllowed` | `SettingsException` | the owner's alias is not allowed in the store, or no store accepts it | `409 owner-not-allowed` |
| `UnknownOwner` | `SettingsException` | the REST owner resolver cannot find the alias or the record | `404 unknown-owner` |
| `StoreNotWritable` | `SettingsException` | no layer of the store accepts the write | `409 store-not-writable` |
| `UnknownStore` | `SettingsException` | a store that is not configured | `404 unknown-store` |
| `UnknownSetting` | `SettingsException` | a strict store was asked for a key with no definition | `404 unknown-setting` |
| `UnknownCategory` | `SettingsException` | no category with the slug exists in the store | `404 unknown-category` |
| `CategoryCycle` | `SettingsException` | moving a category under itself or a descendant | `422 category-cycle` |
| `CategoryStoreMismatch` | `SettingsException` | a category belongs to another store than what refers to it | `422 category-store-mismatch` |
| `InvalidSettingValue` | `SettingsException` | a value failed its definition's rules, cast or locale constraints | `422 invalid-value` |
| `TypeMismatch` | `SettingsException` | a typed getter (`string()`, `integer()`, …) or a typed settings property found another type, or no value | `422 type-mismatch` |
| `InvalidIdentifier` | `SettingsException` | a store name, locale, owner alias or filter failed validation | `422 invalid-identifier` |
| `InvalidKey` | `InvalidIdentifier` | a key is not lower-case dot notation of `[a-z0-9_]` segments, at most 191 characters; or `many()` got a non-string list entry | `422 invalid-identifier` |
| `InvalidDefinition` | `SettingsException` | a definition is inconsistent (encrypted and public), uses an unknown type, or a typed property has an unsupported type | `422 invalid-definition` |
| `InvalidConfiguration` | `SettingsException` | the package configuration is invalid; the message names the config path | `422 invalid-configuration` |
| `InvalidImport` | `SettingsException` | an export file or importer source is not a document this release can read | `422 invalid-import` |
| `HistoryUnavailable` | `SettingsException` | the store keeps no history, or lacks the version asked for | `409 history-unavailable` |
| `PromotionLocked` | `SettingsException` | another promotion of the same store holds the lock; retry when it finishes | `500 settings-error` (console and PHP only) |
| `EncryptionUnavailable` | `SettingsException` | an encrypted setting was written or read before an encryption key exists | `500 settings-error` |
| `UndecryptableValue` | `SettingsException` | an encrypted value cannot be decrypted with the current or previous keys (run `reencrypt` after a key rotation) | `500 settings-error` |
| `DriverUnavailable` | `SettingsException` | a required layer is unreachable (the database is down, its table missing) and no bootstrap evidence lets the store skip it; never a silent fall back to defaults | `503 unavailable` |
| `DriverFailure` | `SettingsException` | a storage layer failed; the underlying exception is the previous one | `500 settings-error` |

`LogicException` (PHP's own) is thrown for a programming error rather than a runtime condition: `flush()` without `for($owner)`.

Each exception names its HTTP status and problem type itself (`STATUS` and `TYPE` constants), and `Http\Support\Problems` renders them; anything without its own is a `500 settings-error`; [Problem types](#problem-types) below lists every problem type.

## Problem types

Each REST error is a problem document whose `type` is this page's URL with the problem type as
its fragment, for example
`https://opensource.simtabi.com/documentation/laranail/settings/tools/exceptions#locked`. Every
`http-<status>` type points at [`http-status`](#http-status).

### <a name="access-denied"></a>`access-denied`

Status 403. Raised by `AccessDenied`.

### <a name="actor-required"></a>`actor-required`

Status 401. Raised by `ActorRequired`.

### <a name="unauthenticated"></a>`unauthenticated`

Status 401. Raised by the host's authentication middleware.

### <a name="verification-required"></a>`verification-required`

Status 428. Raised by `VerificationRequired`: verify (confirm the password) and retry.

### <a name="unknown-store"></a>`unknown-store`

Status 404. Raised by `UnknownStore`.

### <a name="unknown-setting"></a>`unknown-setting`

Status 404. Raised by `UnknownSetting` (a strict store's undefined key).

### <a name="unknown-owner"></a>`unknown-owner`

Status 404. Raised by `UnknownOwner` (an alias or record the owner resolver cannot find).

### <a name="unknown-category"></a>`unknown-category`

Status 404. Raised by `UnknownCategory`.

### <a name="unknown-change-request"></a>`unknown-change-request`

Status 404. Raised by `UnknownChangeRequest`.

### <a name="invalid-value"></a>`invalid-value`

Status 422. Raised by `InvalidSettingValue`.

### <a name="invalid-identifier"></a>`invalid-identifier`

Status 422. Raised by `InvalidIdentifier`, `InvalidKey` (a key, store, locale, alias or filter).

### <a name="invalid-import"></a>`invalid-import`

Status 422. Raised by `InvalidImport`.

### <a name="invalid-definition"></a>`invalid-definition`

Status 422. Raised by `InvalidDefinition`.

### <a name="invalid-configuration"></a>`invalid-configuration`

Status 422. Raised by `InvalidConfiguration`.

### <a name="type-mismatch"></a>`type-mismatch`

Status 422. Raised by `TypeMismatch`.

### <a name="category-cycle"></a>`category-cycle`

Status 422. Raised by `CategoryCycle`.

### <a name="category-store-mismatch"></a>`category-store-mismatch`

Status 422. Raised by `CategoryStoreMismatch`.

### <a name="validation"></a>`validation`

Status 422. Raised by request validation; the body adds `errors`.

### <a name="locked"></a>`locked`

Status 423. Raised by `LockedSetting`.

### <a name="precondition-failed"></a>`precondition-failed`

Status 412. Raised by `ConcurrentModification` (`If-Match` did not match).

### <a name="approval-unavailable"></a>`approval-unavailable`

Status 409. Raised by `ApprovalUnavailable`.

### <a name="change-request-closed"></a>`change-request-closed`

Status 409. Raised by `ChangeRequestClosed`.

### <a name="owner-not-allowed"></a>`owner-not-allowed`

Status 409. Raised by `OwnerNotAllowed`.

### <a name="store-not-writable"></a>`store-not-writable`

Status 409. Raised by `StoreNotWritable`.

### <a name="history-unavailable"></a>`history-unavailable`

Status 409. Raised by `HistoryUnavailable`.

### <a name="too-many-requests"></a>`too-many-requests`

Status 429. Raised by the rate limit.

### <a name="unavailable"></a>`unavailable`

Status 503. Raised by `DriverUnavailable`.

### <a name="http-status"></a>`http-<status>`

Status that status. Raised by any other HTTP exception (`http-404` for an unknown route).

### <a name="settings-error"></a>`settings-error`

Status 500. Raised by any other package exception (its message is not exposed).

---

[← Docs index](../../README.md#documentation)
