<?php
require __DIR__ . '/../vendor/autoload.php';

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;

$cache = new MultiTierCache([ new FileStorage(__DIR__ . '/cache') ]);

$result = $cache->getOrSetSWR('demo', function () {
    usleep(200000);
    return ['time' => time()];
}, 5, 5, 60, ['mode' => 'sync']);

var_dump($result);
