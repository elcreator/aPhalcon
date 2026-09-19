# aPhalcon

**Phalcon inside Evolution CMS.**

One Phalcon DI for the site, opened on the CMS's own database credentials, with
the site's Phalcon models and services in it. Those services are one call away
from any Latte template aLatteX renders — a CMS page can list rows from a
`Phalcon\Mvc\Model` — and Phalcon routes mounted under a CMS path can render the
CMS's own `views/<alias>.latte` files. Two frameworks, one container, one
database, one set of templates.

Configurable for a plugin developer: which container to start from, which
connection to expose, which `Phalcon\Di\ServiceProviderInterface` classes
register the site's services, and whether the mount runs a `Phalcon\Mvc\Micro`
built from route handlers or a full `Phalcon\Mvc\Application` of your own.

---

## Requirements

- PHP 8.3+ with the [Phalcon 5](https://phalcon.io) extension loaded
- Evolution CMS 3.5.9+
- [aLatteX](https://github.com/elcreator/aLatteX) with `LattexEngine::addExtension()` (pulled in via Composer)

## Installation

```bash
cd core
php artisan package:installrequire elcreator/aphalcon "*"
php artisan vendor:publish --provider="Elcreator\aPhalcon\aPhalconServiceProvider"
```

The publish step copies `config/aphalcon.php` to `core/custom/config/aphalcon.php`.
That file is where a site says what it has; every key left out keeps the default.

## Configuration

```php
// core/custom/config/aphalcon.php
return [
    // null → Phalcon\Di\FactoryDefault; or a class name / callable returning a DiInterface
    'di' => null,

    // The CMS connection, as a Phalcon PDO adapter under this service name
    'db' => ['connection' => null, 'service' => 'db'],

    // Phalcon\Di\ServiceProviderInterface classes (or callables taking the DI):
    // the site's models and services
    'providers' => [
        App\Providers\ModelsProvider::class,
    ],

    // null → a Micro built from routes.handlers; or a class name / callable
    // returning a Phalcon\Mvc\Micro or Phalcon\Mvc\Application
    'app' => null,

    'routes' => [
        'prefix' => 'app',          // mounted at /app/...; '' mounts nothing
        'middleware' => ['web'],
        'handlers' => [             // Micro only: Collection classes, or callables (Micro $app, DiInterface $di)
            App\Routes\Api::class,
        ],
    ],

    'frontend' => [
        'takeover' => false,    // true: Phalcon serves the whole front end (see below)
        'fallback' => true,     // hand what it cannot render back to the CMS parser
    ],

    'latte' => ['function' => 'phalcon'],   // the Latte function name; '' for none
];
```

### What is in the DI before your providers run

| Service | What it is |
|---|---|
| `db` | `Phalcon\Db\Adapter\Pdo\{Mysql,Postgresql,Sqlite}` on the CMS connection (lazy: opened on first use) |
| `cms` | `Elcreator\aPhalcon\Cms` — the CMS for a handler: `view()`, `respond()`, `url()`, `setting()`, `snippet()`, `evo()` |
| `evo` | the core, `evo()`, with system settings loaded |
| `tablePrefix` | the connection's table prefix, for a model's `setSource()` |
| `site` | `ElcreatorPhalcon\Frontend\Frontend` — the document tree as Phalcon reads it: `document($id)`, `handle($path)`, `render($row)`, `documents()` |

Everything `FactoryDefault` provides (`modelsManager`, `modelsMetadata`, `request`, `response`, `router`, …) is there as usual, so a `Phalcon\Mvc\Model` works with nothing more than a source name:

```php
final class Article extends Phalcon\Mvc\Model
{
    public function initialize(): void
    {
        $this->setSource($this->getDI()->get('tablePrefix') . 'articles');
    }
}
```

> Phalcon binds a closure definition to the container before calling it, and
> a route handler to the app. A **static** closure cannot be bound and resolves
> to `null` with a warning — use `function () use (...)`, not `static fn`.

## Phalcon services on a CMS page

Every Latte template aLatteX renders — a template held in the database, or a
file the manager's *Template code → In a file → Latte* switch made — gets the
`{phalcon()}` function:

```latte
<h1>{$pagetitle}</h1>
<p>{phalcon('greeter')->greet($pagetitle)}</p>

<ul>
    <li n:foreach="phalcon('articles')->latest(5) as $article">
        <a href="{$evo->makeUrl($article->document_id)}">{$article->title}</a>
    </li>
</ul>

{var $di = phalcon()}
```

To keep the CMS's parser off a file template entirely, so `{{chunk}}` and
`[*field*]` are just text, set aLatteX's switch:

```php
// core/custom/config/alattex.php
return ['evo_tags' => false];
```

Document fields are still there as plain variables — that is aLatteX's own
`renderView()`, not the parser.

## CMS views from Phalcon routes

Route handlers get the DI, so `$app->cms` (Micro) or `$this->cms` (a
controller) reaches the site:

```php
final class Api
{
    public function __invoke(Phalcon\Mvc\Micro $app, Phalcon\Di\DiInterface $di): void
    {
        // GET /app/articles → views/articles.latte, rendered by aLatteX
        $app->get('/articles', function () use ($app) {
            return $app->cms->respond('articles', [
                'articles' => Article::find(['order' => 'id DESC']),
            ]);
        });

        // GET /app/articles.json → an array is JSON
        $app->get('/articles.json', function () {
            return Article::find()->toArray();
        });

        // A string, an echo, or status/headers set on $app->response all work too
        $app->get('/missing', function () use ($app) {
            $app->response->setStatusCode(404);

            return $app->cms->view('not-found');
        });
    }
}
```

`Cms::view($alias, $data)` renders any view the CMS's view factory knows —
`views/<alias>.latte` first, `.blade.php` as well — to a string;
`Cms::respond()` wraps it in a `Phalcon\Http\Response`. The view sees `$data`,
`$evo`, and `{phalcon()}`.

The mount is a Laravel route registered ahead of the CMS's parser fallback:
`/app/...` never reaches the document tree, and the request the handler sees is
the real one (`$app->request` reads the same superglobals). Whatever the handler
produces — a Phalcon `Response`, a string, an array, printed output — becomes the
Laravel response the CMS sends.

## Replacing the CMS front end

```php
'frontend' => ['takeover' => true],
```

With this on, Phalcon answers every front-end request and the CMS renders
only the manager (its own entry point, `manager/index.php`, never touched).
A request path is resolved to a document the way the CMS would resolve it —
`site_start` for `/`, `?id=N`, or the alias path with `friendly_url_prefix` /
`friendly_url_suffix` stripped and `use_alias_path` honoured — and the
document is rendered from its template's `views/<templatealias>.latte`, with
the content table's columns and the document's TVs as plain variables:

```latte
{extends 'layout.latte'}
{block content}
    <h1>{$pagetitle}</h1>
    <p>{$description}</p>
    {$content|noescape}
    <a href="{phalcon('cms')->url($parent)}">up</a>
{/block}
```

No parser pass: `{{chunk}}`, `[[snippet]]` and `[*tv*]` are text. What a page
needs beyond its own row it asks Phalcon for through `{phalcon()}`. Weblinks
redirect, unpublished and deleted documents are misses, and a miss renders the
`error_page` document with a 404.

The reads go through the DI's `db` — `Frontend\Documents` is a small
repository over `site_content`, `site_templates` and the TV tables — so the
front end runs on Phalcon's connection with the CMS's credentials, and the
same object is the DI's `site` service, for a route that wants a page:

```php
$app->get('/latest', function () use ($app) {
    return $app->site->document((int) $app->documents->latest(1)[0]->id);
});
```

**`fallback`** (default `true`) is the escape hatch: a document whose template
has no view file — one still held in the database — and a miss whose
`error_page` cannot be rendered are handed back to the CMS parser, by the
`NotFoundHttpException` that `Core::processRoutes()` answers with
`executeParser()`. A site can move templates to Latte one at a time. With
`fallback => false` those are a plain 500 / 404 from Phalcon and the parser
never runs. Every response the front controller produces carries
`X-Rendered-By: aPhalcon`.

What the parser did that this does not: web-user access (`privateweb`,
`unauthorized_page`), the page cache, and the document-level plugin events
(`OnLoadWebDocument`, `OnWebPagePrerender`, …). A site that needs them for
some documents keeps `fallback` on and their templates in the database.

## Demo

```bash
cd core
php artisan aphalcon:demo:install      # or --force to skip the prompt
php artisan aphalcon:demo:remove
```

Installs one template kept in `views/aphalcon-demo-page.latte`, one document
at `/demo` (alias `demo`) using it, a Phalcon route set at `/app/` rendering
`views/aphalcon-demo.latte`, the two parents both of those extend —
`aphalcon-section.latte` → `aphalcon-base.latte`, a three-level `{extends}`
chain the way the aLatteX demo lays pages out, with the CMS document and the
Phalcon route as two roots of the same chain — and, only if the site has none
of its own,
`core/custom/config/aphalcon.php` naming the demo's provider and routes and
`core/custom/config/alattex.php` switching the parser off for file templates.
Removal deletes only files still identical to what was installed.

The demo config turns `frontend.takeover` on, so the demo page is served by
the front controller (`X-Rendered-By: aPhalcon`), `/app/page/{id}` renders any
document from a route through the `site` service, and the stock start page —
its template in the database — is handed back to the CMS by `fallback`.

The same classes are the test fixtures: `tests/Integration/DemoTest.php` runs
the demo's provider, model and routes over an in-memory sqlite and renders the
shipped views through the real aLatteX engine; `FrontendTest.php` resolves and
renders a small document tree the way the front controller does.

## Development

```bash
# Unit and integration suite, borrowing the core's autoloader (see tests/bootstrap.php)
EVO_CORE_PATH_TEST=/path/to/evolution/core php /path/to/evolution/core/vendor/bin/pest

# A real CMS with the plugin and the demo, on http://localhost:8080
docker compose -f ci/compose.yaml up serve
```

See [ci/README.md](ci/README.md) for the docker toolchain and [AGENTS.md](AGENTS.md)
for the layout.

## License

GPL-3.0-or-later
