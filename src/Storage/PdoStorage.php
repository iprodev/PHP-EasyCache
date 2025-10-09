<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

use PDO;

final class PdoStorage implements StorageInterface
{
    private PDO $pdo;
    private string $table;

    public function __construct(PDO $pdo, string $table = 'easycache')
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->table = $table;
    }

    public function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (k TEXT PRIMARY KEY, payload BLOB NOT NULL, expires_at INTEGER NOT NULL)";
            $this->pdo->exec($sql);
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$this->table}_expires ON {$this->table}(expires_at)");
            return;
        }
        $payloadType = ($driver === 'pgsql') ? 'BYTEA' : 'BLOB';
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            k VARCHAR(64) PRIMARY KEY,
            payload {$payloadType} NOT NULL,
            expires_at BIGINT NOT NULL
        )";
        $this->pdo->exec($sql);
        // index for expiry lookups
        try { $this->pdo->exec("CREATE INDEX idx_{$this->table}_expires ON {$this->table}(expires_at)"); } catch (\Throwable $e) {}
    }

    public function get(string $key): ?string
    {
        $stmt = $this->pdo->prepare("SELECT payload, expires_at FROM {$this->table} WHERE k = :k");
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ((int)$row['expires_at'] > 0 && (int)$row['expires_at'] < time()) {
            $this->delete($key);
            return null;
        }
        $payload = $row['payload'];
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload) ?: '';
        }
        return is_string($payload) ? $payload : null;
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        $expires = $ttl > 0 ? (time() + $ttl) : 0;
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $sql = "INSERT INTO {$this->table}(k, payload, expires_at) VALUES(:k, :p, :e)
                    ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)";
        } else {
            $sql = "INSERT INTO {$this->table}(k, payload, expires_at) VALUES(:k, :p, :e)
                    ON CONFLICT(k) DO UPDATE SET payload = excluded.payload, expires_at = excluded.expires_at";
        }
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':k' => $key, ':p' => $payload, ':e' => $expires]);
    }

    public function delete(string $key): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE k = :k");
        $stmt->execute([':k' => $key]);
        return $stmt->rowCount() > 0;
    }

    public function has(string $key): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->table} WHERE k = :k");
        $stmt->execute([':k' => $key]);
        return $stmt->fetchColumn() !== false;
    }

    public function clear(): bool
    {
        $this->pdo->exec("DELETE FROM {$this->table}");
        return true;
    }

    public function prune(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires_at > 0 AND expires_at < :now");
        $stmt->execute([':now' => time()]);
        return $stmt->rowCount();
    }
}
