<?php

/**
 * Written by `artisan aphalcon:demo:install` when the site has no
 * core/custom/config/aphalcon.php of its own. Replace the demo classes with
 * yours; every key left out keeps the package default.
 */
return [
    'providers' => [
        Elcreator\aPhalcon\Demo\DemoServiceProvider::class,
    ],
    'routes' => [
        'prefix' => 'app',
        'handlers' => [
            Elcreator\aPhalcon\Demo\DemoRoutes::class,
        ],
    ],
];
