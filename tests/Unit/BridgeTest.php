<?php

declare(strict_types=1);

use Elcreator\aPhalcon\Http\Bridge;
use Phalcon\Di\DiInterface;
use Phalcon\Di\FactoryDefault;
use Phalcon\Http\Response;
use Phalcon\Mvc\Micro;
use Phalcon\Mvc\Micro\Collection;

function bridgeWith(array $handlers, array $extra = []): Bridge
{
    return new Bridge(new FactoryDefault(), ['routes' => ['handlers' => $handlers]] + $extra);
}

test('with no app configured a Micro is built and the handlers applied in order', function (): void {
    $order = [];
    $bridge = bridgeWith([
        function (Micro $app, DiInterface $di) use (&$order): void {
            $order[] = 'first';
            $app->get('/hello/{name}', fn (string $name) => 'hello ' . $name);
        },
        function (Micro $app) use (&$order): void {
            $order[] = 'second';
        },
    ]);

    expect($bridge->app())->toBeInstanceOf(Micro::class);
    expect($bridge->app())->toBe($bridge->app());
    expect($order)->toBe(['first', 'second']);

    $response = $bridge->handle('hello/world');
    expect($response->getStatusCode())->toBe(200);
    expect($response->getContent())->toBe('hello world');
});

test('a Collection class or instance is mounted', function (): void {
    $bridge = bridgeWith([BridgeTestCollection::class, (new Collection())->setPrefix('/b')->setHandler(new BridgeTestHandler())->get('/', 'ping')]);

    expect($bridge->handle('/a/')->getContent())->toBe('pong');
    expect($bridge->handle('/b/')->getContent())->toBe('pong');
});

test('an unmatched path is a 404 unless a handler installs its own notFound', function (): void {
    $default = bridgeWith([]);
    expect($default->handle('/nowhere')->getStatusCode())->toBe(404);

    $own = bridgeWith([
        function (Micro $app): void {
            $app->notFound(fn () => 'custom missing');
        },
    ]);
    $response = $own->handle('/nowhere');
    expect($response->getContent())->toBe('custom missing');
});

test('a returned Phalcon response, an array and an echo each become the right Laravel response', function (): void {
    $bridge = bridgeWith([
        function (Micro $app): void {
            $app->get('/r', function (): Response {
                $r = new Response();
                $r->setStatusCode(418)->setContent('teapot');

                return $r;
            });
            $app->get('/j', fn () => ['ok' => true]);
            $app->get('/e', function (): void {
                echo 'printed';
            });
            $app->get('/s', function () use ($app): string {
                $app->response->setStatusCode(403);

                return 'no';
            });
        },
    ]);

    $r = $bridge->handle('/r');
    expect($r->getStatusCode())->toBe(418);
    expect($r->getContent())->toBe('teapot');

    $j = $bridge->handle('/j');
    expect($j->headers->get('Content-Type'))->toBe('application/json');
    expect($j->getContent())->toBe('{"ok":true}');

    expect($bridge->handle('/e')->getContent())->toBe('printed');

    $s = $bridge->handle('/s');
    expect($s->getStatusCode())->toBe(403);
    expect($s->getContent())->toBe('no');
});

test('the path is taken relative to the mount, with or without a leading slash', function (): void {
    $bridge = bridgeWith([
        function (Micro $app): void {
            $app->get('/', fn () => 'root');
            $app->get('/x', fn () => 'x');
        },
    ]);

    expect($bridge->handle('')->getContent())->toBe('root');
    expect($bridge->handle('/')->getContent())->toBe('root');
    expect($bridge->handle('x')->getContent())->toBe('x');
    expect($bridge->handle('/x')->getContent())->toBe('x');
});

test('the app can come from a callable, a class name or an instance', function (): void {
    $di = new FactoryDefault();

    $fromCallable = new Bridge($di, ['app' => function (DiInterface $di): Micro {
        $app = new Micro($di);
        $app->get('/', fn () => 'callable');

        return $app;
    }]);
    expect($fromCallable->handle('/')->getContent())->toBe('callable');

    $instance = new Micro($di);
    $instance->get('/', fn () => 'instance');
    expect((new Bridge($di, ['app' => $instance]))->handle('/')->getContent())->toBe('instance');

    expect((new Bridge($di, ['app' => Micro::class]))->app())->toBeInstanceOf(Micro::class);
});

test('an app that is neither Micro nor Application is refused', function (): void {
    expect(static fn () => (new Bridge(new FactoryDefault(), ['app' => static fn () => new stdClass()]))->app())
        ->toThrow(InvalidArgumentException::class, 'aphalcon.app');
    expect(static fn () => (new Bridge(new FactoryDefault(), ['app' => 3.14]))->app())
        ->toThrow(InvalidArgumentException::class, 'aphalcon.app');
    expect(static fn () => bridgeWith(['NoSuchHandler'])->app())
        ->toThrow(InvalidArgumentException::class, 'aphalcon.routes.handlers');
});

final class BridgeTestHandler
{
    public function ping(): string
    {
        return 'pong';
    }
}

final class BridgeTestCollection extends Collection
{
    public function __construct()
    {
        $this->setPrefix('/a');
        $this->setHandler(new BridgeTestHandler());
        $this->get('/', 'ping');
    }
}
