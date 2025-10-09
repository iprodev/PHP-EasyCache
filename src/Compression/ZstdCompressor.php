<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Compression;

final class ZstdCompressor implements CompressorInterface
{
    private int $level;
    public function __construct(int $level = 3) { $this->level = $level; }
    public function compress(string $data): string
    {
        if (!function_exists('zstd_compress')) { throw new \RuntimeException('zstd not available'); }
        $out = zstd_compress($data, $this->level);
        if ($out === false) { throw new \RuntimeException('zstd_compress failed'); }
        return $out;
    }
    public function decompress(string $data): string
    {
        if (!function_exists('zstd_uncompress')) { throw new \RuntimeException('zstd not available'); }
        $out = zstd_uncompress($data);
        if ($out === false) { throw new \RuntimeException('zstd_uncompress failed'); }
        return $out;
    }
    public function name(): string { return 'zstd'; }
}
