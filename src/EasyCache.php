<?php

declare(strict_types=1);

namespace Iprodev\EasyCache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Compression\NullCompressor;

/**
 * Backwards-compatibility wrapper that mimics v2 constructor options
 * while delegating to MultiTierCache with a single FileStorage tier.
 */
final class EasyCache extends MultiTierCache
{
    /**
     * @param array{
     *   cache_path?:string,
     *   cache_extension?:string,
     *   cache_time?:int,
     *   directory_shards?:int
     * } $options
     */
    public function __construct(array $options = [])
    {
        $path       = rtrim($options['cache_path'] ?? __DIR__ . '/../cache', '/');
        $ext        = $options['cache_extension'] ?? '.cache';
        $shards     = max(0, min(3, (int)($options['directory_shards'] ?? 2)));
        $defaultTtl = max(0, (int)($options['cache_time'] ?? 3600));

        parent::__construct(
            [ new FileStorage($path, $ext, $shards) ],
            new NativeSerializer(),
            new NullCompressor(),
            $defaultTtl
        );
    }
}
