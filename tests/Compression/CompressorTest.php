<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Compression;

use Iprodev\EasyCache\Compression\NullCompressor;
use Iprodev\EasyCache\Compression\GzipCompressor;
use Iprodev\EasyCache\Compression\ZstdCompressor;
use PHPUnit\Framework\TestCase;

class CompressorTest extends TestCase
{
    public function testNullCompressor(): void
    {
        $compressor = new NullCompressor();

        $this->assertEquals('none', $compressor->name());

        $data = 'test data that should not be compressed';
        $compressed = $compressor->compress($data);
        $this->assertEquals($data, $compressed);

        $decompressed = $compressor->decompress($compressed);
        $this->assertEquals($data, $decompressed);
    }

    public function testGzipCompressor(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('Gzip extension not available');
        }

        $compressor = new GzipCompressor(5);

        $this->assertEquals('gzip', $compressor->name());

        $data = str_repeat('This is test data for compression. ', 100);
        $compressed = $compressor->compress($data);

        // Compressed should be smaller than original
        $this->assertLessThan(strlen($data), strlen($compressed));

        $decompressed = $compressor->decompress($compressed);
        $this->assertEquals($data, $decompressed);
    }

    public function testGzipCompressionLevels(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('Gzip extension not available');
        }

        $data = str_repeat('Test data for compression levels. ', 1000);

        $compressor1 = new GzipCompressor(1);
        $compressor9 = new GzipCompressor(9);

        $compressed1 = $compressor1->compress($data);
        $compressed9 = $compressor9->compress($data);

        // Higher compression level should result in smaller size
        $this->assertLessThanOrEqual(strlen($compressed1), strlen($compressed9) + 100);

        $this->assertEquals($data, $compressor1->decompress($compressed1));
        $this->assertEquals($data, $compressor9->decompress($compressed9));
    }

    public function testZstdCompressor(): void
    {
        if (!function_exists('zstd_compress')) {
            $this->markTestSkipped('Zstd extension not available');
        }

        $compressor = new ZstdCompressor(3);

        $this->assertEquals('zstd', $compressor->name());

        $data = str_repeat('This is test data for Zstd compression. ', 100);
        $compressed = $compressor->compress($data);

        // Compressed should be smaller than original
        $this->assertLessThan(strlen($data), strlen($compressed));

        $decompressed = $compressor->decompress($compressed);
        $this->assertEquals($data, $decompressed);
    }

    public function testGzipWithLargeData(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('Gzip extension not available');
        }

        $compressor = new GzipCompressor(3);

        // 1MB of data
        $data = str_repeat('Large data block for compression testing. ', 25000);
        $compressed = $compressor->compress($data);
        $decompressed = $compressor->decompress($compressed);

        $this->assertEquals($data, $decompressed);
        $this->assertLessThan(strlen($data), strlen($compressed));
    }

    public function testGzipCompressionFailureHandling(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('Gzip extension not available');
        }

        $compressor = new GzipCompressor(5);

        $this->expectException(\RuntimeException::class);
        $compressor->decompress('invalid compressed data');
    }

    public function testZstdCompressionFailureHandling(): void
    {
        if (!function_exists('zstd_compress')) {
            $this->markTestSkipped('Zstd extension not available');
        }

        $compressor = new ZstdCompressor(3);

        $this->expectException(\RuntimeException::class);
        $compressor->decompress('invalid compressed data');
    }
}
