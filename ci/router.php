<?php

/**
 * Router script for php's built-in server (ci/entrypoint.sh serve).
 *
 * A path that is a file under the document root is served as that file. Any
 * other path goes to the CMS's index.php, the way the shipped .htaccess and
 * nginx rules send it - which is what the Phalcon mount under /app/ needs,
 * being a Laravel route matched on the request path rather than on ?id=.
 *
 * Friendly document URLs still do not work here (see entrypoint.sh): the core
 * reads ?q= through filter_input(), which a router script cannot feed.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$file = $root . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

$dir = is_dir($file) ? rtrim($file, '/') : $root;
$script = $dir . '/index.php';

$_SERVER['SCRIPT_NAME'] = substr($script, strlen($root));
$_SERVER['SCRIPT_FILENAME'] = $script;
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
chdir($dir);
require $script;
