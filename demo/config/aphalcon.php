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
    'frontend' => [
        // Phalcon serves the front end: /aphalcon-demo.html (or ?id=) is
        // resolved and rendered by the front controller, and every page with
        // a .latte template is too. A page whose template lives in the
        // database - the stock start page - is handed back to the CMS parser.
        'takeover' => true,
        'fallback' => true,
    ],
];
