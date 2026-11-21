<?php

$env = static function (string $key, mixed $default = null): mixed {
    if (function_exists('env')) {
        return env($key, $default);
    }
    $value = getenv($key);
    return $value === false ? $default : $value;
};

$storage = static function (string $path): string {
    return function_exists('storage_path') ? storage_path($path) : sys_get_temp_dir() . '/' . basename($path);
};

return [
    /*
    | Storage tiers, fastest first (comma separated in EASYCACHE_DRIVERS).
    | Available: apcu, redis, file, pdo. Example: "apcu,redis".
    */
    'drivers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) $env('EASYCACHE_DRIVERS', 'file'))
    ))),

    /*
    | When a driver cannot be initialised (extension missing, Redis down...)
    | log a warning and continue with the remaining drivers instead of failing.
    */
    'skip_unavailable_drivers' => (bool) $env('EASYCACHE_SKIP_UNAVAILABLE', true),

    'default_ttl' => (int) $env('EASYCACHE_DEFAULT_TTL', 600),

    /*
    | Entries are signed with HMAC-SHA256 so tampered entries are rejected
    | before they are unserialized. Without an explicit key, one is derived
    | from APP_KEY. Set EASYCACHE_SIGN=false to disable signing.
    */
    'sign' => (bool) $env('EASYCACHE_SIGN', true),
    'signing_key' => $env('EASYCACHE_SIGNING_KEY'),

    /*
    | The php serializer below may create objects (allowed_classes). That is
    | only safe for signed entries, so EasyCache refuses to start with signing
    | disabled unless this is enabled. Enable it only if nobody else can write
    | to any configured cache backend.
    */
    'allow_unsigned_objects' => (bool) $env('EASYCACHE_ALLOW_UNSIGNED_OBJECTS', false),

    'lock' => [
        'driver' => $env('EASYCACHE_LOCK_DRIVER', 'file'), // file|redis|none
        'path' => $env('EASYCACHE_LOCK_PATH') ?: $storage('framework/cache/easycache-locks'),
        'timeout' => (float) $env('EASYCACHE_LOCK_TIMEOUT', 30),
        'redis_ttl_ms' => (int) $env('EASYCACHE_LOCK_REDIS_TTL_MS', 30000),
    ],

    'apcu' => [
        'prefix' => $env('EASYCACHE_APCU_PREFIX') ?: 'ec:',
        // Cap the TTL of the (server-local) APCu tier so other servers'
        // updates become visible quickly. 0 = no cap.
        'max_ttl' => (int) $env('EASYCACHE_APCU_MAX_TTL', 60),
    ],

    'redis' => [
        'client' => $env('EASYCACHE_REDIS_CLIENT') ?: 'phpredis', // phpredis|predis
        'scheme' => $env('EASYCACHE_REDIS_SCHEME') ?: 'tcp',
        'host' => $env('EASYCACHE_REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) ($env('EASYCACHE_REDIS_PORT') ?: 6379),
        'password' => $env('EASYCACHE_REDIS_PASSWORD') ?: null,
        'database' => (int) ($env('EASYCACHE_REDIS_DB') ?: 0),
        'timeout' => (float) ($env('EASYCACHE_REDIS_TIMEOUT') ?: 1.5),
        'prefix' => $env('EASYCACHE_REDIS_PREFIX') ?: 'ec:',
    ],

    'file' => [
        'path' => $env('EASYCACHE_FILE_PATH') ?: $storage('framework/cache/easycache'),
        'ext' => '.cache',
        'shards' => 2,
        'file_mode' => 0640,
        'dir_mode' => 0770,
    ],

    'pdo' => [
        'dsn' => $env('EASYCACHE_PDO_DSN', ''), // empty = SQLite file in storage/framework/cache
        'user' => $env('EASYCACHE_PDO_USER'),
        'password' => $env('EASYCACHE_PDO_PASSWORD'),
        'table' => $env('EASYCACHE_PDO_TABLE', 'easycache'),
        'auto_create' => true,
    ],

    'serializer' => [
        'driver' => $env('EASYCACHE_SERIALIZER', 'php'), // php|json
        // For the php serializer: true = any class (entries are signed by
        // default, see "sign"), false = no objects, or a list of class names.
        'allowed_classes' => true,
    ],

    'compressor' => [
        'driver' => $env('EASYCACHE_COMPRESSOR', 'none'), // none|gzip|zstd
        'level' => (int) $env('EASYCACHE_COMPRESSION_LEVEL', 3),
    ],
];
