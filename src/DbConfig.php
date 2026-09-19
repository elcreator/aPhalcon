<?php

namespace Elcreator\aPhalcon;

use Phalcon\Db\Adapter\AdapterInterface;
use Phalcon\Db\Adapter\Pdo\Mysql;
use Phalcon\Db\Adapter\Pdo\Postgresql;
use Phalcon\Db\Adapter\Pdo\Sqlite;

/**
 * A Laravel connection array, as config('database.connections.*') holds it,
 * turned into what a Phalcon PDO adapter is constructed with.
 *
 * The two describe the same thing under different keys. Phalcon folds every
 * descriptor key it does not know into the DSN, so only the keys PDO would
 * take there are passed on; the rest of the Laravel array (strict, engine,
 * collation, prefix) means nothing to a connection and is dropped.
 */
final class DbConfig
{
    /** @var array<string, class-string<AdapterInterface>> */
    private const ADAPTERS = [
        'mysql' => Mysql::class,
        'mariadb' => Mysql::class,
        'pgsql' => Postgresql::class,
        'sqlite' => Sqlite::class,
    ];

    /**
     * @param  array<string, mixed> $connection
     * @return array{0: class-string<AdapterInterface>, 1: array<string, mixed>} adapter class and descriptor
     */
    public static function descriptor(array $connection): array
    {
        $driver = strtolower((string) ($connection['driver'] ?? ''));
        $class = self::ADAPTERS[$driver] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException(
                'aPhalcon has no Phalcon adapter for the "' . $driver . '" database driver'
            );
        }

        $descriptor = [];

        if ($driver === 'sqlite') {
            $descriptor['dbname'] = (string) ($connection['database'] ?? '');
        } else {
            $descriptor['host'] = (string) ($connection['host'] ?? '');
            $descriptor['dbname'] = (string) ($connection['database'] ?? '');
            $descriptor['username'] = (string) ($connection['username'] ?? '');
            $descriptor['password'] = (string) ($connection['password'] ?? '');

            if (!empty($connection['port'])) {
                $descriptor['port'] = (int) $connection['port'];
            }
            if (!empty($connection['charset'])) {
                $descriptor['charset'] = (string) $connection['charset'];
            }
            // A socket makes the host meaningless for mysql, and PDO takes it
            // straight from the DSN under this name.
            if ($driver !== 'pgsql' && !empty($connection['unix_socket'])) {
                $descriptor['unix_socket'] = (string) $connection['unix_socket'];
                unset($descriptor['host']);
            }
        }

        if (isset($connection['options']) && is_array($connection['options'])) {
            $descriptor['options'] = $connection['options'];
        }

        return [$class, $descriptor];
    }

    /** @param array<string, mixed> $connection */
    public static function adapter(array $connection): AdapterInterface
    {
        [$class, $descriptor] = self::descriptor($connection);

        return new $class($descriptor);
    }

    /** The prefix the CMS puts in front of every table, for a model's setSource(). */
    public static function prefix(array $connection): string
    {
        return (string) ($connection['prefix'] ?? '');
    }
}
