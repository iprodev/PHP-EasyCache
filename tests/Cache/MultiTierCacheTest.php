<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Cache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Serialization\JsonSerializer;
use Iprodev\EasyCache\Compression\NullCompressor;
use Iprodev\EasyCache\Compression\GzipCompressor;
use Iprodev\EasyCache\Exceptions\InvalidArgument;
use PHPUnit\Framework\TestCase;

class MultiTierCacheTest extends TestCase
{
    private string $tempDir;
    private MultiTierCache $cache;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/easycache-test-' . bin2hex(random_bytes(8));
        $storage = new FileStorage($this->tempDir);
        $this->cache = new MultiTierCache(
            [$storage],
            new NativeSerializer(),
            new NullCompressor(),
            3600
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->clear();
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    public function testBasicGetSet(): void
    {
        $this->assertTrue($this->cache->set('test_key', 'test_value'));
        $this->assertEquals('test_value', $this->cache->get('test_key'));
    }

    public function testGetWithDefault(): void
    {
        $this->assertEquals('default', $this->cache->get('nonexistent', 'default'));
    }

    public function testSetWithTtl(): void
    {
        $this->assertTrue($this->cache->set('ttl_key', 'value', 1));
        $this->assertEquals('value', $this->cache->get('ttl_key'));
        
        sleep(2);
        $this->assertNull($this->cache->get('ttl_key'));
    }

    public function testDelete(): void
    {
        $this->cache->set('delete_key', 'value');
        $this->assertTrue($this->cache->has('delete_key'));
        
        $this->assertTrue($this->cache->delete('delete_key'));
        $this->assertFalse($this->cache->has('delete_key'));
    }

    public function testClear(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        
        $this->assertTrue($this->cache->clear());
        
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }

    public function testHas(): void
    {
        $this->assertFalse($this->cache->has('nonexistent'));
        
        $this->cache->set('exists', 'value');
        $this->assertTrue($this->cache->has('exists'));
    }

    public function testGetMultiple(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        
        $result = $this->cache->getMultiple(['key1', 'key2', 'key3'], 'default');
        
        $this->assertEquals([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'default'
        ], $result);
    }

    public function testSetMultiple(): void
    {
        $values = [
            'multi1' => 'value1',
            'multi2' => 'value2',
            'multi3' => 'value3'
        ];
        
        $this->assertTrue($this->cache->setMultiple($values));
        
        $this->assertEquals('value1', $this->cache->get('multi1'));
        $this->assertEquals('value2', $this->cache->get('multi2'));
        $this->assertEquals('value3', $this->cache->get('multi3'));
    }

    public function testDeleteMultiple(): void
    {
        $this->cache->set('del1', 'value1');
        $this->cache->set('del2', 'value2');
        
        $this->assertTrue($this->cache->deleteMultiple(['del1', 'del2']));
        
        $this->assertFalse($this->cache->has('del1'));
        $this->assertFalse($this->cache->has('del2'));
    }

    public function testComplexDataTypes(): void
    {
        $data = [
            'string' => 'test',
            'int' => 42,
            'float' => 3.14,
            'array' => [1, 2, 3],
            'nested' => ['key' => 'value'],
            'object' => (object)['prop' => 'value']
        ];
        
        $this->cache->set('complex', $data);
        $retrieved = $this->cache->get('complex');
        
        $this->assertEquals($data['string'], $retrieved['string']);
        $this->assertEquals($data['int'], $retrieved['int']);
        $this->assertEquals($data['float'], $retrieved['float']);
        $this->assertEquals($data['array'], $retrieved['array']);
    }

    public function testInvalidKeyThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->cache->set('invalid:key', 'value');
    }

    public function testEmptyKeyThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->cache->set('', 'value');
    }

    public function testLongKeyThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->cache->set(str_repeat('x', 65), 'value');
    }

    public function testSWRBasicFunctionality(): void
    {
        $callCount = 0;
        $producer = function () use (&$callCount) {
            $callCount++;
            return 'produced_value_' . $callCount;
        };

        // First call - cache miss, should produce
        $result1 = $this->cache->getOrSetSWR('swr_key', $producer, 2, 5, 10);
        $this->assertEquals('produced_value_1', $result1);
        $this->assertEquals(1, $callCount);

        // Second call - cache hit, should not produce
        $result2 = $this->cache->getOrSetSWR('swr_key', $producer, 2, 5, 10);
        $this->assertEquals('produced_value_1', $result2);
        $this->assertEquals(1, $callCount);
    }

    public function testSWRStaleWhileRevalidate(): void
    {
        $callCount = 0;
        $producer = function () use (&$callCount) {
            $callCount++;
            return 'value_' . $callCount;
        };

        // Set initial value with short TTL
        $result1 = $this->cache->getOrSetSWR('swr_stale', $producer, 1, 10, 20);
        $this->assertEquals('value_1', $result1);

        // Wait for expiry
        sleep(2);

        // Should serve stale and trigger revalidation
        $result2 = $this->cache->getOrSetSWR('swr_stale', $producer, 1, 10, 20, ['mode' => 'sync']);
        $this->assertContains($result2, ['value_1', 'value_2']); // Could be either depending on timing
    }

    public function testDateIntervalTtl(): void
    {
        $interval = new \DateInterval('PT1H'); // 1 hour
        $this->assertTrue($this->cache->set('interval_key', 'value', $interval));
        $this->assertEquals('value', $this->cache->get('interval_key'));
    }

    public function testJsonSerializer(): void
    {
        $storage = new FileStorage($this->tempDir . '_json');
        $cache = new MultiTierCache(
            [$storage],
            new JsonSerializer(),
            new NullCompressor()
        );

        $data = ['key' => 'value', 'number' => 42];
        $cache->set('json_test', $data);
        
        $result = $cache->get('json_test');
        $this->assertEquals($data, $result);

        $cache->clear();
    }

    public function testGzipCompression(): void
    {
        if (!function_exists('gzcompress')) {
            $this->markTestSkipped('Gzip extension not available');
        }

        $storage = new FileStorage($this->tempDir . '_gzip');
        $cache = new MultiTierCache(
            [$storage],
            new NativeSerializer(),
            new GzipCompressor(5)
        );

        $largeData = str_repeat('This is a test string for compression. ', 1000);
        $cache->set('gzip_test', $largeData);
        
        $result = $cache->get('gzip_test');
        $this->assertEquals($largeData, $result);

        $cache->clear();
    }

    public function testNoTiersThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MultiTierCache([]);
    }

    public function testPrune(): void
    {
        $pruned = $this->cache->prune();
        $this->assertIsInt($pruned);
        $this->assertGreaterThanOrEqual(0, $pruned);
    }
}
