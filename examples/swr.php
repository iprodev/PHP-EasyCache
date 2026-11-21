<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;

$cache = new MultiTierCache(
    [new FileStorage(sys_get_temp_dir() . '/easycache-example')],
    signingKey: getenv('EASYCACHE_SIGNING_KEY') ?: null, // >= 16 bytes; recommended in production
);

// Fresh for 5s, then served stale for up to 5s while it is refreshed, and for
// up to 60s if the producer fails.
$result = $cache->getOrSetSWR(
    'demo',
    function (): array {
        usleep(200000); // pretend to call a slow API
        return ['time' => time()];
    },
    ttl: 5,
    swrSeconds: 5,
    staleIfErrorSeconds: 60,
    options: ['mode' => 'defer'], // refresh after the response under PHP-FPM, sync elsewhere
);

var_dump($result);
var_dump($cache->getStats());
