<?php

declare(strict_types=1);

namespace App\Session;

use Doctrine\DBAL\Connection;

/**
 * Builds a dedicated PDO for {@see \Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler}.
 *
 * The session handler must NOT share Doctrine's connection: it opens its
 * own transaction to lock the session row, which collides with whatever
 * transaction the request's ORM work is in ("There is already an active
 * transaction"). Symfony itself recommends a separate connection. We
 * derive one from the app's existing DBAL credentials so there's nothing
 * extra to configure — same database, independent connection.
 */
final class SessionPdoFactory
{
    public static function create(Connection $connection): \PDO
    {
        $params = $connection->getParams();

        $host = is_string($params['host'] ?? null) ? $params['host'] : 'database';
        $port = is_int($params['port'] ?? null) ? $params['port'] : 5432;
        $dbname = is_string($params['dbname'] ?? null) ? $params['dbname'] : 'app';
        $user = is_string($params['user'] ?? null) ? $params['user'] : null;
        $password = is_string($params['password'] ?? null) ? $params['password'] : null;

        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $host, $port, $dbname);

        return new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
