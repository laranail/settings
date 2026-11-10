<?php

declare(strict_types=1);

use Psr\Log\AbstractLogger;
use Illuminate\Support\Facades\DB;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Locale;
use Illuminate\Database\Events\QueryExecuted;
use Simtabi\Laranail\Settings\Data\SettingWrite;
use Simtabi\Laranail\Settings\Data\SettingTarget;
use Simtabi\Laranail\Settings\Actions\SettingActions;
use Simtabi\Laranail\Settings\Services\Stores\SettingWriter;
use Simtabi\Laranail\Settings\Services\Stores\StoreRegistry;
use Simtabi\Laranail\Settings\Exceptions\ConcurrentModification;

/**
 * Make another writer change the row between this writer's read and its write: right after the
 * listed reads of the settings table during the next write (counting from 1), bump the row's
 * version. Each write attempt reads twice (the lock check, then the row it continues), so read 2
 * is the first attempt's and read 4 the retry's.
 *
 * @param list<int> $afterReads
 */
function interveneAfterReads(array $afterReads): void
{
    $reads = 0;
    $busy = false;

    DB::listen(static function (QueryExecuted $query) use ($afterReads, &$reads, &$busy): void {
        if ($busy || ! str_starts_with(strtolower($query->sql), 'select') || ! preg_match('/from [`"]settings[`"]/', $query->sql)) {
            return;
        }

        if (! in_array(++$reads, $afterReads, true)) {
            return;
        }

        $busy = true;
        $version = (int) DB::table('settings')->where('key', 'site.name')->value('version');
        DB::table('settings')->where('key', 'site.name')->update(['value' => '"Theirs"', 'version' => $version + 1]);
        $busy = false;
    });
}

function target(): SettingTarget
{
    $store = app(StoreRegistry::class)->default();

    return new SettingTarget($store, new Key('site.name'), Locale::none());
}

beforeEach(function (): void {
    useDatabase();
    settings()->set('site.name', 'Ours');
});

it('retries a plain set once after a concurrent change, and logs it', function (): void {
    $logger = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $messages = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }
    };
    settings()->configuration()->logTo($logger);
    interveneAfterReads([2]);

    settings()->set('site.name', 'Mine');

    expect(settings()->get('site.name'))->toBe('Mine')
        ->and((int) DB::table('settings')->value('version'))->toBe(3)
        ->and(array_filter($logger->messages, static fn (string $message): bool => str_contains($message, 'retried a write of [site.name]')))->toHaveCount(1);
});

it('gives up after one retry, rolling the whole write back', function (): void {
    interveneAfterReads([2, 4]);

    expect(fn () => settings()->set('site.name', 'Mine'))->toThrow(ConcurrentModification::class)
        ->and(settings()->get('site.name'))->toBe('Ours');
});

it('does not retry when writes.retry_on_conflict is off', function (): void {
    config()->set('laranail.settings.writes.retry_on_conflict', false);
    app()->forgetInstance(SettingWriter::class);
    app()->forgetScopedInstances();
    interveneAfterReads([2]);

    expect(fn () => settings()->set('site.name', 'Mine'))->toThrow(ConcurrentModification::class);
});

it('checks an explicit expected version and never retries it', function (): void {
    $set = app(SettingActions::class)->set;

    expect(fn () => $set->handle(new SettingWrite(target(), 'Stale', expectedVersion: 7)))->toThrow(ConcurrentModification::class, 'not the expected 7');

    $set->handle(new SettingWrite(target(), 'Mine', expectedVersion: 1));
    interveneAfterReads([2]);

    expect(settings()->get('site.name'))->toBe('Mine')
        ->and(fn () => $set->handle(new SettingWrite(target(), 'Again', expectedVersion: 2)))->toThrow(ConcurrentModification::class);
});
