<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Simtabi\Laranail\Settings\Tests\Fixtures\TestUser;
use Simtabi\Laranail\Settings\Tests\Support\ApiSchemas;
use Simtabi\Laranail\Settings\Tests\Support\JsonSchema;
use Simtabi\Laranail\Package\Tools\Testing\TestCredentials;
use Simtabi\Laranail\Settings\Tests\Fixtures\SettingsOwner;
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

    if (is_object($route) && $response->status() !== 204) {
        $schema = ApiSchemas::response((string) $route->getName(), $response->status());

        expect($schema)->not->toBeNull(sprintf('%s %s answered %d, which no response shape describes', $method, $uri, $response->status()));

        $errors = new JsonSchema(ApiSchemas::document())->errors(json_decode((string) $response->getContent(), true), $schema);
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
        ->assertJsonPath('type', 'https://opensource.simtabi.com/documentation/laranail/settings/problems/precondition-failed');

    expect(settings()->get('site.name'))->toBe('Acme Ltd')
        ->and(DB::table('settings_audit')->where('channel', 'rest')->where('action', 'update')->count())->toBe(2);
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
    api('POST', 'stores/settings/import', ['document' => ['format' => 'other']])->assertUnprocessable()->assertJsonPath('type', 'https://opensource.simtabi.com/documentation/laranail/settings/problems/invalid-import');

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

it('needs an authenticated user and switches route groups off', function (): void {
    auth()->logout();
    test()->json('GET', '/api/settings/v1/stores')->assertUnauthorized()->assertJsonPath('status', 401);

    $this->rebootWith(['laranail.settings.http.enabled' => true, 'laranail.settings.http.middleware' => ['api'], 'laranail.settings.http.routes' => ['approvals' => false]]);

    expect(app(Router::class)->getRoutes()->getByName('laranail-settings.api.changes.index'))->toBeNull()
        ->and(app(Router::class)->getRoutes()->getByName('laranail-settings.api.settings.show'))->not->toBeNull();

    test()->json('GET', '/api/settings/v1/stores')->assertUnauthorized()->assertJsonPath('type', 'https://opensource.simtabi.com/documentation/laranail/settings/problems/actor-required');
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
    api('GET', 'stores/settings/owners/owner/6/settings/theme')->assertNotFound()->assertJsonPath('type', 'https://opensource.simtabi.com/documentation/laranail/settings/problems/unknown-owner');
    api('GET', 'stores/settings/owners/owner/bad%20id/settings/theme')->assertNotFound();

    // Mapped only for URLs: addressed by alias and id, so no parents.
    config()->set('laranail.settings.http.owners', ['member' => SettingsOwner::class]);
    config()->set('laranail.settings.stores.settings.owners', ['owner', 'team', 'member']);
    app()->forgetScopedInstances();
    api('GET', 'stores/settings/owners/member/5/settings/theme')->assertOk()->assertJsonPath('data.found', false);
});
