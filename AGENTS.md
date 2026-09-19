# AGENTS.md — aPhalcon

Guidelines for AI agents working on this codebase.

---

## Project overview

`aPhalcon` is an Evolution CMS plugin (type `evolutioncms-plugin`) that runs
Phalcon inside the CMS: one `Phalcon\Di` on the CMS's database, the site's
Phalcon services reachable from Latte templates through aLatteX, and Phalcon
routes mounted under a CMS path that render the CMS's view files.

Key constraint: **no core Evolution CMS files are modified**, and no aLatteX
files either. All integration is through the Laravel container, one Laravel
route, and aLatteX's `LattexEngine::addExtension()` hook.

---

## Repository layout

```
composer.json              Package manifest (type: evolutioncms-plugin); requires ext-phalcon and elcreator/alattex
config/aphalcon.php        Defaults; published to core/custom/config/aphalcon.php
demo/
  views/                   The two .latte files the demo installs into views/
  config/                  The two config files it writes into core/custom/config/ when absent
src/
  aPhalconServiceProvider.php  Binds the DI and the Bridge, mounts the route, attaches the Latte function
  DiFactory.php                Builds the DI from config: base, db, shared services, providers
  DbConfig.php                 Laravel connection array -> Phalcon PDO adapter class + descriptor
  Cms.php                      The 'cms' DI service: view(), respond(), url(), setting(), snippet(), evo()
  Http/Bridge.php              Builds the Micro/Application and turns a path into a Laravel response
  Http/ResponseConverter.php   Phalcon Response | string | array | echo -> Symfony/Laravel response
  Latte/PhalconExtension.php   The {phalcon()} Latte function
  Console/                     DemoInstallCommand, DemoRemoveCommand (console only)
  Demo/                        The demo's provider, model, repository, routes and seeder
ci/                        Docker toolchain shared with the sibling plugins (see ci/README.md)
tests/                     Pest; bootstrap borrows a core's autoloader (EVO_CORE_PATH_TEST)
```

---

## Architecture

### Request path for `/app/...`

```
Laravel router (Core::processRoutes)
  → route "aphalcon" (any method, /<prefix>/{path?})
      → Bridge::handle($path)
          → Micro::handle('/' . $path)  or  Application::handle()
          → ResponseConverter::convert(returned, echoed, DI 'response')
  → Symfony response, sent by the core
```

The route is registered from the provider's `boot()`. The core's parser
fallback is registered earlier, in `RoutingServiceProvider::register()`, and
that is fine: Laravel matches fallback routes last whatever the order.

### Render path for `{phalcon()}` on a page

```
aLatteX resolves LattexEngine
  → afterResolving hook adds PhalconExtension (function name from config)
  → template calls phalcon('service') → DI resolved on first call
```

The hook rides on container resolution, not on provider order, because Latte
takes extensions only before its first compile.

### The DI

`DiFactory::make()` in a fixed order: base container → `db` (lazy, from
`DbConfig`) → the provider's shared services (`cms`, `evo`, `tablePrefix`) →
the site's `providers`. A site provider can rely on all of the earlier ones and
can replace any of them.

`Cms` is built on closures (render, exists, core) rather than on the
container, so the unit tests construct it without a CMS.

---

## Phalcon facts that shaped the code

- **Closures are bound.** A DI definition closure is bound to the container,
  a route handler to the Micro. A `static` closure cannot be bound: the DI
  service resolves to `null` with a warning, the route answers nothing.
  `DiFactory::definition()` wraps every closure it is handed in a bindable
  one; the demo and the tests use non-static closures throughout. A bare
  string given to `setShared()` is read as a class name, so scalars are
  wrapped too.
- **Micro sends what a handler returns.** A `ResponseInterface` return is
  sent (`header()` calls plus echo) and a string is echoed, inside
  `Micro::handle()`. The bridge output-buffers the call and rebuilds the
  response from the returned object, so the body is never duplicated. The
  `header()` calls Phalcon made are then overwritten by the Symfony response
  with the same names and status.
- **`setResponseHandler()` is not used.** With one installed, `handle()`
  returns null and `getReturnedValue()` is stale after a `notFound` handler,
  so the user's 404 body would be lost. Buffering is the approach that keeps
  every Phalcon idiom working.
- **`FactoryDefault`'s router has default routes.** Micro clears them the
  first time `getRouter()` is called, which normally happens when the first
  route is added. `Bridge::build()` calls it up front so an app with no routes
  answers 404 instead of throwing `NoMatchedRouteHandler`.
- **`findFirst($id)` ignores nothing.** A CMS row in the recycle bin has
  `deleted = 1` and is still found by primary key; `Demo\Documents::find()`
  filters it, and so should any model over `site_content`.

## Evolution CMS facts that shaped the code

- **A Laravel route runs before the parser**, and it is the parser
  (`executeParser()` → `getSettings()`) that loads `system_settings`. The
  provider's `core()` calls `getSettings()` when `config` is empty, so a
  handler asking for `site_url` or a document URL gets an answer.
- **`config('aphalcon')` is a whole-key replacement.** `custom/config/*.php`
  overwrite the key; `mergeConfigFrom()` in `register()` puts the package
  defaults underneath, so a site file with two keys keeps the other six.
- **The route needs `web`.** The `web` middleware group is what
  `core/custom/routes.php` runs under; `Core::setRouterMiddleware()` defines
  it just before dispatch.
- **Package providers come from `core/custom/config/app/providers/`**, written
  by `package:discover`. `ci/smoke.sh` checks the file is there.

---

## What to change and where

| Task | File(s) to edit |
|---|---|
| Add a service every site gets (like `cms`) | `aPhalconServiceProvider::register()`, the `make([...])` call |
| Support another database driver | `DbConfig::ADAPTERS` and `descriptor()` |
| Change how a handler's result becomes a response | `Http/ResponseConverter.php` |
| Change how the app is built from config | `Http/Bridge::build()` / `applyHandler()` |
| Add a Latte function or filter | `Latte/PhalconExtension.php` |
| Change what `Cms` offers a handler | `src/Cms.php` (and `CmsTest`) |
| Add a config key | `config/aphalcon.php`, then wherever it is read; document it in README |
| Change the demo | `src/Demo/*`, `demo/views/*`, `demo/config/*`; `DemoTest` pins the output |

---

## What not to do

- Do not use `static` closures for anything Phalcon will bind (see above).
- Do not resolve the DI in `boot()`. It builds the site's providers and can
  open a connection; it is a singleton resolved on first use, and the Latte
  function and the route both ask for it lazily.
- Do not call `evo()->parseDocumentSource()` from a handler. A Phalcon route
  is not a document; render a view, or hand data to a template.
- Do not write into a site's `core/custom/config/*.php` that already exists.
  The demo seeder writes those files only when absent and removes them only
  when byte-identical.
- Do not add a second autoloader for Illuminate in `tests/bootstrap.php`. The
  borrowed core has it; only aLatteX and Latte are taken from the sibling
  checkout, and only when the core lacks them.

## Testing

```sh
EVO_CORE_PATH_TEST=/path/to/evolution/core php /path/to/evolution/core/vendor/pestphp/pest/bin/pest --no-coverage
docker compose -f ci/compose.yaml run --rm test     # the same, inside a built CMS
docker compose -f ci/compose.yaml up serve          # click through the demo on :8080
```

Manual checks after a change to the bridge or the extension:

1. `/app/` renders `views/aphalcon-demo.latte` with rows from the model.
2. `/app/documents.json` is JSON; `/app/documents/999999` is a 404 with a body.
3. `/aphalcon-demo.html` shows the greeting and the model rows, and `{{site_name}}` is printed as text.
4. Remove `core/custom/config/aphalcon.php`: `/app/` is a 404 from Phalcon, the page still renders (no `greeter` → Latte error in the event log, as with any missing service).
