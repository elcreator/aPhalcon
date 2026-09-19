<?php

declare(strict_types=1);

use Elcreator\aPhalcon\DbConfig;
use Phalcon\Db\Adapter\Pdo\Mysql;
use Phalcon\Db\Adapter\Pdo\Postgresql;
use Phalcon\Db\Adapter\Pdo\Sqlite;

test('a mysql connection maps host, port, database and charset onto the Phalcon descriptor', function (): void {
    [$class, $descriptor] = DbConfig::descriptor([
        'driver' => 'mysql',
        'host' => 'db',
        'port' => '3306',
        'database' => 'evolution',
        'username' => 'root',
        'password' => 'evo',
        'unix_socket' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_520_ci',
        'prefix' => 'evo_',
        'strict' => false,
        'engine' => 'innodb',
        'options' => [PDO::ATTR_STRINGIFY_FETCHES => true],
    ]);

    expect($class)->toBe(Mysql::class);
    expect($descriptor)->toBe([
        'host' => 'db',
        'dbname' => 'evolution',
        'username' => 'root',
        'password' => 'evo',
        'port' => 3306,
        'charset' => 'utf8mb4',
        'options' => [PDO::ATTR_STRINGIFY_FETCHES => true],
    ]);
});

test('a unix socket replaces the host for mysql', function (): void {
    [, $descriptor] = DbConfig::descriptor([
        'driver' => 'mariadb',
        'host' => 'ignored',
        'database' => 'evo',
        'username' => 'u',
        'password' => 'p',
        'unix_socket' => '/var/run/mysqld/mysqld.sock',
    ]);

    expect($descriptor)->not->toHaveKey('host');
    expect($descriptor['unix_socket'])->toBe('/var/run/mysqld/mysqld.sock');
});

test('pgsql maps to the Postgresql adapter', function (): void {
    [$class, $descriptor] = DbConfig::descriptor([
        'driver' => 'pgsql',
        'host' => 'pg',
        'port' => 5432,
        'database' => 'evo',
        'username' => 'u',
        'password' => 'p',
        'charset' => 'utf8',
    ]);

    expect($class)->toBe(Postgresql::class);
    expect($descriptor)->toBe([
        'host' => 'pg',
        'dbname' => 'evo',
        'username' => 'u',
        'password' => 'p',
        'port' => 5432,
        'charset' => 'utf8',
    ]);
});

test('sqlite carries only the file path', function (): void {
    [$class, $descriptor] = DbConfig::descriptor([
        'driver' => 'sqlite',
        'database' => '/site/core/database/evo.sqlite',
        'prefix' => 'evo_',
    ]);

    expect($class)->toBe(Sqlite::class);
    expect($descriptor)->toBe(['dbname' => '/site/core/database/evo.sqlite']);
});

test('an unknown driver is refused by name', function (): void {
    expect(static fn () => DbConfig::descriptor(['driver' => 'sqlsrv']))
        ->toThrow(InvalidArgumentException::class, 'sqlsrv');
});

test('adapter() opens a working Phalcon connection', function (): void {
    $adapter = DbConfig::adapter(['driver' => 'sqlite', 'database' => ':memory:']);

    expect($adapter)->toBeInstanceOf(Sqlite::class);
    expect($adapter->fetchColumn('SELECT 40 + 2'))->toBe(42);
});

test('the table prefix is the connection prefix', function (): void {
    expect(DbConfig::prefix(['prefix' => 'evo_']))->toBe('evo_');
    expect(DbConfig::prefix([]))->toBe('');
});
