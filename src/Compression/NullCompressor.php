<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Compression;

final class NullCompressor implements CompressorInterface
{
    public function compress(string $data): string
    {
        return $data;
    }
    public function decompress(string $data): string
    {
        return $data;
    }
    public function name(): string
    {
        return 'none';
    }
}
