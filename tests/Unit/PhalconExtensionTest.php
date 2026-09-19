<?php

declare(strict_types=1);

use Elcreator\aPhalcon\Latte\PhalconExtension;
use Phalcon\Di\Di;

test('the function is registered under the configured name, and not at all for an empty one', function (): void {
    $extension = new PhalconExtension(static fn () => new Di(), 'phalcon');
    expect(array_keys($extension->getFunctions()))->toBe(['phalcon']);

    $renamed = new PhalconExtension(static fn () => new Di(), 'di');
    expect(array_keys($renamed->getFunctions()))->toBe(['di']);

    expect((new PhalconExtension(static fn () => new Di(), ''))->getFunctions())->toBe([]);
});

test('resolve() answers a service by name, or the container with no name', function (): void {
    $di = new Di();
    $di->setShared('greeter', fn () => 'hello');

    $resolved = 0;
    $extension = new PhalconExtension(static function () use ($di, &$resolved) {
        $resolved++;

        return $di;
    });

    // Nothing is resolved until a template asks.
    expect($resolved)->toBe(0);
    expect($extension->resolve('greeter'))->toBe('hello');
    expect($extension->resolve())->toBe($di);
    expect($resolved)->toBe(2);
});
