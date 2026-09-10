<?php
declare(strict_types=1);

namespace CoNotes;

use PDO;

/**
 * PDO singleton plus a few query shorthands.
 * Every statement is prepared; nothing in this app concatenates user input
 * into SQL.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Env::get('DB_HOST', 'localhost');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::require('DB_NAME');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        self::$pdo = new PDO($dsn, Env::require('DB_USER'), Env::get('DB_PASS', '') ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // All timestamps are stored and compared in UTC.
        self::$pdo->exec("SET time_zone = '+00:00'");

        return self::$pdo;
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        /** @var list<array<string,mixed>> */
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<string|int,mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function begin(): void
    {
        if (!self::conn()->inTransaction()) {
            self::conn()->beginTransaction();
        }
    }

    public static function commit(): void
    {
        if (self::conn()->inTransaction()) {
            self::conn()->commit();
        }
    }

    public static function rollback(): void
    {
        if (self::conn()->inTransaction()) {
            self::conn()->rollBack();
        }
    }

    /** Current UTC time in the format the DATETIME columns expect. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** DATETIME string -> ISO 8601 UTC, which is what the API speaks. */
    public static function iso(?string $datetime): ?string
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        return str_replace(' ', 'T', $datetime) . 'Z';
    }
}
