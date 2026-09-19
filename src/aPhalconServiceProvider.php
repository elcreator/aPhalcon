<?php

namespace Elcreator\aPhalcon;

use Elcreator\aLatteX\LattexEngine;
use Elcreator\aPhalcon\Console\DemoInstallCommand;
use Elcreator\aPhalcon\Console\DemoRemoveCommand;
use Elcreator\aPhalcon\Http\Bridge;
use Elcreator\aPhalcon\Latte\PhalconExtension;
use EvolutionCMS\ServiceProvider;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Route;
use Phalcon\Di\DiInterface;

class aPhalconServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__) . '/config/aphalcon.php', 'aphalcon');

        // One container for the site, built on first use from the config and
        // the CMS's own connection.
        $this->app->singleton(DiInterface::class, function ($app) {
            $factory = new DiFactory((array) $app['config']->get('aphalcon', []), $this->connection());

            return $factory->make([
                'cms' => fn () => $this->cms(),
                'evo' => fn () => $this->core(),
                'tablePrefix' => fn () => DbConfig::prefix($this->connection()),
            ]);
        });
        $this->app->alias(DiInterface::class, 'phalcon.di');

        $this->app->singleton(Bridge::class, function ($app) {
            return new Bridge($app->make(DiInterface::class), (array) $app['config']->get('aphalcon', []));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            dirname(__DIR__) . '/config/aphalcon.php' => EVO_CORE_PATH . 'custom/config/aphalcon.php',
        ], 'config');

        $this->registerRoutes();
        $this->registerLatteFunction();
        $this->registerCommands();
    }

    /**
     * Mount the Phalcon app under routes.prefix. Registered from boot(), after
     * the core's parser fallback - which does not matter, because a fallback
     * route is matched last whatever the order of registration.
     */
    private function registerRoutes(): void
    {
        $routes = (array) config('aphalcon.routes', []);
        $prefix = trim((string) ($routes['prefix'] ?? ''), '/');

        if ($prefix === '' || !$this->app->bound('router')) {
            return;
        }

        if (method_exists($this->app, 'isFrontend') && !$this->app->isFrontend() && !$this->app->runningInConsole()) {
            return;
        }

        $middleware = (array) ($routes['middleware'] ?? ['web']);

        Route::middleware($middleware)
            ->any($prefix . '/{aphalcon_path?}', static function (string $aphalcon_path = '') {
                return app(Bridge::class)->handle($aphalcon_path);
            })
            ->where('aphalcon_path', '.*')
            ->name('aphalcon');
    }

    /**
     * Give aLatteX the {phalcon()} function. The engine is resolved lazily by
     * aLatteX and must have its extensions before it compiles anything, so the
     * hook rides on resolution rather than on provider order.
     */
    private function registerLatteFunction(): void
    {
        $name = (string) config('aphalcon.latte.function', 'phalcon');

        if ($name === '' || !class_exists(LattexEngine::class) || !method_exists(LattexEngine::class, 'addExtension')) {
            return;
        }

        $app = $this->app;
        $attach = static function (LattexEngine $engine) use ($app, $name): void {
            $engine->addExtension(new PhalconExtension(static fn () => $app->make(DiInterface::class), $name));
        };

        if ($this->app->resolved(LattexEngine::class)) {
            $attach($this->app->make(LattexEngine::class));

            return;
        }

        $this->app->afterResolving(LattexEngine::class, $attach);
    }

    private function registerCommands(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            DemoInstallCommand::class,
            DemoRemoveCommand::class,
        ]);
    }

    /** @return array<string, mixed> the Laravel connection the DI's 'db' is opened from */
    private function connection(): array
    {
        $config = $this->app['config'];
        $name = (string) ($config->get('aphalcon.db.connection') ?: $config->get('database.default', 'default'));

        return (array) $config->get('database.connections.' . $name, []);
    }

    private function cms(): Cms
    {
        $app = $this->app;

        return new Cms(
            static function (string $alias, array $data) use ($app): string {
                /** @var ViewFactory $views */
                $views = $app->make('view');

                return $views->make($alias, $data)->render();
            },
            static function (string $alias) use ($app): bool {
                /** @var ViewFactory $views */
                $views = $app->make('view');

                return $views->exists($alias);
            },
            fn (): object => $this->core(),
        );
    }

    /**
     * The core with its settings loaded. A Laravel route runs before the
     * parser, which is what normally reads system_settings, so a Phalcon
     * handler asking for site_url or a document URL would otherwise see an
     * empty config.
     */
    private function core(): object
    {
        $core = evo();

        if (empty($core->config) && method_exists($core, 'getSettings')) {
            $core->getSettings();
        }

        return $core;
    }
}
