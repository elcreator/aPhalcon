<?php

namespace Elcreator\aPhalcon\Http;

use Phalcon\Di\DiInterface;
use Phalcon\Http\Response;
use Phalcon\Http\ResponseInterface;
use Phalcon\Mvc\Application;
use Phalcon\Mvc\Micro;
use Phalcon\Mvc\Micro\Collection;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The Phalcon application behind the CMS route, and the one call that runs it.
 *
 * Builds the app the 'app' and 'routes.handlers' keys describe, once, and
 * turns a path into a Laravel response. The app sees the same request PHP
 * received - Phalcon's Request reads the superglobals - and only the path is
 * handed over, relative to the mount point, so a Micro route written as '/x'
 * answers /<prefix>/x.
 */
final class Bridge
{
    private Micro|Application|null $app = null;

    /**
     * @param array<string, mixed> $config the aphalcon config
     */
    public function __construct(
        private DiInterface $di,
        private array $config,
        private ResponseConverter $converter = new ResponseConverter(),
    ) {
    }

    public function app(): Micro|Application
    {
        return $this->app ??= $this->build();
    }

    /**
     * @param string $path the request path below the mount point, with or without a leading slash
     */
    public function handle(string $path): SymfonyResponse
    {
        $uri = '/' . ltrim($path, '/');
        $app = $this->app();

        ob_start();
        try {
            $returned = $app->handle($uri);
        } finally {
            $echoed = (string) ob_get_clean();
        }

        // Application::handle() answers false when a dispatcher event stopped it.
        if ($returned === false) {
            $returned = null;
        }

        return $this->converter->convert($returned, $echoed, $this->sharedResponse());
    }

    private function build(): Micro|Application
    {
        $spec = $this->config['app'] ?? null;

        if ($spec === null || $spec === '') {
            $app = new Micro($this->di);
        } elseif ($spec instanceof Micro || $spec instanceof Application) {
            $app = $spec;
        } elseif (is_string($spec) && class_exists($spec)) {
            $app = new $spec($this->di);
        } elseif (is_callable($spec)) {
            $app = $spec($this->di);
        } else {
            throw new \InvalidArgumentException('aphalcon.app must be null, a class name or a callable');
        }

        if (!$app instanceof Micro && !$app instanceof Application) {
            throw new \InvalidArgumentException(
                'aphalcon.app must produce a Phalcon\Mvc\Micro or a Phalcon\Mvc\Application'
            );
        }

        if ($app instanceof Micro) {
            // Take the router now. Micro clears the DI router's default
            // /:controller/:action routes the first time it is asked for, and
            // an app given no routes would otherwise match those and fail
            // for want of a handler instead of answering 404.
            $app->getRouter();

            // A 404 of its own, so an unmatched path is a response and not an
            // exception; a handler that calls notFound() replaces it.
            $app->notFound(function (): Response {
                $response = new Response();
                $response->setStatusCode(404);
                $response->setContent('Not Found');

                return $response;
            });

            foreach ((array) ($this->config['routes']['handlers'] ?? []) as $handler) {
                $this->applyHandler($app, $handler);
            }
        }

        return $app;
    }

    private function applyHandler(Micro $app, mixed $handler): void
    {
        if (is_string($handler) && class_exists($handler)) {
            $handler = new $handler();
        }

        if ($handler instanceof Collection) {
            $app->mount($handler);

            return;
        }

        if (is_callable($handler)) {
            $handler($app, $this->di);

            return;
        }

        throw new \InvalidArgumentException(
            'aphalcon.routes.handlers entries must be Phalcon\Mvc\Micro\Collection classes or callables'
        );
    }

    /**
     * The DI's 'response', when something has been set on it. Read only if the
     * service was resolved during the request: resolving it here would create
     * an empty one and prove nothing.
     */
    private function sharedResponse(): ?ResponseInterface
    {
        if (!$this->di->has('response')) {
            return null;
        }

        $service = $this->di->getService('response');
        if (!$service->isResolved()) {
            return null;
        }

        $response = $this->di->getShared('response');

        return $response instanceof ResponseInterface ? $response : null;
    }
}
