<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Cache;

use InvalidArgumentException;
use Iprodev\EasyCache\Compression\CompressorInterface;
use Iprodev\EasyCache\Lock\FileLockProvider;
use Iprodev\EasyCache\Lock\LockProviderInterface;
use Iprodev\EasyCache\Serialization\SerializerInterface;
use Iprodev\EasyCache\Storage\StorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Fluent builder for MultiTierCache.
 *
 *     $cache = MultiTierCache::builder()
 *         ->tier(new ApcuStorage('app:', maxTtl: 60))
 *         ->tier(new RedisStorage($redis, 'app:'))
 *         ->serializer(new NativeSerializer(allowedClasses: [UserDto::class]))
 *         ->signingKey($_ENV['EASYCACHE_SIGNING_KEY'])
 *         ->defaultTtl(600)
 *         ->build();
 */
final class CacheBuilder
{
    /** @var list<StorageInterface> */
    private array $tiers = [];
    private ?SerializerInterface $serializer = null;
    private ?CompressorInterface $compressor = null;
    private int $defaultTtl = 3600;
    private ?LoggerInterface $logger = null;
    private ?ClockInterface $clock = null;
    private ?string $signingKey = null;
    private ?LockProviderInterface $lockProvider = null;
    private float $lockTimeout = 30.0;
    private bool $allowUnsignedObjects = false;

    /**
     * Add a storage tier. Tiers are used in the order they are added (fastest first).
     */
    public function tier(StorageInterface $storage): self
    {
        $this->tiers[] = $storage;
        return $this;
    }

    public function tiers(StorageInterface ...$storages): self
    {
        foreach ($storages as $storage) {
            $this->tiers[] = $storage;
        }
        return $this;
    }

    public function serializer(SerializerInterface $serializer): self
    {
        $this->serializer = $serializer;
        return $this;
    }

    public function compressor(CompressorInterface $compressor): self
    {
        $this->compressor = $compressor;
        return $this;
    }

    /**
     * Default TTL in seconds (0 = never expire).
     */
    public function defaultTtl(int $seconds): self
    {
        $this->defaultTtl = $seconds;
        return $this;
    }

    public function logger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function clock(ClockInterface $clock): self
    {
        $this->clock = $clock;
        return $this;
    }

    /**
     * Secret of at least 16 bytes used to sign every entry with HMAC-SHA256.
     */
    public function signingKey(string $key): self
    {
        $this->signingKey = $key;
        return $this;
    }

    public function lockProvider(LockProviderInterface $provider): self
    {
        $this->lockProvider = $provider;
        return $this;
    }

    /**
     * Use file locks in the given directory.
     */
    public function lockPath(string $directory): self
    {
        $this->lockProvider = new FileLockProvider($directory);
        return $this;
    }

    /**
     * Seconds to wait for a lock on a cache miss before computing without it.
     */
    public function lockTimeout(float $seconds): self
    {
        $this->lockTimeout = $seconds;
        return $this;
    }

    /**
     * Allow a serializer that can create objects without a signing key.
     * Only safe when nobody else can write to any of the tiers.
     */
    public function allowUnsignedObjects(bool $allow = true): self
    {
        $this->allowUnsignedObjects = $allow;
        return $this;
    }

    public function build(): MultiTierCache
    {
        if ($this->tiers === []) {
            throw new InvalidArgumentException('Add at least one storage tier with tier() before build().');
        }

        return new MultiTierCache(
            $this->tiers,
            serializer: $this->serializer,
            compressor: $this->compressor,
            defaultTtl: $this->defaultTtl,
            logger: $this->logger,
            clock: $this->clock,
            signingKey: $this->signingKey,
            lockProvider: $this->lockProvider,
            lockTimeout: $this->lockTimeout,
            allowUnsignedObjects: $this->allowUnsignedObjects,
        );
    }
}
