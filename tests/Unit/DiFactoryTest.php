<?php

declare(strict_types=1);

use Elcreator\aPhalcon\DiFactory;
use Phalcon\Db\Adapter\Pdo\Sqlite;
use Phalcon\Di\Di;
use Phalcon\Di\DiInterface;
use Phalcon\Di\FactoryDefault;
use Phalcon\Di\ServiceProviderInterface;

$sqlite = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'evo_'];

test('the default container is a FactoryDefault with db on the CMS connection', function () use ($sqlite): void {
    $di = (new DiFactory([], $sqlite))->make();

    expect($di)->toBeInstanceOf(FactoryDefault::class);
    expect($di->has('db'))->toBeTrue();
    expect($di->getService('db')->isResolved())->toBeFalse();
    expect($di->get('db'))->toBeInstanceOf(Sqlite::class);
    expect($di->get('db'))->toBe($di->get('db'));
});

test('db is registered under the configured service name, or not at all', function () use ($sqlite): void {
    $named = (new DiFactory(['db' => ['service' => 'cmsDb']], $sqlite))->make();
    expect($named->has('cmsDb'))->toBeTrue();
    expect($named->has('db'))->toBeFalse();

    $none = (new DiFactory(['db' => ['service' => '']], $sqlite))->make();
    expect($none->has('db'))->toBeFalse();

    $noConnection = (new DiFactory([], []))->make();
    expect($noConnection->has('db'))->toBeFalse();
});

test('the base container can be a class name, a callable or an instance', function (): void {
    expect((new DiFactory(['di' => Di::class], []))->make())->toBeInstanceOf(Di::class);

    $made = new Di();
    expect((new DiFactory(['di' => static fn (): DiInterface => $made], []))->make())->toBe($made);
    expect((new DiFactory(['di' => $made], []))->make())->toBe($made);
});

test('a base that is neither is refused', function (): void {
    expect(static fn () => (new DiFactory(['di' => 42], []))->make())
        ->toThrow(InvalidArgumentException::class, 'aphalcon.di');
    expect(static fn () => (new DiFactory(['di' => static fn () => new stdClass()], []))->make())
        ->toThrow(InvalidArgumentException::class, 'DiInterface');
});

test('shared services are registered before the providers, which can use them', function () use ($sqlite): void {
    $seen = [];
    $provider = new class ($seen) implements ServiceProviderInterface {
        public function __construct(private array &$seen)
        {
        }

        public function register(DiInterface $di): void
        {
            $this->seen[] = $di->get('cms');
            $di->setShared('fromProvider', fn () => 'provided');
        }
    };

    $di = (new DiFactory(['providers' => [$provider]], $sqlite))->make([
        'cms' => static fn () => 'the cms',
        'evo' => 'the core',
    ]);

    expect($seen)->toBe(['the cms']);
    expect($di->get('evo'))->toBe('the core');
    expect($di->get('fromProvider'))->toBe('provided');
});

test('a provider entry can be a class name or a callable', function (): void {
    $di = (new DiFactory([
        'providers' => [
            DiFactoryTestProvider::class,
            static function (DiInterface $di): void {
                $di->setShared('second', fn () => 2);
            },
        ],
    ], []))->make();

    expect($di->get('first'))->toBe(1);
    expect($di->get('second'))->toBe(2);
});

test('a provider entry that is neither is refused', function (): void {
    expect(static fn () => (new DiFactory(['providers' => ['NoSuchClass']], []))->make())
        ->toThrow(InvalidArgumentException::class, 'aphalcon.providers');
});

final class DiFactoryTestProvider implements ServiceProviderInterface
{
    public function register(DiInterface $di): void
    {
        $di->setShared('first', fn () => 1);
    }
}
