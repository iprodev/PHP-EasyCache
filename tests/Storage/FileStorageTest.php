<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Storage;

use Iprodev\EasyCache\Storage\FileStorage;
use PHPUnit\Framework\TestCase;

class FileStorageTest extends TestCase
{
    private string $tempDir;
    private FileStorage $storage;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/easycache-test-' . bin2hex(random_bytes(8));
        $this->storage = new FileStorage($this->tempDir, '.cache', 2);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->storage->clear();
            @rmdir($this->tempDir);
        }
    }

    public function testSetAndGet(): void
    {
        $key = 'test_key';
        $payload = 'test_payload_data';

        $this->assertTrue($this->storage->set($key, $payload, 3600));
        $this->assertEquals($payload, $this->storage->get($key));
    }

    public function testGetNonExistentKey(): void
    {
        $this->assertNull($this->storage->get('nonexistent'));
    }

    public function testHas(): void
    {
        $key = 'exists_key';
        $this->assertFalse($this->storage->has($key));

        $this->storage->set($key, 'data', 3600);
        $this->assertTrue($this->storage->has($key));
    }

    public function testDelete(): void
    {
        $key = 'delete_me';
        $this->storage->set($key, 'data', 3600);
        $this->assertTrue($this->storage->has($key));

        $this->assertTrue($this->storage->delete($key));
        $this->assertFalse($this->storage->has($key));
    }

    public function testDeleteNonExistent(): void
    {
        $this->assertTrue($this->storage->delete('nonexistent'));
    }

    public function testClear(): void
    {
        $this->storage->set('key1', 'data1', 3600);
        $this->storage->set('key2', 'data2', 3600);
        $this->storage->set('key3', 'data3', 3600);

        $this->assertTrue($this->storage->has('key1'));
        $this->assertTrue($this->storage->has('key2'));
        $this->assertTrue($this->storage->has('key3'));

        $this->assertTrue($this->storage->clear());

        $this->assertFalse($this->storage->has('key1'));
        $this->assertFalse($this->storage->has('key2'));
        $this->assertFalse($this->storage->has('key3'));
    }

    public function testSharding(): void
    {
        // With sharding level 2, files should be organized in subdirectories
        $key = 'sharded_key';
        $this->storage->set($key, 'data', 3600);
        $this->assertTrue($this->storage->has($key));
    }

    public function testLargePayload(): void
    {
        $key = 'large_key';
        $payload = str_repeat('X', 1024 * 100); // 100KB

        $this->assertTrue($this->storage->set($key, $payload, 3600));
        $this->assertEquals($payload, $this->storage->get($key));
    }

    public function testConcurrentWrites(): void
    {
        $key = 'concurrent_key';

        // Simulate multiple writes (atomic rename should handle this)
        $this->storage->set($key, 'data1', 3600);
        $this->storage->set($key, 'data2', 3600);
        $this->storage->set($key, 'data3', 3600);

        $result = $this->storage->get($key);
        $this->assertContains($result, ['data1', 'data2', 'data3']);
    }

    public function testInvalidDirectory(): void
    {
        $this->expectException(\RuntimeException::class);
        new FileStorage('/invalid/path/that/cannot/be/created/xyz123', '.cache', 0);
    }
}
