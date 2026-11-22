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

test('the links and evo filters go through the DI cms, as markup, each off for an empty name', function (): void {
    $di = new Di();
    $di->setShared('cms', fn () => new class {
        public function links(string $html): string
        {
            return str_replace('[~2~]', '/about', $html);
        }

        public function parse(string $html): string
        {
            return str_replace('{{year}}', '2026', $html);
        }
    });

    $extension = new PhalconExtension(static fn () => $di);
    expect(array_keys($extension->getFilters()))->toBe(['links', 'evo']);

    $html = $extension->links('<a href="[~2~]">About</a>');
    expect($html)->toBeInstanceOf(Latte\Runtime\Html::class);
    expect((string) $html)->toBe('<a href="/about">About</a>');
    expect((string) $extension->parse('<p>{{year}}</p>'))->toBe('<p>2026</p>');

    expect(array_keys((new PhalconExtension(static fn () => $di, 'phalcon', '', 'cms'))->getFilters()))->toBe(['cms']);
    expect((new PhalconExtension(static fn () => $di, 'phalcon', '', ''))->getFilters())->toBe([]);
});
