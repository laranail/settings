# Override a setting for one request

Change what settings read for one request, one queued job or one block of code, without writing anything.

An override is held in memory and answers ahead of every stored value; it ends with the request or job. The full reference is [Runtime overrides](../tools/runtime-overrides.md).

## For one request, in middleware

A preview of a theme a user has not saved yet, passed as a signed query parameter:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Simtabi\Laranail\Settings\Contracts\Settings;

final readonly class PreviewTheme
{
    public function __construct(private Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $theme = $request->query('preview_theme');

        if ($request->hasValidSignature() && is_string($theme) && $request->user() !== null) {
            $this->settings->for($request->user())->override('ui.theme', $theme);
        }

        return $next($request);
    }
}
```

Everything that reads `ui.theme` for that user in this request (views, view composers, an API resource) sees the preview. Nothing is saved, the next request reads the stored theme, and Octane drops the override between requests. The key is named in code: never take the key from the request.

## For one queued job

A backfill that must not send mail while it runs, whatever the application's mail setting says:

```php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Simtabi\Laranail\Settings\Contracts\Settings;

final class BackfillInvoices implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tenantId) {}

    public function handle(Settings $settings, InvoiceBackfill $backfill): void
    {
        $settings->withOverrides(
            ['notifications.mail_enabled' => false, 'limits.batch_size' => 500],
            fn () => $backfill->run($this->tenantId),
        );
    }
}
```

The queue worker starts every job with no overrides, so set them inside `handle()`: a job does not inherit the overrides of the request that dispatched it. `withOverrides()` restores the previous state when the callback returns or throws, so a retry starts clean too.

## As a feature-flag style switch

Turn a feature on for staff for the request, while the stored value stays off for everyone else:

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Simtabi\Laranail\Settings\Contracts\Settings;

final readonly class StaffSeesBetaFeatures
{
    public function __construct(private Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_staff === true) {
            $this->settings->for($request->user())->override([
                'features.new_checkout' => true,
                'features.ai_summary'   => true,
            ]);
        }

        return $next($request);
    }
}
```

Code reads the flag as it always does, `$settings->for($user)->boolean('features.new_checkout')`, and cannot tell an override from a stored value. `explain()` can: its layer is `override`. Overriding needs no write ability and is not audited, so keep it for decisions your code makes; a flag a person turns on for someone belongs in `set()`, where it is authorized and audited.

## In a test

When one test needs a single value different, an override is lighter than the fake:

```php
use Simtabi\Laranail\Settings\Contracts\Settings;

it('hides the beta banner when the feature is off', function () {
    app(Settings::class)->override('features.beta', false);

    $this->get('/')->assertDontSee('Try the beta');
});
```

Use [`Settings::fake()`](testing-with-the-fake.md) instead when you need to assert on what the code wrote.

---

[← Docs index](../../README.md#documentation)
