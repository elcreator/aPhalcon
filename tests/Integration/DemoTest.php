<?php

declare(strict_types=1);

use Elcreator\aLatteX\LattexEngine;
use Elcreator\aPhalcon\Cms;
use Elcreator\aPhalcon\DiFactory;
use Elcreator\aPhalcon\Demo\DemoRoutes;
use Elcreator\aPhalcon\Demo\DemoServiceProvider;
use Elcreator\aPhalcon\Http\Bridge;
use Elcreator\aPhalcon\Latte\PhalconExtension;
use Phalcon\Di\DiInterface;

/**
 * The demo, end to end, without a CMS: the DI the provider would build, over
 * an in-memory sqlite with the CMS's table, the demo's provider and routes on
 * it, and the shipped view files rendered by the real aLatteX engine.
 */
function demoDi(): DiInterface
{
    $config = require dirname(__DIR__, 2) . '/demo/config/aphalcon.php';
    $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'evo_'];

    useFakeEvo(config: [
        'site_name' => 'Demo Site',
        'site_url' => 'http://demo.test/',
        'site_start' => 1,
    ]);

    $views = dirname(__DIR__, 2) . '/demo/views';
    $engine = null;
    $di = null;

    $render = static function (string $alias, array $data) use ($views, &$engine, &$di): string {
        if (!class_exists(\Latte\Engine::class) || !class_exists(LattexEngine::class)) {
            return 'rendered ' . $alias . ' with ' . implode(',', array_keys($data));
        }
        if ($engine === null) {
            $engine = new LattexEngine([]);
            $engine->addExtension(new PhalconExtension(static fn () => $di));
        }

        return $engine->renderView($views . '/' . $alias . '.latte', $data);
    };

    $cms = new Cms($render, static fn (string $alias): bool => is_file($views . '/' . $alias . '.latte'), static fn () => evo());

    $di = (new DiFactory($config, $connection))->make([
        'cms' => $cms,
        'evo' => static fn () => evo(),
        'tablePrefix' => 'evo_',
    ]);

    $db = $di->get('db');
    $db->execute('CREATE TABLE evo_site_content (id INTEGER PRIMARY KEY, pagetitle TEXT, alias TEXT, parent INTEGER DEFAULT 0, published INTEGER DEFAULT 1, deleted INTEGER DEFAULT 0, template INTEGER DEFAULT 0, publishedon INTEGER DEFAULT 0)');
    $db->execute("INSERT INTO evo_site_content (id, pagetitle, alias, publishedon) VALUES (1, 'Home', 'index', 100), (2, 'About', 'about', 200), (3, 'Hidden', 'hidden', 300)");
    $db->execute('UPDATE evo_site_content SET deleted = 1 WHERE id = 3');

    return $di;
}

test('the demo provider registers the site services and the model reads the CMS table', function (): void {
    $di = demoDi();
    expect($di->getService('db')->isResolved())->toBeTrue();

    expect((new DemoServiceProvider()) instanceof \Phalcon\Di\ServiceProviderInterface)->toBeTrue();
    expect($di->get('greeter')->greet('you'))->toBe('Hello, you - from Phalcon, on Demo Site');

    $titles = [];
    foreach ($di->get('documents')->latest() as $document) {
        $titles[] = $document->pagetitle;
    }
    expect($titles)->toBe(['About', 'Home']);
    expect($di->get('documents')->find(3))->toBeNull();
});

test('the demo routes render the shipped CMS view through the bridge', function (): void {
    $di = demoDi();
    $config = require dirname(__DIR__, 2) . '/demo/config/aphalcon.php';
    $bridge = new Bridge($di, $config);

    expect($config['routes']['handlers'])->toBe([DemoRoutes::class]);

    $index = $bridge->handle('/');
    expect($index->getStatusCode())->toBe(200);
    expect($index->headers->get('Content-Type'))->toStartWith('text/html');
    expect($index->getContent())->toContain('Phalcon route, CMS view');
    if (class_exists(\Latte\Engine::class)) {
        expect($index->getContent())->toContain('Hello, visitor - from Phalcon, on Demo Site');
        expect($index->getContent())->toContain('href="/index.php?id=2">About</a>');
        expect($index->getContent())->toContain('http://demo.test/app/documents/1');
        expect($index->getContent())->not->toContain('Hidden');
    }

    $json = $bridge->handle('/documents.json');
    expect($json->headers->get('Content-Type'))->toBe('application/json');
    expect(json_decode((string) $json->getContent(), true))->toBe([
        ['id' => 2, 'pagetitle' => 'About', 'alias' => 'about'],
        ['id' => 1, 'pagetitle' => 'Home', 'alias' => 'index'],
    ]);

    $one = $bridge->handle('/documents/1');
    expect($one->getStatusCode())->toBe(200);
    expect($one->getContent())->toContain('Home');

    $missing = $bridge->handle('/documents/99');
    expect($missing->getStatusCode())->toBe(404);
    expect($missing->getContent())->toContain('No document 99');

    expect($bridge->handle('/documents/abc')->getStatusCode())->toBe(404);
});

test('the demo page template renders a document with Phalcon services and no parser pass', function (): void {
    if (!class_exists(\Latte\Engine::class) || !class_exists(LattexEngine::class)) {
        skip('Latte and aLatteX are not reachable; build against a core that has them.');
    }

    $di = demoDi();
    $engine = new LattexEngine([]);
    $engine->addExtension(new PhalconExtension(static fn () => $di));

    $html = $engine->renderView(dirname(__DIR__, 2) . '/demo/views/aphalcon-demo-page.latte', [
        'documentObject' => [
            'id' => 2,
            'pagetitle' => 'About',
            'content' => '<p>Body</p>',
        ],
    ]);

    expect($html)->toContain('<title>About</title>');
    expect($html)->toContain('Hello, About - from Phalcon, on Demo Site');
    expect($html)->toContain('<p>Body</p>');
    expect($html)->toContain('href="/index.php?id=2">About</a>');
    expect($html)->toContain('http://demo.test/app/');
    // What the parser would have replaced is still there for the reader.
    expect($html)->toContain('<code>{{site_name}}</code> and <code>[(site_name)]</code>');
});

test('the published package default counts as no config, so the demo may replace it', function (): void {
    $defaults = \Elcreator\aPhalcon\Demo\DemoSeeder::defaultConfigs();
    $demo = \Elcreator\aPhalcon\Demo\DemoSeeder::configs();

    expect(array_keys($defaults))->toBe(['aphalcon.php']);
    expect($defaults['aphalcon.php'])->toBe(file_get_contents(dirname(__DIR__, 2) . '/config/aphalcon.php'));
    expect(array_keys($demo))->toBe(['alattex.php', 'aphalcon.php']);
    expect($demo['aphalcon.php'])->not->toBe($defaults['aphalcon.php']);
});
