<?php

namespace Elcreator\aPhalcon;

use Phalcon\Di\DiInterface;
use Phalcon\Di\FactoryDefault;
use Phalcon\Di\ServiceProviderInterface;

/**
 * Builds the one Phalcon container the site runs on, from the 'di', 'db' and
 * 'providers' keys of the aphalcon config.
 *
 * Order matters and is fixed: the base container, then 'db' from the CMS's
 * connection, then the shared services the provider hands in ('cms', 'evo',
 * 'tablePrefix'), then the site's own providers - so a site provider can
 * depend on all of them, and can also replace any of them.
 */
final class DiFactory
{
    /**
     * @param array<string, mixed> $config     the aphalcon config
     * @param array<string, mixed> $connection the CMS's Laravel connection array
     */
    public function __construct(
        private array $config,
        private array $connection,
    ) {
    }

    /**
     * @param array<string, mixed|\Closure> $shared name => instance, or a closure building it
     */
    public function make(array $shared = []): DiInterface
    {
        $di = $this->base();

        $this->registerDb($di);

        foreach ($shared as $name => $service) {
            $di->setShared((string) $name, self::definition($service));
        }

        foreach ((array) ($this->config['providers'] ?? []) as $provider) {
            $this->apply($di, $provider);
        }

        return $di;
    }

    private function base(): DiInterface
    {
        $base = $this->config['di'] ?? null;

        if ($base === null || $base === '') {
            return new FactoryDefault();
        }

        if ($base instanceof DiInterface) {
            return $base;
        }

        if (is_string($base) && class_exists($base)) {
            $di = new $base();
        } elseif (is_callable($base)) {
            $di = $base();
        } else {
            throw new \InvalidArgumentException('aphalcon.di must be null, a class name or a callable');
        }

        if (!$di instanceof DiInterface) {
            throw new \InvalidArgumentException('aphalcon.di must produce a Phalcon\Di\DiInterface');
        }

        return $di;
    }

    private function registerDb(DiInterface $di): void
    {
        $service = (string) ($this->config['db']['service'] ?? 'db');
        if ($service === '' || $this->connection === []) {
            return;
        }

        $connection = $this->connection;
        // Shared and lazy: a request that never touches the database never
        // opens a second connection to it.
        $di->setShared($service, function () use ($connection) {
            return DbConfig::adapter($connection);
        });
    }

    /**
     * A value the DI will hand back as given.
     *
     * Phalcon reads a closure as a factory and binds it to the container
     * first, which a static closure refuses - the service then resolves to
     * null with a warning. Every closure is wrapped in a bindable one, and a
     * scalar (a table prefix, say) in a closure returning it, since the DI
     * would read a bare string as a class name.
     */
    private static function definition(mixed $service): mixed
    {
        if ($service instanceof \Closure) {
            return function () use ($service) {
                return $service();
            };
        }

        if (is_object($service)) {
            return $service;
        }

        return function () use ($service) {
            return $service;
        };
    }

    /**
     * One entry of aphalcon.providers: Phalcon's own provider contract, an
     * instance of it, or a plain callable taking the DI.
     */
    private function apply(DiInterface $di, mixed $provider): void
    {
        if (is_string($provider) && class_exists($provider)) {
            $provider = new $provider();
        }

        if ($provider instanceof ServiceProviderInterface) {
            $di->register($provider);

            return;
        }

        if (is_callable($provider)) {
            $provider($di);

            return;
        }

        throw new \InvalidArgumentException(
            'aphalcon.providers entries must be Phalcon\Di\ServiceProviderInterface classes or callables'
        );
    }
}
