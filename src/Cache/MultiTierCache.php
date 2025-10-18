<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Cache;

use DateInterval;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Iprodev\EasyCache\Storage\StorageInterface;
use Iprodev\EasyCache\Serialization\SerializerInterface;
use Iprodev\EasyCache\Compression\CompressorInterface;
use Iprodev\EasyCache\Compression\NullCompressor;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Exceptions\InvalidArgument;
use Iprodev\EasyCache\Util\KeyValidator;
use Iprodev\EasyCache\Util\Lock;

final class MultiTierCache implements CacheInterface
{
    /** @var StorageInterface[] */
    private array $tiers;
    private SerializerInterface $serializer;
    private CompressorInterface $compressor;
    private int $defaultTtl;
    private ?LoggerInterface $logger;
    private string $lockPath;

    private const MAGIC = "EC02";
    private const FLAG_NONE = 0;
    private const FLAG_GZIP = 1;
    private const FLAG_ZSTD = 2;

    /**
     * @param StorageInterface[] $tiers read/write order; tier[0] is primary
     * @param SerializerInterface|null $serializer
     * @param CompressorInterface|null $compressor
     * @param int $defaultTtl default TTL in seconds
     * @param LoggerInterface|null $logger
     * @param string|null $lockPath
     */
    public function __construct(
        array $tiers, 
        ?SerializerInterface $serializer = null, 
        ?CompressorInterface $compressor = null, 
        int $defaultTtl = 3600, 
        ?LoggerInterface $logger = null, 
        ?string $lockPath = null
    ) {
        if (empty($tiers)) {
            throw new \InvalidArgumentException('At least one storage tier is required.');
        }
        
        $this->tiers = $tiers;
        $this->serializer = $serializer ?? new NativeSerializer();
        $this->compressor = $compressor ?? new NullCompressor();
        $this->defaultTtl = max(0, $defaultTtl);
        $this->logger = $logger;
        $this->lockPath = $lockPath ?? sys_get_temp_dir() . '/ec-locks';
        
        if (!is_dir($this->lockPath)) { 
            if (!mkdir($this->lockPath, 0770, true) && !is_dir($this->lockPath)) {
                throw new \RuntimeException("Failed to create lock directory: {$this->lockPath}");
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        KeyValidator::assert($key);
        
        try {
            $now = time();
            $hit = $this->readThrough($key, $now, false);
            return $hit['value'] ?? $default;
        } catch (\Throwable $e) {
            $this->log('warning', "Cache get failed for key '{$key}': " . $e->getMessage());
            return $default;
        }
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        KeyValidator::assert($key);
        
        try {
            $seconds = $this->normalizeTtl($ttl);
            
            if ($seconds <= 0 && $ttl !== null) { 
                return $this->delete($key); 
            }
            
            $expires = $seconds === 0 ? 0 : (time() + ($seconds > 0 ? $seconds : $this->defaultTtl));
            $payload = $this->encodeRecord($value, $expires, 0, 0);
            
            return $this->writeAllTiers($key, $payload, $this->visibleTtl($expires, 0, 0));
        } catch (\Throwable $e) {
            $this->log('error', "Cache set failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    public function delete(string $key): bool
    {
        KeyValidator::assert($key);
        
        $ok = true;
        foreach ($this->tiers as $t) { 
            try {
                $ok = $t->delete($key) && $ok; 
            } catch (\Throwable $e) {
                $this->log('warning', "Delete failed on tier for key '{$key}': " . $e->getMessage());
                $ok = false;
            }
        }
        return $ok;
    }

    public function clear(): bool
    {
        $ok = true;
        foreach ($this->tiers as $t) { 
            try {
                $ok = $t->clear() && $ok; 
            } catch (\Throwable $e) {
                $this->log('warning', "Clear failed on tier: " . $e->getMessage());
                $ok = false;
            }
        }
        return $ok;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        if (!is_iterable($keys)) { 
            throw new InvalidArgument('Keys must be iterable'); 
        }
        
        $result = [];
        foreach ($keys as $k) { 
            $result[$k] = $this->get((string)$k, $default); 
        }
        return $result;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        if (!is_iterable($values)) { 
            throw new InvalidArgument('Values must be iterable'); 
        }
        
        $all = true;
        foreach ($values as $k => $v) { 
            $all = $this->set((string)$k, $v, $ttl) && $all; 
        }
        return $all;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        if (!is_iterable($keys)) { 
            throw new InvalidArgument('Keys must be iterable'); 
        }
        
        $all = true;
        foreach ($keys as $k) { 
            $all = $this->delete((string)$k) && $all; 
        }
        return $all;
    }

    public function has(string $key): bool
    {
        KeyValidator::assert($key);
        
        try {
            $now = time();
            $hit = $this->readThrough($key, $now, false, false);
            return $hit['value'] !== null;
        } catch (\Throwable $e) {
            $this->log('warning', "Cache has check failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get or set with Stale-While-Revalidate support.
     * 
     * @param string $key Cache key
     * @param callable $producer Function to produce fresh value: function(): mixed
     * @param null|int|DateInterval $ttl Fresh data TTL
     * @param int $swrSeconds Stale-while-revalidate window in seconds
     * @param int $staleIfErrorSeconds Stale-if-error window in seconds
     * @param array{mode?: string} $options Options: 'mode' => 'sync'|'defer'
     * @return mixed
     */
    public function getOrSetSWR(
        string $key, 
        callable $producer, 
        null|int|DateInterval $ttl = null, 
        int $swrSeconds = 0, 
        int $staleIfErrorSeconds = 0, 
        array $options = []
    ): mixed {
        KeyValidator::assert($key);
        
        $now = time();
        $seconds = $this->normalizeTtl($ttl);
        $freshTtl = $seconds > 0 ? $seconds : $this->defaultTtl;

        try {
            $hit = $this->readThrough($key, $now, true);
            
            if ($hit['value'] !== null) {
                // Trigger background revalidation if in SWR window
                if ($hit['expired'] && $hit['within_swr']) {
                    $this->maybeRevalidate($key, $producer, $freshTtl, $swrSeconds, $staleIfErrorSeconds, $options);
                }
                return $hit['value'];
            }
        } catch (\Throwable $e) {
            $this->log('warning', "SWR cache read failed for key '{$key}': " . $e->getMessage());
        }

        // Cache miss: compute under per-key lock
        $lock = $this->lockFor($key);
        if (!$lock->acquire(true)) { 
            $this->log('warning', "Failed to acquire lock for key '{$key}', computing without lock");
            return $producer(); 
        }
        
        try {
            // Double-check after acquiring lock
            $hit2 = $this->readThrough($key, time(), true);
            if ($hit2['value'] !== null) { 
                return $hit2['value']; 
            }
            
            // Compute fresh value
            try {
                $val = $producer();
                $expires = time() + $freshTtl;
                $payload = $this->encodeRecord($val, $expires, $swrSeconds, $staleIfErrorSeconds);
                $this->writeAllTiers($key, $payload, $this->visibleTtl($expires, $swrSeconds, $staleIfErrorSeconds));
                return $val;
            } catch (\Throwable $e) {
                $this->log('error', "Producer failed for key '{$key}': " . $e->getMessage());
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Prune expired items across tiers.
     * 
     * @return int Number of items pruned
     */
    public function prune(): int
    {
        $sum = 0;
        foreach ($this->tiers as $t) {
            if (method_exists($t, 'prune')) {
                try { 
                    $count = (int)$t->prune();
                    $sum += $count;
                } catch (\Throwable $e) {
                    $this->log('warning', "Prune failed on tier: " . $e->getMessage());
                }
            }
        }
        return $sum;
    }

    private function maybeRevalidate(
        string $key, 
        callable $producer, 
        int $freshTtl, 
        int $swrSeconds, 
        int $staleIfErrorSeconds, 
        array $options
    ): void {
        $mode = $options['mode'] ?? 'sync';
        
        $revalidate = function () use ($key, $producer, $freshTtl, $swrSeconds, $staleIfErrorSeconds): void {
            $lock = $this->lockFor($key);
            
            // Non-blocking lock: if another process is already revalidating, skip
            if (!$lock->acquire(false)) { 
                return; 
            }
            
            try {
                $val = $producer();
                $expires = time() + $freshTtl;
                $payload = $this->encodeRecord($val, $expires, $swrSeconds, $staleIfErrorSeconds);
                $this->writeAllTiers($key, $payload, $this->visibleTtl($expires, $swrSeconds, $staleIfErrorSeconds));
            } catch (\Throwable $e) {
                $this->log('warning', "SWR refresh failed for key '{$key}': " . $e->getMessage());
            } finally {
                $lock->release();
            }
        };

        // Defer mode: run after response is sent
        if ($mode === 'defer' && function_exists('fastcgi_finish_request')) {
            register_shutdown_function(function () use ($revalidate) {
                try { 
                    if (function_exists('fastcgi_finish_request')) { 
                        @fastcgi_finish_request(); 
                    } 
                } catch (\Throwable $e) {
                    // Ignore fastcgi_finish_request errors
                }
                $revalidate();
            });
        } else {
            // Sync mode: run immediately
            $revalidate();
        }
    }

    private function readThrough(string $key, int $now, bool $allowStale, bool $backfill = true): array
    {
        $record = null; 
        $tierIndex = -1;
        
        // Read from tiers in order
        foreach ($this->tiers as $i => $tier) {
            try {
                $raw = $tier->get($key);
                if ($raw === null) {
                    continue;
                }
                
                $rec = $this->decodeRecord($raw);
                if ($rec === null) {
                    continue;
                }
                
                $record = $rec; 
                $tierIndex = $i; 
                break;
            } catch (\Throwable $e) {
                $this->log('warning', "Read failed on tier {$i} for key '{$key}': " . $e->getMessage());
            }
        }
        
        if ($record === null) {
            return ['value' => null];
        }

        $expired = ($record['e'] > 0 && $record['e'] < $now);
        $within_swr = $expired && ($record['swr'] > 0) && ($now < $record['e'] + $record['swr']);
        $within_sei = $expired && ($record['sei'] > 0) && ($now < $record['e'] + $record['sei']);

        // Return null if expired and stale is not allowed
        if ($expired && !$allowStale) {
            return ['value' => null];
        }
        
        // Return null if expired and outside SWR/SEI windows
        if ($expired && $allowStale && !($within_swr || $within_sei)) {
            return ['value' => null];
        }

        // Backfill to faster tiers
        if ($backfill && $tierIndex > 0) {
            $ttl = $this->visibleTtl($record['e'], $record['swr'], $record['sei']);
            if ($ttl > 0) {
                $payload = $this->encodeRecord($record['v'], $record['e'], $record['swr'], $record['sei']);
                for ($j = 0; $j < $tierIndex; $j++) {
                    try {
                        $this->tiers[$j]->set($key, $payload, $ttl);
                    } catch (\Throwable $e) {
                        $this->log('warning', "Backfill failed on tier {$j}: " . $e->getMessage());
                    }
                }
            }
        }

        return [
            'value' => $record['v'],
            'expired' => $expired,
            'within_swr' => $within_swr,
            'within_sei' => $within_sei,
        ];
    }

    private function writeAllTiers(string $key, string $payload, int $ttl): bool
    {
        $ok = true;
        foreach ($this->tiers as $tier) { 
            try {
                $ok = $tier->set($key, $payload, $ttl) && $ok; 
            } catch (\Throwable $e) {
                $this->log('warning', "Write failed on tier for key '{$key}': " . $e->getMessage());
                $ok = false;
            }
        }
        return $ok;
    }

    private function visibleTtl(int $expires, int $swr, int $sei): int
    {
        if ($expires === 0) {
            return 0;
        }
        
        $maxExtra = max($swr, $sei);
        $ttlUntil = $expires + $maxExtra - time();
        return $ttlUntil > 0 ? $ttlUntil : 1;
    }

    private function encodeRecord(mixed $value, int $expires, int $swrSeconds, int $seiSeconds): string
    {
        try {
            $val = $this->serializer->serialize($value);
            $tuple = serialize([
                'e' => $expires,
                'swr' => $swrSeconds,
                'sei' => $seiSeconds,
                'v' => $val,
                'sn' => $this->serializer->name()
            ]);
            
            $flag = chr(self::FLAG_NONE);
            $data = $tuple;
            $compName = $this->compressor->name();
            
            if ($compName === 'gzip') { 
                $flag = chr(self::FLAG_GZIP); 
                $data = $this->compressor->compress($tuple); 
            } elseif ($compName === 'zstd') { 
                $flag = chr(self::FLAG_ZSTD); 
                $data = $this->compressor->compress($tuple); 
            }
            
            $sn = $this->serializer->name();
            $snlen = strlen($sn);
            
            if ($snlen > 255) { 
                throw new \RuntimeException('Serializer name too long (max 255 chars)'); 
            }
            
            return self::MAGIC . $flag . chr($snlen) . $sn . $data;
        } catch (\Throwable $e) {
            throw new \RuntimeException("Failed to encode cache record: " . $e->getMessage(), 0, $e);
        }
    }

    private function decodeRecord(string $raw): ?array
    {
        try {
            if (strlen($raw) < 6) {
                return null;
            }
            
            $magic = substr($raw, 0, 4);
            if ($magic !== self::MAGIC) {
                return null;
            }
            
            $flagByte = ord($raw[4]);
            $snlen = ord($raw[5]);
            $offset = 6 + $snlen;
            
            if (strlen($raw) < $offset) {
                return null;
            }
            
            $storedSerializer = substr($raw, 6, $snlen);
            $payload = substr($raw, $offset);
            
            // Decompress if needed
            if ($flagByte === self::FLAG_GZIP || $flagByte === self::FLAG_ZSTD) {
                try { 
                    $payload = $this->compressor->decompress($payload); 
                } catch (\Throwable $e) { 
                    $this->log('warning', "Decompression failed: " . $e->getMessage());
                    return null; 
                }
            }
            
            // Unserialize metadata
            $arr = @unserialize($payload, ['allowed_classes' => true]);
            if (!is_array($arr) || !isset($arr['e'], $arr['v'], $arr['sn'])) {
                return null;
            }
            
            // Deserialize value with appropriate serializer
            if ($arr['sn'] !== $this->serializer->name()) {
                $this->log('info', "Serializer mismatch: stored={$arr['sn']}, current={$this->serializer->name()}");
                // Try to deserialize with current serializer anyway
            }
            
            try {
                $v = $this->serializer->deserialize($arr['v']);
            } catch (\Throwable $e) {
                $this->log('warning', "Deserialization failed: " . $e->getMessage());
                return null;
            }
            
            return [
                'e' => (int)$arr['e'],
                'swr' => (int)($arr['swr'] ?? 0),
                'sei' => (int)($arr['sei'] ?? 0),
                'v' => $v
            ];
        } catch (\Throwable $e) {
            $this->log('warning', "Failed to decode cache record: " . $e->getMessage());
            return null;
        }
    }

    private function normalizeTtl(null|int|DateInterval $ttl): int
    {
        if ($ttl === null) {
            return $this->defaultTtl;
        }
        
        if ($ttl instanceof DateInterval) {
            return max(0, (int)(new \DateTimeImmutable())->add($ttl)->format('U') - time());
        }
        
        return (int)$ttl;
    }

    private function lockFor(string $key): Lock
    {
        $hash = md5($key);
        $dir = $this->lockPath . '/' . substr($hash, 0, 2);
        
        if (!is_dir($dir)) { 
            if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
                error_log("Failed to create lock subdirectory: {$dir}");
            }
        }
        
        return new Lock($dir . '/' . $hash . '.lock');
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message);
        }
    }
}
