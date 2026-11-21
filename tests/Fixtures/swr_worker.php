<?php

declare(strict_types=1);

// Usage: php swr_worker.php <autoload> <cache-dir> <lock-dir> <counter-file>
require $argv[1];

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Lock\FileLockProvider;
use Iprodev\EasyCache\Storage\FileStorage;

[, , $cacheDir, $lockDir, $counter] = $argv;

$cache = new MultiTierCache([new FileStorage($cacheDir)], lockProvider: new FileLockProvider($lockDir));

$value = $cache->getOrSetSWR('shared', function () use ($counter): string {
    file_put_contents($counter, "x", FILE_APPEND | LOCK_EX);
    usleep(500_000);
    return 'computed';
}, 60);

echo $value;
