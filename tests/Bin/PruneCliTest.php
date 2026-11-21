<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Bin;

use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Tests\Support\TempDirectory;
use PHPUnit\Framework\TestCase;

class PruneCliTest extends TestCase
{
    use TempDirectory;

    protected function tearDown(): void
    {
        $this->removeTempDirs();
    }

    /**
     * @param list<string> $args
     * @return array{int, string, string}
     */
    private function runCli(array $args): array
    {
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/easycache-prune'], $args);
        $pipes = [];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out, $err];
    }

    public function testPrunesExpiredEntries(): void
    {
        $dir = $this->makeTempDir();
        $storage = new FileStorage($dir);
        $storage->set('fresh', 'x', 3600);
        $storage->set('expired', 'y', 1);
        file_put_contents($dir . '/' . str_repeat('a', 32) . '.cache', 'old format');
        sleep(2);

        [$code, $out] = $this->runCli([$dir]);

        $this->assertSame(0, $code);
        $this->assertSame("Pruned 2 expired entries.\n", $out);
        $this->assertSame('x', $storage->get('fresh'));
    }

    public function testUsageErrors(): void
    {
        [$code, , $err] = $this->runCli([]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Usage', $err);

        [$code, , $err] = $this->runCli([$this->makeTempDir() . '/missing']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('not found', $err);

        [$code, $out] = $this->runCli(['--help']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Usage', $out);
    }
}
