<?php

return [
    'drivers' => ['apcu', 'redis', 'file'],

    'default_ttl' => 600,
    'lock_path' => getenv('EASYCACHE_LOCK_PATH') ?: (function_exists('storage_path') ? storage_path('framework/cache/easycache-locks') : sys_get_temp_dir().'/easycache-locks'),

    'apcu' => [
        'prefix' => getenv('EASYCACHE_APCU_PREFIX') ?: 'ec:',
    ],

    'redis' => [
        'client' => getenv('EASYCACHE_REDIS_CLIENT') ?: 'phpredis',
        'host' => getenv('EASYCACHE_REDIS_HOST') ?: '127.0.0.1',
        'port' => getenv('EASYCACHE_REDIS_PORT') ?: 6379,
        'password' => getenv('EASYCACHE_REDIS_PASSWORD') ?: null,
        'database' => getenv('EASYCACHE_REDIS_DB') ?: 0,
        'prefix' => getenv('EASYCACHE_REDIS_PREFIX') ?: 'ec:',
    ],

    'file' => [
        'path' => function_exists('storage_path') ? storage_path('framework/cache/easycache') : sys_get_temp_dir().'/easycache',
        'ext' => '.cache',
        'shards' => 2,
    ],

    'pdo' => [
        'dsn' => '',
        'user' => '',
        'password' => '',
        'table' => 'easycache',
        'auto_create' => true,
    ],

    'serializer' => [
        'driver' => 'php', // php|json
    ],

    'compressor' => [
        'driver' => 'none', // none|gzip|zstd
        'level' => 3,
    ],
];
