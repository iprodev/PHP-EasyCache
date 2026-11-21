<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

use InvalidArgumentException;
use Iprodev\EasyCache\Clock\SystemClock;
use Iprodev\EasyCache\Support\LogsErrors;
use PDO;
use PDOStatement;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Stores entries in a SQL table (SQLite, MySQL/MariaDB, PostgreSQL; other
 * drivers use a portable delete+insert fallback).
 *
 * The PDO connection is used as-is: its error mode is not changed, both
 * exception and silent error modes are handled.
 */
final class PdoStorage implements PrunableStorageInterface, LoggerAwareInterface
{
    use LogsErrors;

    /** Keys longer than this are stored as a hash, so they fit the key column. */
    private const int MAX_KEY_LENGTH = 64;
    private const int CHUNK_SIZE = 500;

    private PDO $pdo;
    private string $table;
    private string $driver;
    private ClockInterface $clock;

    public function __construct(
        PDO $pdo,
        string $table = 'easycache',
        ?ClockInterface $clock = null,
        ?LoggerInterface $logger = null
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}(\.[A-Za-z_][A-Za-z0-9_]{0,62})?$/', $table) !== 1) {
            throw new InvalidArgumentException(
                "Invalid table name '{$table}': use letters, digits and underscores (optionally schema.table)"
            );
        }

        $this->pdo = $pdo;
        $this->table = $table;
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->driver = is_string($driver) ? $driver : '';
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger;
    }

    /**
     * Create the cache table if it doesn't exist.
     * Call this once during setup or deployment.
     */
    public function ensureTable(): void
    {
        $t = $this->table;
        $index = 'idx_' . str_replace('.', '_', $t) . '_expires';

        try {
            switch ($this->driver) {
                case 'sqlite':
                    $this->exec("CREATE TABLE IF NOT EXISTS {$t} (
                        k TEXT PRIMARY KEY,
                        payload BLOB NOT NULL,
                        expires_at INTEGER NOT NULL
                    )");
                    // SQLite puts the schema on the index name, not on the table.
                    $parts = explode('.', $t, 2);
                    [$sqliteIndex, $sqliteTable] = count($parts) === 2
                        ? [$parts[0] . '.' . $index, $parts[1]]
                        : [$index, $t];
                    $this->exec("CREATE INDEX IF NOT EXISTS {$sqliteIndex} ON {$sqliteTable}(expires_at)");
                    break;

                case 'mysql':
                    // VARBINARY keeps keys case-sensitive; LONGBLOB lifts the 64KB BLOB limit.
                    $this->exec("CREATE TABLE IF NOT EXISTS {$t} (
                        k VARBINARY(64) NOT NULL PRIMARY KEY,
                        payload LONGBLOB NOT NULL,
                        expires_at BIGINT NOT NULL,
                        INDEX {$index} (expires_at)
                    )");
                    break;

                case 'pgsql':
                    $this->exec("CREATE TABLE IF NOT EXISTS {$t} (
                        k VARCHAR(64) PRIMARY KEY,
                        payload BYTEA NOT NULL,
                        expires_at BIGINT NOT NULL
                    )");
                    $this->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$t}(expires_at)");
                    break;

                default:
                    $this->exec("CREATE TABLE {$t} (
                        k VARCHAR(64) NOT NULL PRIMARY KEY,
                        payload BLOB NOT NULL,
                        expires_at BIGINT NOT NULL
                    )");
                    break;
            }
        } catch (Throwable $e) {
            if ($this->driver !== 'sqlite' && $this->driver !== 'mysql' && $this->driver !== 'pgsql') {
                // Generic drivers have no IF NOT EXISTS; the table probably exists already.
                return;
            }
            throw new RuntimeException('Failed to create cache table: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Upgrade a MySQL table created by EasyCache < 3.1 (BLOB payload limited to
     * 64KB and case-insensitive VARCHAR key). No-op for other drivers.
     */
    public function upgradeSchema(): void
    {
        if ($this->driver !== 'mysql') {
            return;
        }

        try {
            $this->exec("ALTER TABLE {$this->table}
                MODIFY k VARBINARY(64) NOT NULL,
                MODIFY payload LONGBLOB NOT NULL");
        } catch (Throwable $e) {
            throw new RuntimeException('Failed to upgrade cache table: ' . $e->getMessage(), 0, $e);
        }
    }

    public function get(string $key): ?string
    {
        try {
            $stmt = $this->run(
                "SELECT payload, expires_at FROM {$this->table} WHERE k = :k",
                [':k' => [$this->storageKey($key), PDO::PARAM_STR]]
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if (!is_array($row)) {
                return null;
            }

            $expiresAt = self::toInt($row['expires_at'] ?? 0);
            if ($expiresAt > 0 && $expiresAt <= $this->now()) {
                $this->deleteIfExpired($key);
                return null;
            }

            return $this->readPayload($row['payload']);
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: PDO get failed for key {key}', ['key' => $key], $e);
            return null;
        }
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        try {
            $this->upsert($this->storageKey($key), $payload, $this->expiresAt($ttl));
            return true;
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: PDO set failed for key {key}', ['key' => $key], $e);
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->run("DELETE FROM {$this->table} WHERE k = :k", [':k' => [$this->storageKey($key), PDO::PARAM_STR]]);
            return true;
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: PDO delete failed for key {key}', ['key' => $key], $e);
            return false;
        }
    }

    public function has(string $key): bool
    {
        try {
            $stmt = $this->run(
                "SELECT 1 FROM {$this->table} WHERE k = :k AND (expires_at = 0 OR expires_at > :now)",
                [':k' => [$this->storageKey($key), PDO::PARAM_STR], ':now' => [$this->now(), PDO::PARAM_INT]]
            );
            $found = $stmt->fetchColumn() !== false;
            $stmt->closeCursor();
            return $found;
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: PDO has failed for key {key}', ['key' => $key], $e);
            return false;
        }
    }

    public function getMany(array $keys): array
    {
        $result = [];
        if ($keys === []) {
            return $result;
        }

        $map = [];
        foreach ($keys as $key) {
            $map[$this->storageKey($key)] = $key;
        }

        try {
            $now = $this->now();
            foreach (array_chunk(array_keys($map), self::CHUNK_SIZE) as $chunk) {
                $params = [];
                $placeholders = [];
                foreach ($chunk as $i => $storageKey) {
                    $placeholders[] = ":k{$i}";
                    $params[":k{$i}"] = [(string) $storageKey, PDO::PARAM_STR];
                }

                $in = implode(', ', $placeholders);
                $stmt = $this->run("SELECT k, payload, expires_at FROM {$this->table} WHERE k IN ({$in})", $params);

                while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if (!is_array($row)) {
                        break;
                    }
                    $storageKey = $this->readPayload($row['k']);
                    $expiresAt = self::toInt($row['expires_at'] ?? 0);
                    if ($storageKey === null || !isset($map[$storageKey]) || ($expiresAt > 0 && $expiresAt <= $now)) {
                        continue;
                    }
                    $payload = $this->readPayload($row['payload']);
                    if ($payload !== null) {
                        $result[$map[$storageKey]] = $payload;
                    }
                }
                $stmt->closeCursor();
            }
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: PDO getMany failed', ['count' => count($keys)], $e);
        }

        return $result;
    }

    public function setMany(array $payloads, int $ttl): bool
    {
        if ($payloads === []) {
            return true;
        }

        $expires = $this->expiresAt($ttl);
        $ownTransaction = !$this->pdo->inTransaction();

        try {
            if ($ownTransaction) {
                $this->pdo->beginTransaction();
            }
            foreach ($payloads as $key => $payload) {
                $this->upsert($this->storageKey((string) $key), $payload, $expires, false);
            }
            if ($ownTransaction) {
                $this->pdo->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->logWarning('EasyCache: PDO setMany failed', ['count' => count($payloads)], $e);
            return false;
        }
    }

    public function deleteMany(array $keys): bool
    {
        try {
            foreach (array_chunk($keys, self::CHUNK_SIZE) as $chunk) {
                $params = [];
                $placeholders = [];
                foreach ($chunk as $i => $key) {
                    $placeholders[] = ":k{$i}";
                    $params[":k{$i}"] = [$this->storageKey($key), PDO::PARAM_STR];
                }
                $this->run("DELETE FROM {$this->table} WHERE k IN (" . implode(', ', $placeholders) . ')', $params);
            }
            return true;
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: PDO deleteMany failed', ['count' => count($keys)], $e);
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $this->exec("DELETE FROM {$this->table}");
            return true;
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: PDO clear failed', [], $e);
            return false;
        }
    }

    public function prune(): int
    {
        try {
            $stmt = $this->run(
                "DELETE FROM {$this->table} WHERE expires_at > 0 AND expires_at <= :now",
                [':now' => [$this->now(), PDO::PARAM_INT]]
            );
            return $stmt->rowCount();
        } catch (Throwable $e) {
            $this->logWarning('EasyCache: PDO prune failed', [], $e);
            return 0;
        }
    }

    private function upsert(string $storageKey, string $payload, int $expires, bool $atomic = true): void
    {
        $params = [
            ':k' => [$storageKey, PDO::PARAM_STR],
            // PARAM_LOB sends the payload as binary data. As a plain string
            // parameter PostgreSQL would cut it at the first NUL byte.
            ':p' => [$payload, PDO::PARAM_LOB],
            ':e' => [$expires, PDO::PARAM_INT],
        ];

        switch ($this->driver) {
            case 'mysql':
                $this->run(
                    "INSERT INTO {$this->table} (k, payload, expires_at) VALUES (:k, :p, :e)
                     ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)",
                    $params
                );
                return;

            case 'pgsql':
            case 'sqlite':
                $this->run(
                    "INSERT INTO {$this->table} (k, payload, expires_at) VALUES (:k, :p, :e)
                     ON CONFLICT (k) DO UPDATE SET payload = excluded.payload, expires_at = excluded.expires_at",
                    $params
                );
                return;

            default:
                $ownTransaction = $atomic && !$this->pdo->inTransaction();
                if ($ownTransaction) {
                    $this->pdo->beginTransaction();
                }
                try {
                    $this->run("DELETE FROM {$this->table} WHERE k = :k", [':k' => $params[':k']]);
                    $this->run("INSERT INTO {$this->table} (k, payload, expires_at) VALUES (:k, :p, :e)", $params);
                    if ($ownTransaction) {
                        $this->pdo->commit();
                    }
                } catch (Throwable $e) {
                    if ($ownTransaction && $this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }
        }
    }

    private function deleteIfExpired(string $key): void
    {
        try {
            // Only delete the row if it is still expired (it may have been refreshed meanwhile).
            $this->run(
                "DELETE FROM {$this->table} WHERE k = :k AND expires_at > 0 AND expires_at <= :now",
                [':k' => [$this->storageKey($key), PDO::PARAM_STR], ':now' => [$this->now(), PDO::PARAM_INT]]
            );
        } catch (Throwable) {
            // Best effort; prune() removes it later.
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: int}> $params
     */
    private function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $this->errorMessage($this->pdo->errorInfo()));
        }

        foreach ($params as $name => [$value, $type]) {
            $stmt->bindValue($name, $value, $type);
        }

        if ($stmt->execute() === false) {
            throw new RuntimeException('Failed to execute statement: ' . $this->errorMessage($stmt->errorInfo()));
        }

        return $stmt;
    }

    private function exec(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new RuntimeException('Failed to execute statement: ' . $this->errorMessage($this->pdo->errorInfo()));
        }
    }

    /**
     * @param array<mixed> $info
     */
    private function errorMessage(array $info): string
    {
        $parts = [];
        foreach ($info as $part) {
            if (is_scalar($part)) {
                $parts[] = (string) $part;
            }
        }

        return trim(implode(' ', $parts));
    }

    private static function toInt(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private function readPayload(mixed $value): ?string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return is_string($value) ? $value : null;
    }

    private function storageKey(string $key): string
    {
        if (strlen($key) <= self::MAX_KEY_LENGTH) {
            return $key;
        }

        // ':' never appears in a valid cache key, so hashed keys cannot collide with real ones.
        return ':' . substr(hash('sha256', $key), 0, self::MAX_KEY_LENGTH - 1);
    }

    private function expiresAt(int $ttl): int
    {
        return $ttl > 0 ? $this->now() + $ttl : 0;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
