<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Compression;

use RuntimeException;

final class GzipCompressor implements CompressorInterface
{
    public const DEFAULT_MAX_DECOMPRESSED_SIZE = 64 * 1024 * 1024;

    private int $level;
    private int $maxDecompressedSize;

    /**
     * @param int $level               Compression level 0-9
     * @param int $maxDecompressedSize Refuse to inflate payloads larger than this (bytes, 0 = unlimited)
     */
    public function __construct(int $level = 3, int $maxDecompressedSize = self::DEFAULT_MAX_DECOMPRESSED_SIZE)
    {
        $this->level = max(0, min(9, $level));
        $this->maxDecompressedSize = max(0, $maxDecompressedSize);
    }

    public function compress(string $data): string
    {
        if (!function_exists('gzcompress')) {
            throw new RuntimeException('zlib not available');
        }
        $out = gzcompress($data, $this->level);
        if ($out === false) {
            throw new RuntimeException('gzcompress failed');
        }
        return $out;
    }

    public function decompress(string $data): string
    {
        if (!function_exists('gzuncompress')) {
            throw new RuntimeException('zlib not available');
        }
        $out = @gzuncompress($data, $this->maxDecompressedSize);
        if ($out === false) {
            throw new RuntimeException('gzuncompress failed (corrupt data or larger than the configured maximum)');
        }
        return $out;
    }

    public function name(): string
    {
        return 'gzip';
    }
}
