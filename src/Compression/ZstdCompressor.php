<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Compression;

use RuntimeException;

final class ZstdCompressor implements CompressorInterface
{
    public const DEFAULT_MAX_DECOMPRESSED_SIZE = 64 * 1024 * 1024;

    private int $level;
    private int $maxDecompressedSize;

    /**
     * @param int $level               Compression level 1-22
     * @param int $maxDecompressedSize Reject payloads that inflate beyond this (bytes, 0 = unlimited)
     */
    public function __construct(int $level = 3, int $maxDecompressedSize = self::DEFAULT_MAX_DECOMPRESSED_SIZE)
    {
        $this->level = max(1, min(22, $level));
        $this->maxDecompressedSize = max(0, $maxDecompressedSize);
    }

    public function compress(string $data): string
    {
        if (!function_exists('zstd_compress')) {
            throw new RuntimeException('zstd not available');
        }
        $out = zstd_compress($data, $this->level);
        if ($out === false) {
            throw new RuntimeException('zstd_compress failed');
        }
        return $out;
    }

    public function decompress(string $data): string
    {
        if (!function_exists('zstd_uncompress')) {
            throw new RuntimeException('zstd not available');
        }
        $out = @zstd_uncompress($data);
        if ($out === false) {
            throw new RuntimeException('zstd_uncompress failed');
        }
        if ($this->maxDecompressedSize > 0 && strlen($out) > $this->maxDecompressedSize) {
            throw new RuntimeException('Decompressed payload exceeds the configured maximum size');
        }
        return $out;
    }

    public function name(): string
    {
        return 'zstd';
    }
}
