<?php

declare(strict_types=1);

use Elcreator\aPhalcon\Cms;
use Elcreator\aPhalcon\DbConfig;
use Elcreator\aPhalcon\Frontend\Documents;
use Elcreator\aPhalcon\Frontend\Frontend;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A small site in sqlite: a tree of four documents, two templates (one with a
 * view file, one without), a TV with a value and one relying on its default,
 * a weblink, an unpublished page and one in the bin.
 *
 *   1 index (site_start, template 1 "home")
 *   2 about (template 2 "page")
 *   3   about/team (template 2)
 *   4 error (error_page, template 2)
 *   5 link -> reference to document 2
 *   6 draft (unpublished)
 *   7 gone (deleted)
 *   8 legacy (template 3 "db-only": no view)
 */
function siteDb(): \Phalcon\Db\Adapter\AdapterInterface
{
    $db = DbConfig::adapter(['driver' => 'sqlite', 'database' => ':memory:']);
    $db->execute('CREATE TABLE evo_site_content (id INTEGER PRIMARY KEY, type TEXT DEFAULT "document", contentType TEXT DEFAULT "text/html", pagetitle TEXT, longtitle TEXT DEFAULT "", description TEXT DEFAULT "", alias TEXT, parent INTEGER DEFAULT 0, published INTEGER DEFAULT 1, deleted INTEGER DEFAULT 0, template INTEGER DEFAULT 0, content TEXT DEFAULT "", pub_date INTEGER DEFAULT 0, unpub_date INTEGER DEFAULT 0)');
    $db->execute('CREATE TABLE evo_site_templates (id INTEGER PRIMARY KEY, templatename TEXT, templatealias TEXT, templatesource TEXT DEFAULT "", templatefileextension TEXT DEFAULT "")');
    $db->execute('CREATE TABLE evo_site_tmplvars (id INTEGER PRIMARY KEY, name TEXT, default_text TEXT DEFAULT "", rank INTEGER DEFAULT 0)');
    $db->execute('CREATE TABLE evo_site_tmplvar_templates (tmplvarid INTEGER, templateid INTEGER)');
    $db->execute('CREATE TABLE evo_site_tmplvar_contentvalues (id INTEGER PRIMARY KEY, tmplvarid INTEGER, contentid INTEGER, value TEXT)');

    $db->execute("INSERT INTO evo_site_templates (id, templatename, templatealias, templatesource, templatefileextension) VALUES (1, 'Home', 'home', 'file', 'latte'), (2, 'Page', 'page', 'file', 'latte'), (3, 'Legacy', '', '', '')");
    $db->execute("INSERT INTO evo_site_content (id, pagetitle, alias, parent, template, content) VALUES (1, 'Home', 'index', 0, 1, '<p>home</p>'), (2, 'About', 'about', 0, 2, '<p>about</p>'), (3, 'Team', 'team', 2, 2, '<p>team</p>'), (4, 'Not found', 'error', 0, 2, '<p>404</p>'), (8, 'Legacy', 'legacy', 0, 3, '')");
    $db->execute("INSERT INTO evo_site_content (id, type, pagetitle, alias, parent, template, content) VALUES (5, 'reference', 'Link', 'link', 0, 2, '2')");
    $db->execute("INSERT INTO evo_site_content (id, pagetitle, alias, parent, template, published) VALUES (6, 'Draft', 'draft', 0, 2, 0)");
    $db->execute("INSERT INTO evo_site_content (id, pagetitle, alias, parent, template, deleted) VALUES (7, 'Gone', 'gone', 0, 2, 1)");
    $db->execute("INSERT INTO evo_site_tmplvars (id, name, default_text, rank) VALUES (1, 'subtitle', 'default subtitle', 0), (2, 'colour', 'grey', 1)");
    $db->execute("INSERT INTO evo_site_tmplvar_templates (tmplvarid, templateid) VALUES (1, 2), (2, 2)");
    $db->execute("INSERT INTO evo_site_tmplvar_contentvalues (tmplvarid, contentid, value) VALUES (1, 2, 'About us')");

    return $db;
}

/** @return array{0: Frontend, 1: Documents, 2: ArrayObject rendered} */
function site(array $settings = [], bool $fallback = true, array $views = ['home', 'page']): array
{
    $settings += [
        'site_start' => 1,
        'error_page' => 4,
        'friendly_url_prefix' => '',
        'friendly_url_suffix' => '.html',
        'use_alias_path' => 1,
    ];
    useFakeEvo(config: $settings);

    $rendered = new ArrayObject();
    $cms = new Cms(
        static function (string $alias, array $data) use ($rendered): string {
            $rendered[] = [$alias, $data];

            return '<view ' . $alias . '>' . ($data['pagetitle'] ?? '') . '</view>';
        },
        static fn (string $alias): bool => in_array($alias, $views, true),
        static fn (): object => evo(),
    );

    $documents = new Documents(siteDb(), 'evo_', static fn (string $name, mixed $default = null) => $cms->setting($name, $default));

    return [new Frontend($documents, $cms, $fallback), $documents, $rendered];
}

test('the root and ?id= resolve to documents whatever the URL style', function (): void {
    [, $documents] = site();

    expect($documents->resolve('')['id'])->toBe(1);
    expect($documents->resolve('/')['id'])->toBe(1);
    expect($documents->resolve('index.php')['id'])->toBe(1);
    expect($documents->resolve('index.php', ['id' => '3'])['id'])->toBe(3);
    expect($documents->resolve('anything', ['id' => 99]))->toBeNull();
});

test('friendly paths resolve by alias path, with prefix and suffix stripped', function (): void {
    [, $documents] = site(['friendly_url_prefix' => 'p-']);

    expect($documents->resolve('p-about.html')['id'])->toBe(2);
    expect($documents->resolve('p-about/team.html')['id'])->toBe(3);
    expect($documents->resolve('p-about/team')['id'])->toBe(3);
    // The alias exists, but not under that parent.
    expect($documents->resolve('p-team.html'))->toBeNull();
    expect($documents->resolve('p-nope.html'))->toBeNull();
});

test('without use_alias_path the last segment is enough', function (): void {
    [, $documents] = site(['use_alias_path' => 0, 'friendly_url_suffix' => '/']);

    expect($documents->resolve('team/')['id'])->toBe(3);
    expect($documents->resolve('whatever/team')['id'])->toBe(3);
});

test('a deleted document is not found by id or alias; an unpublished one is found but not live', function (): void {
    [$frontend, $documents] = site(fallback: false);

    expect($documents->find(7))->toBeNull();
    expect($documents->resolve('gone.html'))->toBeNull();
    expect($documents->resolve('draft.html')['id'])->toBe(6);
    expect($frontend->handle('draft.html')->getStatusCode())->toBe(404);
});

test('template variables come flat, the value over the default', function (): void {
    [, $documents] = site();

    expect($documents->tvs(2, 2))->toBe(['subtitle' => 'About us', 'colour' => 'grey']);
    expect($documents->tvs(3, 2))->toBe(['subtitle' => 'default subtitle', 'colour' => 'grey']);
    expect($documents->tvs(1, 1))->toBe([]);
});

test('a document renders through its template view with its fields and TVs as variables', function (): void {
    [$frontend, , $rendered] = site();

    $response = $frontend->handle('about/team.html');

    expect($response->getStatusCode())->toBe(200);
    expect($response->headers->get('Content-Type'))->toBe('text/html; charset=UTF-8');
    expect($response->headers->get(Frontend::HEADER))->toBe('aPhalcon');
    expect($response->getContent())->toBe('<view page>Team</view>');

    [$alias, $data] = $rendered[0];
    expect($alias)->toBe('page');
    expect($data['id'])->toBe(3);
    expect($data['alias'])->toBe('team');
    expect($data['content'])->toBe('<p>team</p>');
    expect($data['subtitle'])->toBe('default subtitle');
    expect($data['frontend'])->toBeTrue();
    expect($data['documentObject']['pagetitle'])->toBe('Team');
    expect($data['documentObject'])->not->toHaveKey('frontend');
});

test('a miss renders the error_page document with a 404', function (): void {
    [$frontend] = site();

    $response = $frontend->handle('nope.html');

    expect($response->getStatusCode())->toBe(404);
    expect($response->getContent())->toBe('<view page>Not found</view>');
});

test('a weblink redirects to its document', function (): void {
    [$frontend] = site();

    $response = $frontend->handle('link.html');
    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toBe('/index.php?id=2');
    expect($response->headers->get(Frontend::HEADER))->toBe('aPhalcon');
});

test('a template without a view file goes back to the CMS, or fails plainly without fallback', function (): void {
    [$lenient] = site();
    expect(static fn () => $lenient->handle('legacy.html'))->toThrow(NotFoundHttpException::class);

    [$strict] = site(fallback: false);
    $response = $strict->handle('legacy.html');
    expect($response->getStatusCode())->toBe(500);
    expect($response->getContent())->toContain('document #8');
});

test('a miss with no renderable error page goes back to the CMS, or is a plain 404', function (): void {
    [$lenient] = site(['error_page' => 8]);
    expect(static fn () => $lenient->handle('nope.html'))->toThrow(NotFoundHttpException::class);

    [$strict] = site(['error_page' => 0], fallback: false);
    expect($strict->handle('nope.html')->getStatusCode())->toBe(404);
});

test('document() renders by id for a route, and path() spells a document the way the site does', function (): void {
    [$frontend, $documents] = site(['friendly_url_prefix' => 'p-']);

    expect($frontend->document(2)->getContent())->toBe('<view page>About</view>');
    expect($frontend->document(6)->getStatusCode())->toBe(404);

    expect($documents->path($documents->find(1)))->toBe('');
    expect($documents->path($documents->find(3)))->toBe('p-about/team.html');

    [, $flat] = site(['use_alias_path' => 0, 'friendly_url_suffix' => '/']);
    expect($flat->path($flat->find(3)))->toBe('team/');
});
