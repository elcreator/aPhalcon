<?php

/**
 * aPhalcon defaults. Copy to core/custom/config/aphalcon.php to change any of
 * them; keys you leave out keep the value below.
 */
return [
    // The container to start from. null builds a Phalcon\Di\FactoryDefault;
    // otherwise a class name, or a callable returning a Phalcon\Di\DiInterface.
    'di' => null,

    // The CMS connection exposed to Phalcon, as the DI service named here.
    // 'connection' is a key of config('database.connections'); null is the
    // CMS's default connection. An empty 'service' registers no adapter.
    'db' => [
        'connection' => null,
        'service' => 'db',
    ],

    // Registered into the DI in order, after 'db', 'cms' and 'evo'. Each entry
    // is a Phalcon\Di\ServiceProviderInterface class name or instance, or a
    // callable taking the DI. This is where a site's models and services go.
    'providers' => [],

    // The application answering requests under routes.prefix. null builds a
    // Phalcon\Mvc\Micro; otherwise a class name constructed with the DI, or a
    // callable taking the DI and returning a Phalcon\Mvc\Micro or a
    // Phalcon\Mvc\Application.
    'app' => null,

    'routes' => [
        // The CMS path the Phalcon app is mounted at. '' mounts nothing: the
        // DI and the Latte function still work, only the routes are off.
        'prefix' => 'app',

        // Laravel middleware for the mount. 'web' is what core/custom/routes.php
        // routes run under.
        'middleware' => ['web'],

        // Applied to a Micro app in order: a Phalcon\Mvc\Micro\Collection
        // class name or instance to mount, or a callable taking the app and
        // the DI. Ignored for a Phalcon\Mvc\Application, which routes itself.
        'handlers' => [],
    ],

    'latte' => [
        // Name of the Latte function that reaches the DI from a template:
        // {phalcon('service')} or {phalcon()} for the container itself.
        // '' registers none.
        'function' => 'phalcon',
    ],
];
