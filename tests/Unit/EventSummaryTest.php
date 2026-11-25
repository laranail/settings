<?php

declare(strict_types=1);

use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Actor;
use Simtabi\Laranail\Settings\Data\Subject;
use Simtabi\Laranail\Settings\Enums\Ability;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Enums\ActorType;
use Simtabi\Laranail\Settings\Support\EventSummary;
use Simtabi\Laranail\Settings\Events\AccessWasDenied;

it('flattens an event to identifiers, and leaves out objects it does not know', function (): void {
    $event = new class
    {
        public string $store = 'settings';

        public int $version = 3;

        public object $payload;

        public function __construct()
        {
            $this->payload = new ArrayObject(['colour' => 'blue']);
        }
    };

    expect(EventSummary::of($event))->toBe(['store' => 'settings', 'version' => 3]);
});

it('names an actor by type, alias and id, and a system actor by its type', function (): void {
    $denied = new AccessWasDenied(Ability::Update, new Subject(new StoreName('settings'), new Key('site.name')), new Actor(ActorType::Token, '9', 'client'), 'access_denied');

    expect(EventSummary::of($denied))->toMatchArray(['ability' => 'update', 'key' => 'site.name', 'actor' => 'token:client:9', 'refusal' => 'access_denied'])
        ->and(EventSummary::actor(Actor::system()))->toBe('system')
        ->and(EventSummary::actor(new Actor(ActorType::Service, '4')))->toBe('service:?:4');
});
