<?php

declare(strict_types=1);

// Usage: php increment_worker.php <autoload> <cache-dir> <lock-dir> <iterations>
require $argv[1];

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Lock\FileLockProvider;
use Iprodev\EasyCache\Storage\FileStorage;

[, , $cacheDir, $lockDir, $iterations] = $argv;

$cache = new MultiTierCache([new FileStorage($cacheDir)], lockProvider: new FileLockProvider($lockDir));

for ($i = 0; $i < (int) $iterations; $i++) {
    if ($cache->increment('counter') === false) {
        fwrite(STDERR, "increment failed\n");
        exit(1);
    }
    $cache->add('first-writer', getmypid());
}
