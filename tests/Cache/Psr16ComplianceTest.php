<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Cache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Lock\NullLockProvider;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Storage\PdoStorage;
use Iprodev\EasyCache\Tests\Support\TempDirectory;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * PSR-16 behaviour, run against real storages.
 */
class Psr16ComplianceTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function backends(): iterable
    {
        yield 'file' => ['file'];
        yield 'sqlite' => ['sqlite'];
    }

    private function cache(string $backend): CacheInterface
    {
        if ($backend === 'sqlite') {
            $storage = new PdoStorage(new PDO('sqlite::memory:'));
            $storage->ensureTable();
        } else {
            $storage = new FileStorage($this->makeTempDir());
        }

        return new MultiTierCache([$storage], null, null, 3600, null, null, null, null, new NullLockProvider());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function values(): iterable
    {
        yield 'string' => ['value'];
        yield 'empty string' => [''];
        yield 'binary string' => ["\x00\x01\xff"];
        yield 'int' => [42];
        yield 'negative int' => [-7];
        yield 'float' => [1.5];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'null' => [null];
        yield 'array' => [['a' => 1, 'b' => [2, 3]]];
        yield 'object' => [(object) ['p' => 'v']];
    }

    #[DataProvider('backends')]
    public function testDataTypesRoundTrip(string $backend): void
    {
        $cache = $this->cache($backend);
        foreach (self::values() as $name => [$value]) {
            $key = 'type_' . preg_replace('/\W/', '_', $name);
            $this->assertTrue($cache->set($key, $value), $name);
            $this->assertEquals($value, $cache->get($key, 'default-sentinel'), $name);
            $this->assertTrue($cache->has($key), $name);
        }
    }

    #[DataProvider('backends')]
    public function testBasicContract(string $backend): void
    {
        $cache = $this->cache($backend);

        $this->assertNull($cache->get('missing'));
        $this->assertSame('d', $cache->get('missing', 'd'));
        $this->assertFalse($cache->has('missing'));
        $this->assertTrue($cache->delete('missing'));
        $this->assertTrue($cache->deleteMultiple([]));
        $this->assertTrue($cache->setMultiple([]));
        $this->assertSame([], $cache->getMultiple([]));

        $this->assertTrue($cache->setMultiple(['k1' => 'v1', 'k2' => 'v2'], new \DateInterval('PT1H')));
        $this->assertSame(['k1' => 'v1', 'k2' => 'v2'], $cache->getMultiple(new \ArrayIterator(['k1', 'k2'])));
        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('k1'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        foreach (str_split('{}()/\\@:') as $char) {
            yield "reserved {$char}" => ["key{$char}"];
        }
        yield 'empty' => [''];
        yield 'space' => ['with space'];
        yield 'newline' => ["key\n"];
        yield 'tab' => ["key\t"];
        yield 'non-ascii' => ['ключ'];
        yield 'too long' => [str_repeat('k', 256)];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysAreRejectedByEveryMethod(string $key): void
    {
        $cache = $this->cache('file');
        $calls = [
            'get' => fn () => $cache->get($key),
            'set' => fn () => $cache->set($key, 'v'),
            'delete' => fn () => $cache->delete($key),
            'has' => fn () => $cache->has($key),
            'getMultiple' => fn () => $cache->getMultiple([$key]),
            'setMultiple' => fn () => $cache->setMultiple([$key => 'v']),
            'deleteMultiple' => fn () => $cache->deleteMultiple([$key]),
        ];

        foreach ($calls as $method => $call) {
            try {
                $call();
                $this->fail("{$method}() accepted an invalid key");
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
