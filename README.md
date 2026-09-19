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

## Demo

```bash
cd core
php artisan aphalcon:demo:install      # or --force to skip the prompt
php artisan aphalcon:demo:remove
```

Installs one template kept in `views/aphalcon-demo-page.latte`, one document
at `/aphalcon-demo.html` using it, a Phalcon route set at `/app/` rendering
`views/aphalcon-demo.latte`, and — only if the site has none of its own —
`core/custom/config/aphalcon.php` naming the demo's provider and routes and
`core/custom/config/alattex.php` switching the parser off for file templates.
Removal deletes only files still identical to what was installed.

The same classes are the test fixtures: `tests/Integration/DemoTest.php` runs
the demo's provider, model and routes over an in-memory sqlite and renders the
shipped views through the real aLatteX engine.

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
