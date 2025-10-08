# PHP EasyCache 🚀

A lightweight, secure, and extendable caching library for PHP — now safer, faster, and more flexible.

---

## Features
✅ File locking for thread-safe writes  
✅ Automatic expired cache cleanup  
✅ Per-key TTL  
✅ Key sanitization (security)  
✅ Optional compression  
✅ PHPUnit test support  

---

## Installation
```bash
composer require iprodev/php-easycache
```

---

## Usage
```php
use Iprodev\EasyCache\EasyCache;

$cache = new EasyCache([
    'cache_path' => __DIR__ . '/tmp/',
    'cache_time' => 600,
    'compression_level' => 3
]);

$data = $cache->get('github_data');

if (!$data) {
    $data = file_get_contents('https://api.github.com/repos/iprodev/PHP-EasyCache');
    $cache->set('github_data', $data, 300);
}

echo $data;
```

---

## Running Tests
```bash
composer install
composer test
```

---

## License
MIT © [iprodev](https://github.com/iprodev)
