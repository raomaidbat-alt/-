<?php
declare(strict_types=1);

namespace App;

use PDO;

/**
 * Тонкая обёртка над PDO: одно подключение, UTC, и те немногие места,
 * где MySQL и PostgreSQL расходятся в синтаксисе (upsert, дата в поясе).
 */
final class Db
{
    public readonly PDO $pdo;
    public readonly string $driver; // mysql | pgsql

    /**
     * @param string|null $sslCa путь к CA-сертификату сервера БД для TLS (MySQL).
     *        Для PostgreSQL TLS включается в DSN: ";sslmode=verify-full;sslrootcert=/path/ca.pem".
     */
    public function __construct(string $dsn, ?string $user, ?string $password, ?string $sslCa = null)
    {
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($sslCa !== null && $sslCa !== '' && str_starts_with($dsn, 'mysql:')) {
            $opts[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        $this->pdo = new PDO($dsn, $user, $password, $opts);
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($this->driver, ['mysql', 'pgsql'], true)) {
            throw new \RuntimeException("Unsupported PDO driver: {$this->driver}");
        }
        if ($this->driver === 'mysql') {
            $this->pdo->exec("SET time_zone = '+00:00', NAMES utf8mb4");
        } else {
            $this->pdo->exec("SET TIME ZONE 'UTC'");
        }
    }

    public static function fromConfig(array $cfg): self
    {
        return new self($cfg['dsn'], $cfg['user'] ?? null, $cfg['password'] ?? null, $cfg['ssl_ca'] ?? null);
    }

    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public function exec(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function insertGetId(string $sql, array $params, string $pk = 'id'): int
    {
        if ($this->driver === 'pgsql') {
            $row = $this->one($sql . ' RETURNING ' . $pk, $params);
            return (int) $row[$pk];
        }
        $this->exec($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Транзакция с автоматическим откатом при исключении. Повторный вызов внутри
     * открытой транзакции не открывает новую.
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        // Вложенный вызов работает внутри уже открытой транзакции.
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Многострочный INSERT ... ON DUPLICATE KEY / ON CONFLICT DO UPDATE.
     * Строки режутся на пачки, чтобы не упереться в лимит плейсхолдеров.
     *
     * @param list<array<string,mixed>> $rows все строки с одинаковым набором колонок
     * @param list<string> $conflictKeys колонки первичного/уникального ключа
     * @param list<string>|null $updateColumns что обновлять при конфликте (null = всё, кроме ключа)
     */
    public function upsert(string $table, array $rows, array $conflictKeys, ?array $updateColumns = null): int
    {
        if (!$rows) {
            return 0;
        }
        $columns = array_keys($rows[0]);
        $updateColumns ??= array_values(array_diff($columns, $conflictKeys));
        $affected = 0;
        $chunkSize = max(1, intdiv(20000, count($columns)));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
                foreach ($columns as $c) {
                    $params[] = $row[$c];
                }
            }
            $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES ' . implode(',', $placeholders);
            if ($this->driver === 'mysql') {
                $sets = array_map(static fn ($c) => "{$c} = new_row.{$c}", $updateColumns);
                $sql .= ' AS new_row ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
            } else {
                $sets = array_map(static fn ($c) => "{$c} = EXCLUDED.{$c}", $updateColumns);
                $sql .= ' ON CONFLICT (' . implode(',', $conflictKeys) . ') DO UPDATE SET ' . implode(', ', $sets);
            }
            $affected += $this->exec($sql, $params);
        }
        return $affected;
    }

    /** Многострочный INSERT без обновления. */
    public function insertMany(string $table, array $rows): int
    {
        if (!$rows) {
            return 0;
        }
        $columns = array_keys($rows[0]);
        $chunkSize = max(1, intdiv(20000, count($columns)));
        $n = 0;
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
                foreach ($columns as $c) {
                    $params[] = $row[$c];
                }
            }
            $n += $this->exec(
                'INSERT INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES ' . implode(',', $placeholders),
                $params
            );
        }
        return $n;
    }

    /** "IN (?,?,?)" и массив параметров. */
    public static function inList(array $values): array
    {
        $values = array_values($values);
        return ['(' . implode(',', array_fill(0, max(1, count($values)), '?')) . ')', $values ?: [null]];
    }

    /** SQL-выражение: локальная дата для UTC-колонки при фиксированном смещении в минутах. */
    public function localDateExpr(string $column, int $offsetMinutes): string
    {
        $offsetMinutes = (int) $offsetMinutes;
        return $this->driver === 'mysql'
            ? "DATE(DATE_ADD({$column}, INTERVAL {$offsetMinutes} MINUTE))"
            : "CAST({$column} + INTERVAL '{$offsetMinutes} minutes' AS DATE)";
    }

    /**
     * Разбивает SQL-файл на отдельные запросы. Учитывает строки в кавычках,
     * комментарии и тела функций PostgreSQL в $$ ... $$.
     * @return list<string>
     */
    public static function splitSql(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        $inSingle = false;
        $dollarTag = null;
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($dollarTag === null && !$inSingle && $ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                $buf .= "\n";
                continue;
            }
            if ($dollarTag === null && $ch === "'") {
                $inSingle = !$inSingle;
            } elseif (!$inSingle && $ch === '$' && preg_match('/\G\$[A-Za-z_]*\$/', $sql, $m, 0, $i)) {
                if ($dollarTag === null) {
                    $dollarTag = $m[0];
                } elseif ($m[0] === $dollarTag) {
                    $dollarTag = null;
                }
                $buf .= $m[0];
                $i += strlen($m[0]) - 1;
                continue;
            } elseif (!$inSingle && $dollarTag === null && $ch === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
