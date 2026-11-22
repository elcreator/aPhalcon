<?php

declare(strict_types=1);

use Elcreator\aPhalcon\Cms;
use Phalcon\Http\Response;

function makeCms(array $views = [], array $config = [], array $snippets = []): array
{
    $rendered = new ArrayObject();
    $evo = useFakeEvo($config, $snippets);

    $cms = new Cms(
        static function (string $alias, array $data) use ($views, $rendered): string {
            $rendered[] = [$alias, $data];

            return $views[$alias] ?? '';
        },
        static fn (string $alias): bool => isset($views[$alias]),
        static fn (): object => evo(),
    );

    return [$cms, $rendered, $evo];
}

test('view() renders a view name with its data', function (): void {
    [$cms, $rendered] = makeCms(['page' => '<h1>Hi</h1>']);

    expect($cms->view('page', ['title' => 'T']))->toBe('<h1>Hi</h1>');
    expect($rendered->getArrayCopy())->toBe([['page', ['title' => 'T']]]);
});

test('hasView() asks the factory', function (): void {
    [$cms] = makeCms(['page' => '']);

    expect($cms->hasView('page'))->toBeTrue();
    expect($cms->hasView('missing'))->toBeFalse();
});

test('respond() wraps the rendered view in an HTML Phalcon response', function (): void {
    [$cms] = makeCms(['page' => '<p>body</p>']);

    $response = $cms->respond('page', [], 202);

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->getContent())->toBe('<p>body</p>');
    expect($response->getStatusCode())->toBe(202);
    expect($response->getHeaders()->get('Content-Type'))->toBe('text/html; charset=UTF-8');
});

test('url(), setting() and snippet() go to the core', function (): void {
    [$cms, , $evo] = makeCms([], ['site_name' => 'Site'], ['rows' => static fn (array $p) => $p['n'] * 2]);

    expect($cms->evo())->toBe($evo);
    expect($cms->url(5))->toBe('/index.php?id=5');
    expect($cms->url(5, ['page' => 2]))->toBe('/index.php?id=5&page=2');
    // A stringified id, as the CMS connection's PDO options hand them out.
    expect($cms->url('7'))->toBe('/index.php?id=7');
    expect($cms->setting('site_name'))->toBe('Site');
    expect($cms->setting('nope', 'dflt'))->toBe('dflt');
    expect($cms->snippet('rows', ['n' => 21]))->toBe(42);
    expect($evo->snippetCalls)->toBe([['rows', ['n' => 21]]]);
});

test('links() resolves the link tags the manager writes, as the parser would', function (): void {
    [$cms] = makeCms([], ['site_url' => 'https://example.com/', 'base_url' => '/']);

    expect($cms->links('<a href="[~5~]">a</a> <a href="[~7~]">b</a> <img src="[(base_url)]x.png"> [(site_url)]'))
        ->toBe('<a href="/index.php?id=5">a</a> <a href="/index.php?id=7">b</a> <img src="/x.png"> https://example.com/');
    // Not a link tag: left alone.
    expect($cms->links('[~docs~] [~ 7 ~] [~[*id*]~] [(site_name)] [*content*]'))->toBe('[~docs~] [~ 7 ~] [~[*id*]~] [(site_name)] [*content*]');
});

test('enter() makes a document the current one of the core, parse() runs the parser over a field', function (): void {
    [$cms, , $evo] = makeCms();

    $cms->enter(['id' => '5', 'pagetitle' => 'Prices'], ['price' => ['price', '$12', '', '', 'text']]);
    expect($evo->documentIdentifier)->toBe(5);
    expect($evo->documentMethod)->toBe('id');
    expect($evo->documentObject)->toBe(['id' => '5', 'pagetitle' => 'Prices', 'price' => ['price', '$12', '', '', 'text']]);

    expect($cms->parse('<h1>[*pagetitle*]</h1> [*price*] <a href="[~5~]">x</a> [!Clock!]'))
        ->toBe('<h1>Prices</h1> $12 <a href="/index.php?id=5">x</a> [[Clock]]');
    // Uncached snippet tags go to the parser as cached ones: nothing here is page-cached.
    expect($evo->parsed[0])->toContain('[[Clock]]');
});
