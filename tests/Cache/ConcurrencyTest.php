<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Cache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

class ConcurrencyTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    public function testProducerRunsOnceUnderConcurrentMisses(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available');
        }

        $cacheDir = $this->makeTempDir();
        $lockDir = $this->makeTempDir('easycache-locks');
        $counter = $this->makeTempDir('easycache-counter');
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $worker = dirname(__DIR__) . '/Fixtures/swr_worker.php';

        $processes = [];
        for ($i = 0; $i < 5; $i++) {
            $cmd = [PHP_BINARY, $worker, $autoload, $cacheDir, $lockDir, $counter];
            $pipes = [];
            $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), (string) $err);
            $this->assertSame('computed', $out);
        }

        $this->assertSame('x', file_get_contents($counter), 'the producer must run exactly once');
    }

    public function testIncrementIsAtomicAcrossProcesses(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available');
        }

        $cacheDir = $this->makeTempDir();
        $lockDir = $this->makeTempDir('easycache-locks');
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $worker = dirname(__DIR__) . '/Fixtures/increment_worker.php';

        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $worker, $autoload, $cacheDir, $lockDir, '25'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), (string) $err);
        }

        $cache = new MultiTierCache([new FileStorage($cacheDir)]);
        $this->assertSame(100, $cache->get('counter'), 'no increment may be lost');
        $this->assertIsInt($cache->get('first-writer'));
    }
}
