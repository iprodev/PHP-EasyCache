<?php
use PHPUnit\Framework\TestCase;
use Iprodev\EasyCache\EasyCache;

class EasyCacheTest extends TestCase {

    private $cache;

    protected function setUp(): void {
        $this->cache = new EasyCache([
            'cache_path' => __DIR__ . '/../tmp/',
            'cache_time' => 2,
            'compression_level' => 0
        ]);
    }

    public function testSetAndGetCache() {
        $this->cache->set('foo', 'bar', 5);
        $this->assertEquals('bar', $this->cache->get('foo'));
    }

    public function testExpiredCache() {
        $this->cache->set('temp', 'data', 1);
        sleep(2);
        $this->assertNull($this->cache->get('temp'));
    }

    public function testFlush() {
        $this->cache->set('a', 'A');
        $this->cache->flush();
        $this->assertNull($this->cache->get('a'));
    }
}
?>