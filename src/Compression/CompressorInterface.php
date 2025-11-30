<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Compression;

interface CompressorInterface
{
    public function compress(string $data): string;
    public function decompress(string $data): string;
    public function name(): string;
}
