<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Cache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Compression\CompressorInterface;
use Iprodev\EasyCache\Compression\GzipCompressor;
use Iprodev\EasyCache\Compression\NullCompressor;
use Iprodev\EasyCache\Exceptions\InvalidArgument;
use Iprodev\EasyCache\Lock\NullLockProvider;
use Iprodev\EasyCache\Serialization\JsonSerializer;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Tests\Support\ArrayStorage;
use Iprodev\EasyCache\Tests\Support\MemoryLogger;
use Iprodev\EasyCache\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

class MultiTierCacheTest extends TestCase
{
    use TempDirectory;

    private FrozenClock $clock;
    private ArrayStorage $storage;
    private MultiTierCache $cache;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->storage = new ArrayStorage($this->clock);
        $this->cache = $this->makeCache([$this->storage]);
    }

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    /**
     * @param list<\Iprodev\EasyCache\Storage\StorageInterface> $tiers
     */
    private function makeCache(array $tiers, int $defaultTtl = 3600, ...$named): MultiTierCache
    {
        return new MultiTierCache(
            $tiers,
            $named['serializer'] ?? null,
            $named['compressor'] ?? null,
            $defaultTtl,
            $named['logger'] ?? null,
            null,
            $this->clock,
            $named['signingKey'] ?? null,
            $named['locks'] ?? new NullLockProvider()
        );
    }

    public function testBasicGetSet(): void
    {
        $this->assertTrue($this->cache->set('test_key', 'test_value'));
        $this->assertSame('test_value', $this->cache->get('test_key'));
    }

    public function testGetWithDefault(): void
    {
        $this->assertSame('default', $this->cache->get('nonexistent', 'default'));
    }

    public function testTtlExpiry(): void
    {
        $this->cache->set('ttl_key', 'value', 10);
        $this->clock->advance(9);
        $this->assertSame('value', $this->cache->get('ttl_key'));
        $this->clock->advance(1);
        $this->assertNull($this->cache->get('ttl_key'));
    }

    public function testDateIntervalTtl(): void
    {
        $this->assertTrue($this->cache->set('interval_key', 'value', new \DateInterval('PT1H')));
        $this->clock->advance(3599);
        $this->assertSame('value', $this->cache->get('interval_key'));
        $this->clock->advance(1);
        $this->assertNull($this->cache->get('interval_key'));
    }

    public function testDefaultTtlIsUsed(): void
    {
        $cache = $this->makeCache([$this->storage], 100);
        $cache->set('k', 'v');
        $this->clock->advance(99);
        $this->assertSame('v', $cache->get('k'));
        $this->clock->advance(1);
        $this->assertNull($cache->get('k'));
    }

    public function testDefaultTtlZeroMeansForever(): void
    {
        $cache = $this->makeCache([$this->storage], 0);
        $cache->set('k', 'v');
        $this->clock->advance(10 * 365 * 86400);
        $this->assertSame('v', $cache->get('k'));
    }

    public function testZeroOrNegativeTtlDeletes(): void
    {
        $this->cache->set('k', 'v');
        $this->assertTrue($this->cache->set('k', 'new', 0));
        $this->assertFalse($this->cache->has('k'));

        $this->cache->set('k', 'v');
        $this->assertTrue($this->cache->set('k', 'new', -5));
        $this->assertFalse($this->cache->has('k'));

        $this->assertTrue($this->cache->set('never-existed', 'x', 0));
    }

    public function testDeleteReturnsTrueForMissingKey(): void
    {
        $this->assertTrue($this->cache->delete('missing'));
    }

    public function testDeleteAndHas(): void
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

    public function testNullAndFalseAreCached(): void
    {
        $this->cache->set('null', null);
        $this->cache->set('false', false);

        $this->assertTrue($this->cache->has('null'));
        $this->assertNull($this->cache->get('null', 'default'));
        $this->assertTrue($this->cache->has('false'));
        $this->assertFalse($this->cache->get('false', 'default'));
    }

    public function testComplexDataTypes(): void
    {
        $data = [
            'string' => 'test',
            'int' => 42,
            'float' => 3.14,
            'array' => [1, 2, 3],
            'nested' => ['key' => 'value'],
            'object' => (object) ['prop' => 'value'],
        ];

        $this->cache->set('complex', $data);
        $this->assertEquals($data, $this->cache->get('complex'));
    }

    public function testGetMultiple(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $result = $this->cache->getMultiple(['key1', 'key2', 'key3'], 'default');

        $this->assertSame(['key1' => 'value1', 'key2' => 'value2', 'key3' => 'default'], $result);
        $this->assertSame(1, $this->storage->calls['getMany'] ?? 0, 'getMultiple() should use one batch read');
    }

    public function testMultipleWithGeneratorsAndNumericKeys(): void
    {
        $values = (function () {
            yield 'a' => 1;
            yield '123' => 2;
        })();
        $this->assertTrue($this->cache->setMultiple($values));
        $this->assertSame(1, $this->storage->calls['setMany'] ?? 0);

        $keys = (function () {
            yield 'a';
            yield '123';
        })();
        $this->assertSame(['a' => 1, 123 => 2], $this->cache->getMultiple($keys));

        $this->assertTrue($this->cache->deleteMultiple(['a', '123']));
        $this->assertSame(1, $this->storage->calls['deleteMany'] ?? 0);
        $this->assertFalse($this->cache->has('a'));
    }

    public function testSetMultipleWithZeroTtlDeletes(): void
    {
        $this->cache->set('a', 1);
        $this->assertTrue($this->cache->setMultiple(['a' => 2, 'b' => 3], 0));
        $this->assertFalse($this->cache->has('a'));
        $this->assertFalse($this->cache->has('b'));
    }

    public function testMultipleRejectsInvalidKeys(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->cache->getMultiple(['valid', 'in:valid']);
    }

    public function testMultipleRejectsNonStringKeys(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->cache->deleteMultiple([new \stdClass()]);
    }

    public function testInvalidKeyThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->cache->set('invalid:key', 'value');
    }

    public function testEmptyKeyThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->cache->get('');
    }

    public function testLongKeyThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->cache->set(str_repeat('x', 256), 'value');
    }

    public function testRelaxedKeys(): void
    {
        $key = 'user-3f2a9c1e-77b2-4f3b-9a51-1c2d3e4f5a6b';
        $this->assertTrue($this->cache->set($key, 'uuid'));
        $this->assertSame('uuid', $this->cache->get($key));
    }

    public function testRememberAndForever(): void
    {
        $calls = 0;
        $producer = function () use (&$calls) {
            return ++$calls;
        };

        $this->assertSame(1, $this->cache->remember('r', 10, $producer));
        $this->assertSame(1, $this->cache->remember('r', 10, $producer));
        $this->clock->advance(10);
        $this->assertSame(2, $this->cache->remember('r', 10, $producer));

        $this->assertTrue($this->cache->forever('f', 'v'));
        $this->clock->advance(100 * 365 * 86400);
        $this->assertSame('v', $this->cache->get('f'));
    }

    public function testStats(): void
    {
        $this->cache->set('a', 1);
        $this->cache->get('a');
        $this->cache->get('missing');
        $this->cache->delete('a');

        $stats = $this->cache->getStats();
        $this->assertSame(1, $stats['hits']);
        $this->assertSame(1, $stats['misses']);
        $this->assertSame(1, $stats['writes']);
        $this->assertSame(1, $stats['deletes']);
        $this->assertSame(0.5, $stats['hit_ratio']);

        $this->cache->resetStats();
        $this->assertSame(0, $this->cache->getStats()['hits']);
    }

    public function testStorageFailuresAreLoggedAndDegradeGracefully(): void
    {
        $logger = new MemoryLogger();
        $cache = $this->makeCache([$this->storage], 3600, logger: $logger);
        $this->storage->failing = true;

        $this->assertSame('d', $cache->get('k', 'd'));
        $this->assertFalse($cache->set('k', 'v'));
        $this->assertTrue($logger->hasMessageContaining('Read failed on tier 0'));
        $this->assertGreaterThan(0, $cache->getStats()['errors']);
    }

    public function testLoggerIsHandedToTiers(): void
    {
        $logger = new MemoryLogger();
        $dir = $this->makeTempDir();
        $storage = new FileStorage($dir);
        $this->makeCache([$storage], 3600, logger: $logger);
        $this->assertTrue($storage->hasLogger());
    }

    public function testJsonSerializer(): void
    {
        $cache = $this->makeCache([$this->storage], 3600, serializer: new JsonSerializer());
        $data = ['key' => 'value', 'number' => 42, 'float' => 1.0];
        $cache->set('json_test', $data);
        $this->assertSame($data, $cache->get('json_test'));
    }

    public function testGzipCompression(): void
    {
        $cache = $this->makeCache([$this->storage], 3600, compressor: new GzipCompressor(5));
        $largeData = str_repeat('This is a test string for compression. ', 1000);
        $cache->set('gzip_test', $largeData);

        $this->assertSame($largeData, $cache->get('gzip_test'));
        $this->assertLessThan(strlen($largeData), strlen((string) $this->storage->rawGet('gzip_test')));
    }

    public function testCustomCompressorIsUsed(): void
    {
        $compressor = new class () implements CompressorInterface {
            public int $calls = 0;

            public function compress(string $data): string
            {
                $this->calls++;
                return strrev($data);
            }

            public function decompress(string $data): string
            {
                return strrev($data);
            }

            public function name(): string
            {
                return 'reverse';
            }
        };

        $cache = $this->makeCache([$this->storage], 3600, compressor: $compressor);
        $cache->set('k', 'value');
        $this->assertSame(1, $compressor->calls);
        $this->assertSame('value', $cache->get('k'));

        // Another instance without that compressor cannot decode the entry: a miss, not garbage.
        $plain = $this->makeCache([$this->storage], 3600, compressor: new NullCompressor());
        $this->assertSame('miss', $plain->get('k', 'miss'));
    }

    public function testEntriesStayReadableAfterSwitchingCompressor(): void
    {
        $this->makeCache([$this->storage], 3600, compressor: new GzipCompressor())->set('k', 'gzipped');
        $plain = $this->makeCache([$this->storage], 3600, compressor: new NullCompressor());
        $this->assertSame('gzipped', $plain->get('k'));
    }

    public function testSwitchingSerializerIsAMiss(): void
    {
        $this->makeCache([$this->storage], 3600, serializer: new JsonSerializer())->set('k', ['a' => 1]);
        $php = $this->makeCache([$this->storage], 3600, serializer: new NativeSerializer());
        $this->assertSame('miss', $php->get('k', 'miss'));
        $this->assertFalse($php->has('k'));
    }

    public function testCorruptEntriesAreMisses(): void
    {
        $this->storage->rawSet('garbage', 'not an entry');
        $this->storage->rawSet('truncated', "EC03\x00\x00\x00");
        $this->assertSame('miss', $this->cache->get('garbage', 'miss'));
        $this->assertSame('miss', $this->cache->get('truncated', 'miss'));
    }

    public function testPrune(): void
    {
        $this->cache->set('a', 1, 10);
        $this->cache->set('b', 2, 100);
        $this->clock->advance(50);
        $this->assertSame(1, $this->cache->prune());
    }

    public function testNoTiersThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MultiTierCache([]);
    }

    public function testInvalidTierThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MultiTierCache([new \stdClass()]);
    }

    public function testConstructorDoesNotTouchTheFilesystemForLocks(): void
    {
        $lockDir = $this->makeTempDir('easycache-locks');
        new MultiTierCache([$this->storage], null, null, 60, null, $lockDir);
        $this->assertDirectoryDoesNotExist($lockDir);
    }
}
