<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\Locale;
use Simtabi\Laranail\Settings\Data\Record;
use Simtabi\Laranail\Settings\Data\OwnerRef;
use Simtabi\Laranail\Settings\Data\RecordSet;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\CacheTarget;
use Simtabi\Laranail\Settings\Support\BagCodec;

it('round-trips every field of a record, and an empty bag', function (): void {
    $codec = new BagCodec;
    $record = new Record(
        scope: Scope::forOwner(new OwnerRef('user', '42')),
        key: new Key('ui.theme'),
        locale: new Locale('fr'),
        payload: '"sombre"',
        type: 'string',
        encrypted: true,
        locked: true,
        version: 7,
        writtenAt: CarbonImmutable::parse('2026-11-10T08:00:00+00:00'),
        group: 'ui',
        category: 'appearance',
        public: true,
    );
    $plain = new Record(Scope::global(), new Key('site.name'), Locale::none(), null);

    $decoded = $codec->decode($codec->encode(new RecordSet([$record, $plain])));

    expect($decoded)->toBeInstanceOf(RecordSet::class)
        ->and($decoded?->all())->toEqual([$record, $plain])
        ->and($codec->encode(new RecordSet))->toBe(['v' => BagCodec::VERSION, 'r' => []])
        ->and($codec->decode($codec->encode(new RecordSet))?->count())->toBe(0);
});

it('reads anything it did not write as no entry, never as an empty bag', function (mixed $entry): void {
    expect((new BagCodec)->decode($entry))->toBeNull();
})->with([
    'null'              => [null],
    'a string'          => ['bag'],
    'no version'        => [['r' => []]],
    'another version'   => [['v' => 2, 'r' => []]],
    'no records'        => [['v' => 1]],
    'a scalar row'      => [['v' => 1, 'r' => ['row']]],
    'a bad scope'       => [['v' => 1, 'r' => [['s' => 'Not A Scope', 'k' => 'a', 'l' => '', 'p' => null, 't' => null, 'e' => false, 'x' => false, 'n' => 1, 'w' => null, 'g' => null, 'c' => null, 'u' => false]]]],
    'a bad key'         => [['v' => 1, 'r' => [['s' => 'global', 'k' => '', 'l' => '', 'p' => null, 't' => null, 'e' => false, 'x' => false, 'n' => 1, 'w' => null, 'g' => null, 'c' => null, 'u' => false]]]],
    'a string version'  => [['v' => 1, 'r' => [['s' => 'global', 'k' => 'a', 'l' => '', 'p' => null, 't' => null, 'e' => false, 'x' => false, 'n' => '1', 'w' => null, 'g' => null, 'c' => null, 'u' => false]]]],
    'a numeric payload' => [['v' => 1, 'r' => [['s' => 'global', 'k' => 'a', 'l' => '', 'p' => 1, 't' => null, 'e' => false, 'x' => false, 'n' => 1, 'w' => null, 'g' => null, 'c' => null, 'u' => false]]]],
    'a numeric date'    => [['v' => 1, 'r' => [['s' => 'global', 'k' => 'a', 'l' => '', 'p' => null, 't' => null, 'e' => false, 'x' => false, 'n' => 1, 'w' => 5, 'g' => null, 'c' => null, 'u' => false]]]],
    'a bad date'        => [['v' => 1, 'r' => [['s' => 'global', 'k' => 'a', 'l' => '', 'p' => null, 't' => null, 'e' => false, 'x' => false, 'n' => 1, 'w' => 'not a date', 'g' => null, 'c' => null, 'u' => false]]]],
    'a missing type'    => [['v' => 1, 'r' => [['s' => 'global', 'k' => 'a', 'l' => '', 'p' => null, 'e' => false, 'x' => false, 'n' => 1, 'w' => null, 'g' => null, 'c' => null, 'u' => false]]]],
    'a numeric group'   => [['v' => 1, 'r' => [['s' => 'global', 'k' => 'a', 'l' => '', 'p' => null, 't' => null, 'e' => false, 'x' => false, 'n' => 1, 'w' => null, 'g' => 1, 'c' => null, 'u' => false]]]],
    'a string flag'     => [['v' => 1, 'r' => [['s' => 'global', 'k' => 'a', 'l' => '', 'p' => null, 't' => null, 'e' => 'yes', 'x' => false, 'n' => 1, 'w' => null, 'g' => null, 'c' => null, 'u' => false]]]],
    'a missing flag'    => [['v' => 1, 'r' => [['s' => 'global', 'k' => 'a', 'l' => '', 'p' => null, 't' => null, 'e' => false, 'x' => false, 'n' => 1, 'w' => null, 'g' => null, 'c' => null]]]],
    'a numeric locale'  => [['v' => 1, 'r' => [['s' => 'global', 'k' => 'a', 'l' => 1, 'p' => null, 't' => null, 'e' => false, 'x' => false, 'n' => 1, 'w' => null, 'g' => null, 'c' => null, 'u' => false]]]],
]);

it('lists a cache target\'s parts in key order', function (): void {
    expect(new CacheTarget('mysql', 'app', new StoreName('settings'), 'settings')->parts())->toBe(['mysql', 'app', 'settings', 'settings']);
});
