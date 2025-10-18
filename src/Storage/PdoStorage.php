<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

use PDO;
use PDOException;

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

    /**
     * Create the cache table if it doesn't exist.
     * Call this once during setup or deployment.
     */
    public function ensureTable(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        try {
            if ($driver === 'sqlite') {
                $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
                    k TEXT PRIMARY KEY, 
                    payload BLOB NOT NULL, 
                    expires_at INTEGER NOT NULL
                )";
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
            
            // Create index for expiry lookups
            try { 
                $this->pdo->exec("CREATE INDEX idx_{$this->table}_expires ON {$this->table}(expires_at)"); 
            } catch (PDOException $e) {
                // Index might already exist, ignore
            }
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to create cache table: " . $e->getMessage(), 0, $e);
        }
    }

    public function get(string $key): ?string
    {
        try {
            $stmt = $this->pdo->prepare("SELECT payload, expires_at FROM {$this->table} WHERE k = :k");
            $stmt->execute([':k' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                return null;
            }
            
            // Check expiration
            $expiresAt = (int)$row['expires_at'];
            if ($expiresAt > 0 && $expiresAt < time()) {
                $this->delete($key);
                return null;
            }
            
            $payload = $row['payload'];
            
            // Handle BLOB data for PostgreSQL
            if (is_resource($payload)) {
                $payload = stream_get_contents($payload);
                if ($payload === false) {
                    error_log("Failed to read BLOB data for key: {$key}");
                    return null;
                }
            }
            
            return is_string($payload) ? $payload : null;
        } catch (PDOException $e) {
            error_log("PDO get failed for key {$key}: " . $e->getMessage());
            return null;
        }
    }

    public function set(string $key, string $payload, int $ttl): bool
    {
        try {
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
        } catch (PDOException $e) {
            error_log("PDO set failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE k = :k");
            $stmt->execute([':k' => $key]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("PDO delete failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function has(string $key): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM {$this->table} WHERE k = :k AND (expires_at = 0 OR expires_at >= :now)"
            );
            $stmt->execute([':k' => $key, ':now' => time()]);
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            error_log("PDO has failed for key {$key}: " . $e->getMessage());
            return false;
        }
    }

    public function clear(): bool
    {
        try {
            $this->pdo->exec("DELETE FROM {$this->table}");
            return true;
        } catch (PDOException $e) {
            error_log("PDO clear failed: " . $e->getMessage());
            return false;
        }
    }

    public function prune(): int
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE expires_at > 0 AND expires_at < :now");
            $stmt->execute([':now' => time()]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("PDO prune failed: " . $e->getMessage());
            return 0;
        }
    }
}
