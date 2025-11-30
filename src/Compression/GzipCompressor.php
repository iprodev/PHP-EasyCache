<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Compression;

final class GzipCompressor implements CompressorInterface
{
    private int $level;
    public function __construct(int $level = 3)
    {
        $this->level = max(0, min(9, $level));
    }
    public function compress(string $data): string
    {
        if (!function_exists('gzcompress')) {
            throw new \RuntimeException('zlib not available');
        }
        $out = gzcompress($data, $this->level);
        if ($out === false) {
            throw new \RuntimeException('gzcompress failed');
        }
        return $out;
    }
    public function decompress(string $data): string
    {
        if (!function_exists('gzuncompress')) {
            throw new \RuntimeException('zlib not available');
        }
        $out = gzuncompress($data);
        if ($out === false) {
            throw new \RuntimeException('gzuncompress failed');
        }
        return $out;
    }
    public function name(): string
    {
        return 'gzip';
    }
}
