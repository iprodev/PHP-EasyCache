<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Laravel;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Laravel\EasyCacheServiceProvider;
use Iprodev\EasyCache\Laravel\EasyCacheStore;
use Iprodev\EasyCache\Tests\Support\TempDirectory;
use Orchestra\Testbench\TestCase;

class CacheStoreTest extends TestCase
{
    use TempDirectory;

    protected function getPackageProviders($app): array
    {
        return [EasyCacheServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $dir = $this->makeTempDir();
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.prefix', 'app-cache-');
        $app['config']->set('easycache.file.path', $dir . '/data');
        $app['config']->set('easycache.lock.path', $dir . '/locks');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeTempDirs();
    }

    private function store(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store('easycache');
    }

    public function testStoreIsRegisteredWithoutConfiguration(): void
    {
        $store = $this->store()->getStore();
        $this->assertInstanceOf(EasyCacheStore::class, $store);
        $this->assertSame('app-cache-', $store->getPrefix());
        $this->assertSame($this->app->make(MultiTierCache::class), $store->getCache());
    }

    public function testBasicOperations(): void
    {
        $cache = $this->store();

        $this->assertTrue($cache->put('user:1', ['name' => 'Ava'], 60));
        $this->assertSame(['name' => 'Ava'], $cache->get('user:1'));
        $this->assertTrue($cache->has('user:1'));

        $this->assertSame('computed', $cache->remember('posts/latest', 60, fn () => 'computed'));
        $this->assertSame('computed', $cache->remember('posts/latest', 60, fn () => 'other'));

        $this->assertTrue($cache->forever('forever key', 'v'));
        $this->assertSame('v', $cache->get('forever key'));

        $this->assertTrue($cache->forget('user:1'));
        $this->assertNull($cache->get('user:1'));
    }

    public function testKeysWithSpecialCharactersDoNotCollide(): void
    {
        $cache = $this->store();
        $cache->put('a:b', 1, 60);
        $cache->put('a%3Ab', 2, 60);
        $cache->put('a/b', 3, 60);
        $cache->put(str_repeat('x', 300), 4, 60);

        $this->assertSame(1, $cache->get('a:b'));
        $this->assertSame(2, $cache->get('a%3Ab'));
        $this->assertSame(3, $cache->get('a/b'));
        $this->assertSame(4, $cache->get(str_repeat('x', 300)));
    }

    public function testManyAndPutMany(): void
    {
        $cache = $this->store();
        $this->assertTrue($cache->putMany(['a:1' => 'A', 'b:2' => 'B'], 60));
        $this->assertSame(['a:1' => 'A', 'b:2' => 'B', 'c:3' => null], $cache->many(['a:1', 'b:2', 'c:3']));
    }

    public function testIncrementDecrementAndAdd(): void
    {
        $cache = $this->store();

        $this->assertSame(1, $cache->increment('hits'));
        $this->assertSame(11, $cache->increment('hits', 10));
        $this->assertSame(9, $cache->decrement('hits', 2));

        $this->assertTrue($cache->add('once', 'first', 60));
        $this->assertFalse($cache->add('once', 'second', 60));
        $this->assertSame('first', $cache->get('once'));
    }

    public function testTouchAndFlush(): void
    {
        $cache = $this->store();
        $cache->put('k', 'v', 60);
        $this->assertTrue($cache->getStore()->touch('k', 120));
        $this->assertFalse($cache->getStore()->touch('missing', 120));

        $this->assertTrue($cache->flush());
        $this->assertNull($cache->get('k'));
    }

    public function testLaravelTags(): void
    {
        $cache = $this->store();
        $cache->tags(['users'])->put('u1', 'alice', 60);
        $cache->tags(['posts'])->put('p1', 'hello', 60);

        $this->assertSame('alice', $cache->tags(['users'])->get('u1'));
        $cache->tags(['users'])->flush();

        $this->assertNull($cache->tags(['users'])->get('u1'));
        $this->assertSame('hello', $cache->tags(['posts'])->get('p1'));
    }

    public function testAtomicLocks(): void
    {
        $cache = $this->store();
        $first = $cache->lock('report', 10);
        $second = $cache->lock('report', 10);

        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
        $this->assertFalse($second->release(), 'only the owner can release');

        $restored = $cache->restoreLock('report', $first->owner());
        $this->assertTrue($restored->release());
        $this->assertTrue($second->get());
        $second->forceRelease();

        $this->assertSame('done', $cache->lock('block')->get(fn () => 'done'));
        $this->assertTrue($cache->lock('block')->get(), 'released after the callback');
    }

    public function testRateLimiter(): void
    {
        $limiter = new RateLimiter($this->store());

        for ($i = 0; $i < 3; $i++) {
            $limiter->hit('login:alice@example.com', 60);
        }

        $this->assertSame(3, $limiter->attempts('login:alice@example.com'));
        $this->assertTrue($limiter->tooManyAttempts('login:alice@example.com', 3));
        $this->assertFalse($limiter->tooManyAttempts('login:bob@example.com', 3));
    }

    public function testExplicitStoreConfigurationIsKept(): void
    {
        $this->app['config']->set('cache.stores.custom', ['driver' => 'easycache', 'prefix' => 'custom-']);
        $this->assertSame('custom-', Cache::store('custom')->getStore()->getPrefix());
    }
}
