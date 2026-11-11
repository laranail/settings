<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Data\PromotionReport;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\TransactionBeginning;
use Simtabi\Laranail\Settings\Http\Support\Problems;
use Simtabi\Laranail\Settings\Exceptions\AccessDenied;
use Simtabi\Laranail\Settings\Tests\Fixtures\TestUser;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Simtabi\Laranail\Settings\Tests\Support\ApiSchemas;
use Simtabi\Laranail\Settings\Tests\Support\JsonSchema;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Tests\Fixtures\SettingsOwner;
use Simtabi\Laranail\Settings\Contracts\TenantDiscriminator;
use Simtabi\Laranail\Settings\Services\Values\ValuePipeline;
use Simtabi\Laranail\Settings\Exceptions\InvalidSettingValue;
use Simtabi\Laranail\Settings\Exceptions\InvalidConfiguration;
use Simtabi\Laranail\Settings\Http\Middleware\IdempotentWrites;
use Simtabi\Laranail\Settings\Tenancy\ClosureTenantDiscriminator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Simtabi\Laranail\Settings\Services\Definitions\DefinitionRegistry;

/*
 * The REST API runs the actions the PHP API runs, answers problem details, ETags and
 * If-Match, 202 for approvals, cursor pages, idempotent retries, and every response it gives
 * matches its shape (tests/Support/ApiSchemas.php).
 */

beforeEach(function (): void {
    $this->rebootWith([
        'laranail.settings.http.enabled'    => true,
        'laranail.settings.http.middleware' => ['api', 'auth'],
    ]);
    useDatabase(['stores.settings.owners' => ['user'], 'definitions.sources' => ['code', 'config', 'database'], 'configuration.persisted' => true, 'verification.driver' => 'none']);
    Relation::morphMap(['user' => TestUser::class]);
    allowGates(...Ability::cases());
    $this->actingAs(user(1));
});

/** Call the API and check the response against its shape. */
function api(string $method, string $uri, array $data = [], array $headers = []): TestResponse
{
    $response = test()->json($method, '/api/settings/v1/' . $uri, $data, $headers);
    $route = $response->baseRequest->route();

    $status = $response->baseResponse->getStatusCode();

    if (is_object($route) && $status !== 204) {
        $schema = ApiSchemas::response((string) $route->getName(), $status);

        expect($schema)->not->toBeNull(sprintf('%s %s answered %d, which no response shape describes', $method, $uri, $status));

        // An export is streamed.
        $content = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : (string) $response->getContent();
        $errors = new JsonSchema(ApiSchemas::document())->errors(json_decode($content, true), $schema);
        expect($errors)->toBe([], sprintf('%s %s does not match its schema', $method, $uri));
    }

    return $response;
}

it('registers no route unless the API is enabled', function (): void {
    $this->rebootWith([]);

    expect(collect(app(Router::class)->getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'laranail-settings.api.')))->toHaveCount(0);
});

it('reads and writes a setting with ETags and If-Match', function (): void {
    $put = api('PUT', 'stores/settings/settings/site.name', ['value' => 'Acme'])->assertOk()->assertJsonPath('data.value', 'Acme')->assertJsonPath('data.version', 1);
    $etag = $put->headers->get('ETag');

    api('GET', 'stores/settings/settings/site.name')->assertOk()->assertHeader('ETag', $etag)->assertJsonPath('data.layer', 'database');
    api('PUT', 'stores/settings/settings/site.name', ['value' => 'Acme Ltd'], ['If-Match' => $etag])->assertOk()->assertJsonPath('data.version', 2);
    api('PUT', 'stores/settings/settings/site.name', ['value' => 'Stale'], ['If-Match' => $etag])
        ->assertStatus(412)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://opensource.simtabi.com/documentation/laranail/settings/tools/exceptions#precondition-failed');

    expect(settings()->get('site.name'))->toBe('Acme Ltd')
        ->and(DB::table('settings_audit')->where('channel', 'rest')->where('action', 'update')->count())->toBe(2);
});

it('forgets on If-Match only at that version, even when the setting changes after the check', function (): void {
    $etag = api('PUT', 'stores/settings/settings/site.name', ['value' => 'Acme'])->headers->get('ETag');

    api('DELETE', 'stores/settings/settings/site.name', [], ['If-Match' => '"stale"'])->assertStatus(412);

    // Another writer lands between the If-Match check and the delete, as the write's transaction opens.
    $moved = false;
    Event::listen(TransactionBeginning::class, static function () use (&$moved): void {
        if (! $moved) {
            $moved = true;
            DB::table('settings')->where('key', 'site.name')->update(['value' => json_encode('Moved on'), 'version' => 2]);
        }
    });

    api('DELETE', 'stores/settings/settings/site.name', [], ['If-Match' => $etag])->assertStatus(412)->assertHeader('Content-Type', 'application/problem+json');

    // The simulated write ran inside the refused transaction and rolled back with it; the row is not deleted.
    expect(DB::table('settings')->where('key', 'site.name')->count())->toBe(1);
});

it('lists, explains, locks, unlocks and forgets, for the global value and an owner', function (): void {
    settings()->set('site.name', 'Acme');
    settings()->set('theme', 'light');

    api('GET', 'stores/settings/settings')->assertOk()->assertJsonCount(2, 'data');
    api('GET', 'stores/settings/settings?limit=1')->assertOk()->assertJsonPath('meta.next_cursor', 'site.name');
    api('PUT', 'stores/settings/owners/user/1/settings/theme', ['value' => 'dark'])->assertOk()->assertJsonPath('data.scope', 'user:1');
    api('GET', 'stores/settings/settings/theme/explain')->assertOk()->assertJsonPath('data.found', true);
    api('POST', 'stores/settings/owners/user/1/settings/theme/lock')->assertOk();
    api('PUT', 'stores/settings/owners/user/1/settings/theme', ['value' => 'blue'])->assertStatus(423);
    api('DELETE', 'stores/settings/owners/user/1/settings/theme/lock')->assertOk();
    api('DELETE', 'stores/settings/owners/user/1/settings/theme')->assertNoContent();
    api('GET', 'stores/settings/owners/user/9/settings/theme')->assertOk()->assertJsonPath('data.found', false)->assertJsonPath('data.version', 0);
    api('GET', 'stores/settings/owners/nope/1/settings/theme')->assertNotFound();

    expect(settings()->for(new OwnerRef('user', 1))->has('theme'))->toBeFalse();
});

it('omits what the actor may not view, and refuses what they may not do', function (): void {
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
    settings()->set('mail.password', TestCredentials::secret());
    settings()->set('site.name', 'Acme');
    Gate::define(Ability::ViewSensitive->gateName(), static fn (): bool => false);
    Gate::define(Ability::Update->gateName(), static fn (): bool => false);

    $list = api('GET', 'stores/settings/settings')->assertOk();

    expect(collect($list->json('data'))->pluck('key')->all())->toBe(['site.name']);
    api('GET', 'stores/settings/settings/mail.password')->assertOk()->assertJsonPath('data.redacted', true)->assertJsonMissingPath('data.value');
    api('PUT', 'stores/settings/settings/site.name', ['value' => 'x'])->assertForbidden()->assertJsonPath('status', 403);
    api('PUT', 'stores/settings/settings/site.name', [])->assertUnprocessable()->assertJsonStructure(['errors' => ['value']]);
    api('GET', 'stores/nope/settings/site.name')->assertNotFound();
});

it('answers 202 with a change request, and approves it through the API', function (): void {
    app(DefinitionRegistry::class)->define('mail.host')->requiresApproval()->register();

    $id = api('PUT', 'stores/settings/settings/mail.host', ['value' => 'smtp.test'])->assertStatus(202)->json('data.id');

    api('GET', 'stores/settings/changes')->assertOk()->assertJsonPath('data.0.id', $id);
    api('GET', 'stores/settings/changes/' . $id)->assertOk()->assertJsonPath('data.status', 'pending');
    api('POST', 'stores/settings/changes/' . $id . '/approve')->assertForbidden();

    $this->actingAs(user(2));
    api('POST', 'stores/settings/changes/' . $id . '/approve', ['reason' => 'ok'])->assertOk()->assertJsonPath('data.status', 'applied');

    $proposed = api('POST', 'stores/settings/changes', ['key' => 'mail.host', 'value' => 'other.test'])->assertStatus(202)->json('data.id');
    api('POST', 'stores/settings/changes/' . $proposed . '/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    $again = api('POST', 'stores/settings/changes', ['key' => 'mail.host', 'value' => 'x.test', 'owner' => ['alias' => 'user', 'id' => '1']])->json('data.id');
    $this->actingAs(user(1));
    api('POST', 'stores/settings/changes/' . $again . '/reject', ['reason' => 'no'])->assertOk()->assertJsonPath('data.status', 'rejected');
    api('POST', 'stores/settings/changes/' . $again . '/reject')->assertStatus(409);
    api('GET', 'stores/settings/changes/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();

    expect(settings()->get('mail.host'))->toBe('smtp.test');
});

it('pages history and rolls back', function (): void {
    settings()->set('site.name', 'One');
    settings()->set('site.name', 'Two');

    api('GET', 'stores/settings/settings/site.name/history?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.version', 2);
    api('POST', 'stores/settings/settings/site.name/rollback', ['version' => 1])->assertOk()->assertJsonPath('data.value', 'One')->assertJsonPath('data.version', 3);
    api('POST', 'stores/settings/settings/site.name/rollback', ['version' => 9])->assertStatus(409);
    api('GET', 'audit?action=rollback')->assertOk()->assertJsonPath('data.0.action', 'rollback');
});

it('manages categories, persisted configuration and definitions', function (): void {
    api('POST', 'stores/settings/categories', ['slug' => 'mail', 'title' => 'Mail'])->assertCreated()->assertJsonPath('data.slug', 'mail');
    api('POST', 'stores/settings/categories', ['slug' => 'smtp', 'title' => 'SMTP'])->assertCreated();
    api('PATCH', 'stores/settings/categories/smtp', ['title' => 'Outgoing', 'parent' => 'mail'])->assertOk()->assertJsonPath('data.parent', 'mail')->assertJsonPath('data.title', 'Outgoing');
    api('GET', 'stores/settings/categories')->assertOk()->assertJsonCount(2, 'data');
    api('DELETE', 'stores/settings/categories/smtp')->assertNoContent();

    api('PUT', 'configuration/stores/extra', ['config' => ['layers' => ['runtime']]])->assertOk()->assertJsonPath('data.kind', 'store');
    api('PUT', 'configuration/layers/scratch', ['config' => ['driver' => 'array']])->assertOk();
    api('GET', 'configuration')->assertOk()->assertJsonCount(2, 'data');
    api('GET', 'stores')->assertOk()->assertJsonCount(2, 'data');
    api('PUT', 'configuration/stores/broken', ['config' => ['layers' => ['missing']]])->assertUnprocessable();
    api('DELETE', 'configuration/stores/extra')->assertNoContent();
    api('DELETE', 'configuration/layers/scratch')->assertNoContent();
    api('GET', 'configuration')->assertOk()->assertJsonCount(0, 'data');

    api('PUT', 'stores/settings/definitions/site.theme', ['attributes' => ['type' => 'string', 'default' => 'light']])->assertOk()->assertJsonPath('data.default', 'light');
    api('GET', 'stores/settings/definitions')->assertOk()->assertJsonPath('data.0.key', 'site.theme');
    api('GET', 'stores/settings/settings/site.theme')->assertOk()->assertJsonPath('data.value', 'light')->assertJsonPath('data.layer', 'default');
    api('DELETE', 'stores/settings/definitions/site.theme')->assertNoContent();
});

it('exports, compares and imports', function (): void {
    settings()->categories()->create(new NewCategory('mail', 'Mail'));
    settings()->set('site.name', 'Acme');

    $document = api('POST', 'stores/settings/export')->assertOk()->json('data');
    settings()->set('site.name', 'Changed');

    api('POST', 'stores/settings/diff', ['document' => $document])->assertOk()->assertJsonPath('data.changed', ['global|site.name|']);
    api('POST', 'stores/settings/import', ['document' => $document])->assertOk()->assertJsonPath('data.applied', false);
    expect(settings()->get('site.name'))->toBe('Changed');
    api('POST', 'stores/settings/import', ['document' => $document, 'apply' => true])->assertOk()->assertJsonPath('data.updated', ['global|site.name|']);
    api('POST', 'stores/settings/import', ['document' => ['format' => 'other']])->assertUnprocessable()->assertJsonPath('type', 'https://opensource.simtabi.com/documentation/laranail/settings/tools/exceptions#invalid-import');

    expect(settings()->get('site.name'))->toBe('Acme');
});

it('answers a retried write from the idempotency cache instead of applying it twice', function (): void {
    $first = api('PUT', 'stores/settings/settings/site.name', ['value' => 'Acme'], ['Idempotency-Key' => 'abc'])->assertOk();
    settings()->set('site.name', 'Moved on');
    $retry = api('PUT', 'stores/settings/settings/site.name', ['value' => 'Acme'], ['Idempotency-Key' => 'abc'])->assertOk();

    expect($retry->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($retry->getContent())->toBe($first->getContent())
        ->and(settings()->get('site.name'))->toBe('Moved on');
});

it('refuses an idempotency key reused with a different body, and applies nothing', function (): void {
    api('PUT', 'stores/settings/settings/site.name', ['value' => 'Acme'], ['Idempotency-Key' => 'reused'])->assertOk();

    test()->json('PUT', '/api/settings/v1/stores/settings/settings/site.name', ['value' => 'Other'], ['Idempotency-Key' => 'reused'])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(settings()->get('site.name'))->toBe('Acme');
});

it('keys a replay by the resolved actor, not only by the session user', function (): void {
    auth()->logout();
    $this->rebootWith(['laranail.settings.http.enabled' => true, 'laranail.settings.http.middleware' => ['api']]);
    useDatabase(['verification.driver' => 'none']);
    allowGates(...Ability::cases());
    // An application that finds its actor from a header rather than a Laravel guard.
    settings()->configuration()->resolveActorsUsing(static fn ($request): ?Actor => is_numeric($request?->header('X-User'))
        ? Actor::user(user((int) $request->header('X-User')))
        : null);

    test()->json('PUT', '/api/settings/v1/stores/settings/settings/site.name', ['value' => 'Acme'], ['Idempotency-Key' => 'shared', 'X-User' => '1'])->assertOk();
    settings()->set('site.name', 'Moved on');
    $other = test()->json('PUT', '/api/settings/v1/stores/settings/settings/site.name', ['value' => 'Acme'], ['Idempotency-Key' => 'shared', 'X-User' => '2'])->assertOk();

    expect($other->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and($other->json('data.version'))->toBe(3);
});

it('keeps a stored idempotent response encrypted, since it can hold a secret', function (): void {
    $directory = sys_get_temp_dir() . '/laranail-settings-idempotency-' . bin2hex(random_bytes(6));
    config()->set('cache.stores.idempotency', ['driver' => 'file', 'path' => $directory]);
    config()->set('laranail.settings.http.idempotency.cache', 'idempotency');
    app(DefinitionRegistry::class)->define('mail.password')->encrypted()->register();
    $secret = TestCredentials::secret();

    api('PUT', 'stores/settings/settings/mail.password', ['value' => $secret], ['Idempotency-Key' => 'secret'])->assertOk()->assertJsonPath('data.value', $secret);
    $replay = api('PUT', 'stores/settings/settings/mail.password', ['value' => $secret], ['Idempotency-Key' => 'secret'])->assertOk();

    $files = array_filter(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS))), static fn (SplFileInfo $file): bool => $file->isFile());

    expect($replay->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($replay->json('data.value'))->toBe($secret)
        ->and($files)->toHaveCount(1)
        ->and((string) file_get_contents((string) array_key_first($files)))->not->toContain($secret);
});

it('answers a retry sent while the first request still runs with 409, and writes once (SP-04)', function (): void {
    $runs = 0;
    $inner = null;
    $request = static function (): Request {
        $request = Request::create('/api/settings/v1/stores/settings/settings/site.name', 'PUT', content: '{"value":"Acme"}');
        $request->headers->set('Idempotency-Key', 'in-flight');

        return $request;
    };
    $middleware = app(IdempotentWrites::class);

    // The retry arrives while the first request is still inside the action.
    $middleware->handle($request(), function () use ($middleware, $request, &$runs, &$inner): Response {
        $runs++;

        try {
            $middleware->handle($request(), static function () use (&$runs): Response {
                $runs++;

                return new Response('second', 200);
            });
        } catch (ConflictHttpException $conflict) {
            $inner = $conflict->getStatusCode();
        }

        return new Response('first', 200);
    });

    $replay = $middleware->handle($request(), static fn (): Response => new Response('third', 200));

    expect($runs)->toBe(1)
        ->and($inner)->toBe(409)
        ->and($replay->getContent())->toBe('first')
        ->and($replay->headers->get('Idempotent-Replayed'))->toBe('true');
});

it('replays a response stored while it waited for the key, rather than writing again (SP-04)', function (): void {
    $runs = 0;
    $request = static function (): Request {
        $request = Request::create('/api/settings/v1/stores/settings/settings/site.name', 'PUT', content: '{"value":"Acme"}');
        $request->headers->set('Idempotency-Key', 'finished-meanwhile');

        return $request;
    };
    $middleware = app(IdempotentWrites::class);
    $raced = false;

    // The first request finishes between this one's cache read and its taking the lock.
    Event::listen(CacheMissed::class, static function () use ($middleware, $request, &$runs, &$raced): void {
        if (! $raced) {
            $raced = true;
            $middleware->handle($request(), static function () use (&$runs): Response {
                $runs++;

                return new Response('first', 200);
            });
        }
    });

    $response = $middleware->handle($request(), static function () use (&$runs): Response {
        $runs++;

        return new Response('second', 200);
    });

    expect($raced)->toBeTrue()
        ->and($runs)->toBe(1)
        ->and($response->getContent())->toBe('first')
        ->and($response->headers->get('Idempotent-Replayed'))->toBe('true');
});

it('keys a replay by the tenant, so the same user id in two tenants never shares a response (SP-08)', function (): void {
    useDatabase(['stores.settings.owners' => ['user'], 'tenancy.mode' => 'column', 'verification.driver' => 'none']);
    $tenant = (object) ['id' => 'acme'];
    app()->forgetScopedInstances();
    app()->instance(TenantDiscriminator::class, new ClosureTenantDiscriminator(static fn (): ?string => $tenant->id));
    allowGates(...Ability::cases());
    $secret = TestCredentials::secret();

    api('PUT', 'stores/settings/settings/site.name', ['value' => $secret], ['Idempotency-Key' => 'same-key'])->assertOk();

    $tenant->id = 'globex';
    $other = test()->json('PUT', '/api/settings/v1/stores/settings/settings/site.name', ['value' => $secret], ['Idempotency-Key' => 'same-key'])->assertOk();

    expect($other->headers->get('Idempotent-Replayed'))->toBeNull()
        ->and($other->json('data.version'))->toBe(1);

    $tenant->id = 'acme';
    $replay = test()->json('PUT', '/api/settings/v1/stores/settings/settings/site.name', ['value' => $secret], ['Idempotency-Key' => 'same-key'])->assertOk();

    expect($replay->headers->get('Idempotent-Replayed'))->toBe('true');
});

it('answers a refused export with a problem, not a half-written stream (SP-10)', function (): void {
    Gate::define(Ability::Export->gateName(), static fn (): bool => false);

    api('POST', 'stores/settings/export')->assertForbidden()->assertJsonPath('status', 403);
});

it('never runs a REST request as the system actor, whatever the resolver returns (SP-02)', function (): void {
    settings()->configuration()->resolveActorsUsing(static fn (): Actor => Actor::system('service account'));

    foreach (Ability::cases() as $ability) {
        Gate::define($ability->gateName(), static fn (): bool => false);
    }

    test()->json('PUT', '/api/settings/v1/stores/settings/settings/site.name', ['value' => 'Acme'])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://opensource.simtabi.com/documentation/laranail/settings/tools/exceptions#actor-required');
    test()->json('GET', '/api/settings/v1/stores/settings/settings')->assertUnauthorized();

    expect(settings()->get('site.name'))->toBeNull();
});

it('keeps a value stored encrypted secret after its definition stops saying so (SP-01)', function (): void {
    $secret = TestCredentials::secret();
    api('PUT', 'stores/settings/definitions/mail.password', ['attributes' => ['type' => 'string', 'encrypted' => true]])->assertOk();
    settings()->set('mail.password', $secret);
    Gate::define(Ability::ViewSensitive->gateName(), static fn (): bool => false);

    // manage_definitions alone: redefine the key as plain, then delete the definition.
    api('PUT', 'stores/settings/definitions/mail.password', ['attributes' => ['type' => 'string']])->assertOk();
    api('GET', 'stores/settings/settings/mail.password')->assertOk()->assertJsonPath('data.redacted', true)->assertJsonMissingPath('data.value');
    api('DELETE', 'stores/settings/definitions/mail.password')->assertNoContent();

    $show = api('GET', 'stores/settings/settings/mail.password')->assertOk()->assertJsonPath('data.redacted', true)->assertJsonMissingPath('data.value');
    $list = api('GET', 'stores/settings/settings')->assertOk();
    $explain = api('GET', 'stores/settings/settings/mail.password/explain')->assertOk();

    expect($show->getContent())->not->toContain($secret)
        ->and($list->getContent())->not->toContain($secret)
        ->and($explain->getContent())->not->toContain($secret)
        ->and(fn (): mixed => settings()->as(user(1))->get('mail.password'))->toThrow(AccessDenied::class, 'view_sensitive')
        ->and(settings()->as(user(1))->all())->not->toHaveKey('mail.password')
        // Trusted code and an actor allowed view_sensitive still read it.
        ->and(settings()->get('mail.password'))->toBe($secret);

    Gate::define(Ability::ViewSensitive->gateName(), static fn (): bool => true);

    expect(settings()->as(user(1))->get('mail.password'))->toBe($secret);
});

it('applies values.max_bytes to REST writes, imports and promotion alike', function (): void {
    config()->set('laranail.settings.values.max_bytes', 64);
    app()->forgetInstance(ValuePipeline::class);
    app()->forgetScopedInstances();
    $big = str_repeat('x', 100);

    api('PUT', 'stores/settings/settings/site.name', ['value' => $big])->assertUnprocessable()
        ->assertJsonPath('type', 'https://opensource.simtabi.com/documentation/laranail/settings/tools/exceptions#invalid-value');

    $document = ['format' => 'laranail/settings', 'version' => 1, 'store' => 'settings', 'mode' => 'redacted', 'exported_at' => now()->toIso8601String(), 'categories' => [],
        'settings'        => [['scope' => 'global', 'key' => 'site.name', 'locale' => '', 'value' => $big, 'encrypted' => false]]];
    api('POST', 'stores/settings/import', ['document' => $document, 'apply' => true])->assertUnprocessable()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'over the 64-byte limit'));

    Storage::disk('local')->put('laranail/settings/settings.json', (string) json_encode(['records' => [
        ['scope' => 'global', 'key' => 'site.tagline', 'locale' => '', 'payload' => json_encode($big), 'version' => 1],
    ]]));

    expect(fn (): PromotionReport => settings()->asSystem()->promote())->toThrow(InvalidSettingValue::class)
        ->and(DB::table('settings')->count())->toBe(0);
});

it('refuses a malformed cursor instead of serving the first page', function (string $uri): void {
    settings()->set('site.name', 'Acme');

    api('GET', $uri . '?cursor=' . rawurlencode('not a cursor!'))->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonStructure(['errors' => ['cursor']]);
})->with([
    'settings' => ['stores/settings/settings'],
    'owner'    => ['stores/settings/owners/user/1/settings'],
    'history'  => ['stores/settings/settings/site.name/history'],
    'changes'  => ['stores/settings/changes'],
    'audit'    => ['audit'],
]);

it('accepts the cursors its listings hand out', function (): void {
    settings()->set('site.name', 'Acme');
    settings()->set('site.name', 'Acme Ltd');
    settings()->set('theme', 'dark');

    $settings = api('GET', 'stores/settings/settings?limit=1')->json('meta.next_cursor');
    $history = api('GET', 'stores/settings/settings/site.name/history?limit=1')->json('meta.next_cursor');
    $audit = api('GET', 'audit?limit=1')->json('meta.next_cursor');

    api('GET', 'stores/settings/settings?limit=1&cursor=' . rawurlencode((string) $settings))->assertOk()->assertJsonPath('data.0.key', 'theme');
    api('GET', 'stores/settings/settings/site.name/history?limit=1&cursor=' . rawurlencode((string) $history))->assertOk()->assertJsonCount(1, 'data');
    api('GET', 'audit?limit=1&cursor=' . rawurlencode((string) $audit))->assertOk()->assertJsonCount(1, 'data');
});

it('needs an authenticated user and switches route groups off', function (): void {
    auth()->logout();
    test()->json('GET', '/api/settings/v1/stores')->assertUnauthorized()->assertJsonPath('status', 401);

    $this->rebootWith(['laranail.settings.http.enabled' => true, 'laranail.settings.http.middleware' => ['api'], 'laranail.settings.http.routes' => ['approvals' => false]]);

    expect(app(Router::class)->getRoutes()->getByName('laranail-settings.api.changes.index'))->toBeNull()
        ->and(app(Router::class)->getRoutes()->getByName('laranail-settings.api.settings.show'))->not->toBeNull();

    test()->json('GET', '/api/settings/v1/stores')->assertUnauthorized()->assertJsonPath('type', 'https://opensource.simtabi.com/documentation/laranail/settings/tools/exceptions#actor-required');
});

it('has a response shape for every registered route, and none for a route that is gone', function (): void {
    $names = collect(app(Router::class)->getRoutes()->getRoutes())
        ->map(static fn ($route): string => (string) $route->getName())
        ->filter(static fn (string $name): bool => str_starts_with($name, 'laranail-settings.api.'))
        ->map(static fn (string $name): string => (string) preg_replace('/^laranail-settings\.api\.(owners\.)?/', '', $name))
        ->unique()->sort()->values()->all();
    $shapes = ApiSchemas::routes();
    sort($shapes);

    expect($names)->toHaveCount(30)
        ->and($shapes)->toBe($names);
});

it('loads a Settable owner, so its parents take part in reads, and refuses one that does not exist', function (): void {
    useDatabase(['stores.settings.owners' => ['owner', 'team'], 'stores.settings.inherits' => true, 'verification.driver' => 'none']);
    Schema::create('owners', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('team_id')->nullable();
    });
    DB::table('owners')->insert(['id' => 5, 'team_id' => 3]);
    Relation::morphMap(['owner' => SettingsOwner::class]);
    settings()->for(new OwnerRef('team', 3))->set('theme', 'teal');

    api('GET', 'stores/settings/owners/owner/5/settings/theme')->assertOk()->assertJsonPath('data.value', 'teal')->assertJsonPath('data.from', 'team:3');
    api('GET', 'stores/settings/owners/owner/6/settings/theme')->assertNotFound()->assertJsonPath('type', 'https://opensource.simtabi.com/documentation/laranail/settings/tools/exceptions#unknown-owner');
    api('GET', 'stores/settings/owners/owner/bad%20id/settings/theme')->assertNotFound();

    // Mapped only for URLs: addressed by alias and id, so no parents.
    config()->set('laranail.settings.http.owners', ['member' => SettingsOwner::class]);
    config()->set('laranail.settings.stores.settings.owners', ['owner', 'team', 'member']);
    app()->forgetScopedInstances();
    api('GET', 'stores/settings/owners/member/5/settings/theme')->assertOk()->assertJsonPath('data.found', false);
});

it('pages listings by http.page_size, 50 by default and clamped to 1 to 200', function (): void {
    foreach (range(1, 51) as $n) {
        settings()->set(sprintf('page.key_%02d', $n), $n);
    }

    api('GET', 'stores/settings/settings')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.next_cursor', 'page.key_50');

    config()->set('laranail.settings.http.page_size', 20);
    api('GET', 'stores/settings/settings')->assertOk()->assertJsonCount(20, 'data');
    api('GET', 'stores/settings/settings?limit=3')->assertOk()->assertJsonCount(3, 'data');

    config()->set('laranail.settings.http.page_size', 0);
    api('GET', 'stores/settings/settings')->assertOk()->assertJsonCount(1, 'data');

    config()->set('laranail.settings.http.page_size', 1000);
    api('GET', 'stores/settings/settings')->assertOk()->assertJsonCount(51, 'data');
});

it('fails closed, with one warning, when the API is on and no authorizer can be built', function (): void {
    $log = sys_get_temp_dir() . '/laranail-settings-closed-' . bin2hex(random_bytes(4)) . '.log';
    config()->set('logging.channels.settings_closed', ['driver' => 'single', 'path' => $log]);
    config()->set('laranail.settings.logging.channel', 'settings_closed');
    config()->set('laranail.settings.authorization.driver', 'Nope\\Authorizer');
    app()->forgetScopedInstances();

    api('GET', 'stores')->assertUnauthorized()->assertJsonPath('type', Problems::typeUri('actor-required'));
    expect(fn (): mixed => settings()->as(user())->get('site.name'))->toThrow(AccessDenied::class)
        ->and(settings()->get('site.name'))->toBeNull()
        ->and(substr_count((string) file_get_contents($log), 'has no usable authorizer'))->toBe(1);

    // Without the API, a broken authorizer still raises the configuration error.
    config()->set('laranail.settings.http.enabled', false);
    app()->forgetScopedInstances();

    expect(fn (): mixed => settings()->as(user())->get('site.name'))->toThrow(InvalidConfiguration::class, 'laranail.settings.authorization.driver');
});
