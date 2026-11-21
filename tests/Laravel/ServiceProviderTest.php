<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Laravel;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Laravel\EasyCacheServiceProvider;
use Iprodev\EasyCache\Laravel\Facades\EasyCache;
use Iprodev\EasyCache\Tests\Support\TempDirectory;
use Orchestra\Testbench\TestCase;

class ServiceProviderTest extends TestCase
{
    use TempDirectory;

    private string $cacheDir;

    protected function getPackageProviders($app): array
    {
        return [EasyCacheServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['EasyCache' => EasyCache::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->cacheDir = $this->makeTempDir();
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('easycache.file.path', $this->cacheDir . '/data');
        $app['config']->set('easycache.lock.path', $this->cacheDir . '/locks');
        $app['config']->set('easycache.pdo.dsn', 'sqlite::memory:');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeTempDirs();
    }

    /**
     * @return array<string, mixed>
     */
    private function readPrivate(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        return $reflection->getValue($object);
    }

    public function testDefaultConfigurationUsesFileStorage(): void
    {
        $cache = $this->app->make('easycache');
        $this->assertInstanceOf(MultiTierCache::class, $cache);
        $this->assertSame($cache, $this->app->make(MultiTierCache::class));

        $this->assertTrue($cache->set('k', 'v'));
        $this->assertSame('v', $cache->get('k'));
        $this->assertDirectoryExists($this->cacheDir . '/data');
    }

    public function testFacade(): void
    {
        EasyCache::set('facade', 'works');
        $this->assertSame('works', EasyCache::get('facade'));
        $this->assertSame('computed', EasyCache::remember('r', 60, fn () => 'computed'));
    }

    public function testEntriesAreSignedWithKeyDerivedFromAppKey(): void
    {
        $cache = $this->app->make('easycache');
        $key = $this->readPrivate($cache, 'signingKey');
        $this->assertIsString($key);
        $this->assertNotSame($this->app['config']->get('app.key'), $key);
    }

    public function testSigningCanBeDisabled(): void
    {
        $this->app['config']->set('easycache.sign', false);
        $this->assertNull($this->readPrivate($this->app->make('easycache'), 'signingKey'));
    }

    public function testUnavailableDriversAreSkipped(): void
    {
        $this->app['config']->set('easycache.drivers', ['apcu', 'redis', 'file']);
        $this->app['config']->set('easycache.redis.host', '127.0.0.1');
        $this->app['config']->set('easycache.redis.port', 1); // nothing listens there
        $this->app['config']->set('easycache.redis.timeout', 0.2);

        $cache = $this->app->make('easycache');
        $this->assertTrue($cache->set('k', 'v'));
        $this->assertSame('v', $cache->get('k'));
    }

    public function testUnavailableDriversFailWhenSkippingIsDisabled(): void
    {
        $this->app['config']->set('easycache.skip_unavailable_drivers', false);
        $this->app['config']->set('easycache.drivers', ['nope']);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make('easycache');
    }

    public function testPdoDriverWithDefaults(): void
    {
        $this->app['config']->set('easycache.drivers', ['pdo']);
        $this->app['config']->set('easycache.pdo.dsn', '');
        $this->app->useStoragePath($this->cacheDir . '/storage');

        $cache = $this->app->make('easycache');
        $this->assertTrue($cache->set('k', 'v'));
        $this->assertSame('v', $cache->get('k'));
        $this->assertFileExists($this->cacheDir . '/storage/framework/cache/easycache.sqlite');
    }

    public function testJsonSerializerAndCompression(): void
    {
        $this->app['config']->set('easycache.serializer.driver', 'json');
        $this->app['config']->set('easycache.compressor.driver', 'gzip');

        $cache = $this->app->make('easycache');
        $cache->set('k', ['a' => 1]);
        $this->assertSame(['a' => 1], $cache->get('k'));
    }

    public function testArtisanCommands(): void
    {
        $this->app->make('easycache')->set('k', 'v');

        $this->artisan('easycache:prune')->expectsOutputToContain('Pruned')->assertExitCode(0);
        $this->artisan('easycache:clear')->expectsOutput('EasyCache cleared.')->assertExitCode(0);
        $this->assertNull($this->app->make('easycache')->get('k'));
    }
}
