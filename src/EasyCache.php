<?php

declare(strict_types=1);

namespace Iprodev\EasyCache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Compression\NullCompressor;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Support\Environment;
use Psr\Log\LoggerInterface;

/**
 * Backwards-compatibility wrapper that mimics v2 constructor options
 * while delegating to MultiTierCache with a single FileStorage tier.
 */
final class EasyCache extends MultiTierCache
{
    /**
     * @param array{
     *   cache_path?: string,
     *   cache_extension?: string,
     *   cache_time?: int,
     *   directory_shards?: int,
     *   lock_path?: string,
     *   signing_key?: string,
     *   logger?: LoggerInterface
     * } $options
     */
    public function __construct(array $options = [])
    {
        // The default lives outside vendor/ (which is wiped by composer install)
        // in a per-user directory.
        $path       = (string) ($options['cache_path'] ?? Environment::tempDirectory('easycache'));
        $ext        = (string) ($options['cache_extension'] ?? '.cache');
        $shards     = max(0, min(3, (int) ($options['directory_shards'] ?? 2)));
        $defaultTtl = max(0, (int) ($options['cache_time'] ?? 3600));
        $dirMode    = isset($options['cache_path']) ? 0770 : 0700;

        parent::__construct(
            [new FileStorage($path, $ext, $shards, 0640, $dirMode)],
            new NativeSerializer(),
            new NullCompressor(),
            $defaultTtl,
            $options['logger'] ?? null,
            isset($options['lock_path']) ? (string) $options['lock_path'] : null,
            null,
            isset($options['signing_key']) ? (string) $options['signing_key'] : null
        );
    }
}
