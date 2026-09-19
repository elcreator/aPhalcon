<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Like aLatteX, this package carries no vendor tree in CI: it is installed
 * into an Evolution CMS core and borrows that core's autoloader, which is
 * where Illuminate, Pest, Latte and aLatteX come from. So the suite points at
 * the core it is developed against (EVO_CORE_PATH_TEST) and maps this
 * package's namespace on top. A developer who has run `composer install` here
 * gets the package's own vendor tree instead.
 *
 * Phalcon itself is an extension: the suite needs ext-phalcon loaded, and
 * says so rather than skipping every test.
 *
 * The CMS is never booted. What these tests cover is the DI, the request
 * bridge and the Latte function; the core is a two-method double.
 */

if (!extension_loaded('phalcon')) {
    fwrite(STDERR, "aPhalcon tests need the phalcon extension loaded.\n");
    exit(1);
}

$core = getenv('EVO_CORE_PATH_TEST') ?: '';
$core = $core !== '' ? rtrim(str_replace('\\', '/', $core), '/') : '';

if ($core !== '' && is_file($core . '/vendor/autoload.php')) {
    require $core . '/vendor/autoload.php';
} elseif (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    require dirname(__DIR__) . '/vendor/autoload.php';
} else {
    fwrite(STDERR, "aPhalcon tests need an autoloader to borrow.\n"
        . "Either set EVO_CORE_PATH_TEST to an Evolution CMS core built with dev\n"
        . "dependencies, or run `composer install` in this repository.\n");
    exit(1);
}

/**
 * PSR-4 for one namespace out of one directory, prepended so a working copy
 * wins over an installed copy in the borrowed core.
 */
$psr4 = static function (string $prefix, string $directory): void {
    spl_autoload_register(static function (string $class) use ($prefix, $directory): void {
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $path = $directory . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }, true, true);
};

$psr4('Elcreator\\aPhalcon\\', dirname(__DIR__) . '/src');

// The developer layout has aLatteX as a sibling checkout with its own vendor
// tree. When the borrowed core does not carry aLatteX and Latte, take both
// from there, so the integration test renders through the real engine.
$sibling = dirname(__DIR__, 2) . '/aLatteX';
if (!class_exists(\Elcreator\aLatteX\LattexEngine::class) && is_dir($sibling . '/src')) {
    $psr4('Elcreator\\aLatteX\\', $sibling . '/src');
    // Latte keeps more than one class per file in places, so its own
    // classmap is the loader, not PSR-4.
    $classmap = $sibling . '/vendor/composer/autoload_classmap.php';
    if (!class_exists(\Latte\Engine::class) && is_file($classmap)) {
        $map = require $classmap;
        spl_autoload_register(static function (string $class) use ($map): void {
            if (str_starts_with($class, 'Latte\\') && isset($map[$class])) {
                require $map[$class];
            }
        }, true, true);
    }
}

// Latte's base Extension class, for the unit test of the function map when
// no Latte is reachable at all.
if (!class_exists(\Latte\Extension::class)) {
    eval(<<<'PHP'
namespace Latte;

abstract class Extension
{
    public function getFunctions(): array
    {
        return [];
    }
}
PHP);
}

define('APHALCON_TEST_STORAGE', sys_get_temp_dir() . '/aphalcon-tests-' . getmypid());
@mkdir(APHALCON_TEST_STORAGE, 0775, true);
register_shutdown_function(static function (): void {
    $dir = APHALCON_TEST_STORAGE;
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dir);
});

/**
 * Stands in for `EvolutionCMS\Core`: the calls Cms forwards, answered from
 * arrays. Also the `path.storage` lookup aLatteX makes for its cache, when the
 * integration test builds a real engine.
 */
final class FakeEvolutionCore
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $snippetCalls = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        private array $config = [],
        private array $snippets = [],
        public array $documentObject = [],
    ) {
    }

    public function getInstance(): self
    {
        return $this;
    }

    public function make(string $abstract, array $parameters = []): mixed
    {
        return $abstract === 'path.storage' ? APHALCON_TEST_STORAGE : null;
    }

    public function getConfig(string $name = '', mixed $default = null): mixed
    {
        return $this->config[$name] ?? $default;
    }

    public function makeUrl(int|string $id, string $alias = '', string $args = '', string $scheme = ''): string
    {
        return '/index.php?id=' . $id . ($args !== '' ? '&' . $args : '');
    }

    public function runSnippet(string $name, array $params = []): mixed
    {
        $this->snippetCalls[] = [$name, $params];
        $snippet = $this->snippets[$name] ?? null;

        return is_callable($snippet) ? $snippet($params) : $snippet;
    }
}

foreach ([
    'EVO_CLASS' => FakeEvolutionCore::class,
    // The three the core's `evo()` guards its CSRF check behind.
    'IN_MANAGER_MODE' => false,
    'IN_INSTALL_MODE' => false,
    'EVO_API_MODE' => false,
] as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

// Only reached when there is no core to borrow one from.
if (!function_exists('evo')) {
    function evo(): object
    {
        return $GLOBALS['evo'];
    }
}

/**
 * Install a core double where `evo()` looks: the borrowed core's preload has
 * defined the real function, which caches its answer in `global $evo`.
 */
function useFakeEvo(array $config = [], array $snippets = [], array $documentObject = []): FakeEvolutionCore
{
    return $GLOBALS['evo'] = new FakeEvolutionCore($config, $snippets, $documentObject);
}
