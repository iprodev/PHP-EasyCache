<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Compression;

interface CompressorInterface
{
    public function compress(string $data): string;

    public function decompress(string $data): string;

    /**
     * Unique name (1-255 bytes) stored with every entry so the entry can be
     * decompressed later. "none" is reserved for "not compressed".
     */
    public function name(): string;
}
