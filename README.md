# PHP EasyCache

The PHP EasyCache Class is an easy & fast way to cache 3rd party API calls with lossless data compression support.

## Install

Install via [composer](https://getcomposer.org):

```javascript
{
    "require": {
        "iprodev/php-easycache": "~1.2"
    }
}
```

Run `composer install` then use as normal:

```php
require 'vendor/autoload.php';
$cache = new iProDev\Util\EasyCache();
```

## Usage

A very basic usage example:

```php
$cache = new iProDev\Util\EasyCache();
$latest_tweet = $cache->get_data('tweet', 'http://search.twitter.com/search.atom?q=from:chawroka&rpp=1');
var_dump($latest_tweet);
```

A more advanced example:

```php
$cache = new iProDev\Util\EasyCache();
$cache->cache_path = 'cache/';
$cache->cache_time = 3600;

$data = $cache->get('identity_keyword');

if(!$data) {
	$data = $cache->get_contents('http://some.api.com/file.json');
	$cache->set('identity_keyword', $data);
}

var_dump($data);
```

An example with compressing data:

```php
$cache = new iProDev\Util\EasyCache();
$cache->compress_level = 9;

$data = $cache->get_data('identity_keyword', 'http://some.api.com/file.json');

var_dump($data);
```

## API Methods

```php
$data = "Some data to cache";

// Call EasyCache
$cache = new iProDev\Util\EasyCache();

// SET a Data into Cache
$cache->set('identity_keyword', $data);

// GET a Data from Cache
$data = $cache->get('identity_keyword');

// Check that the data is cached
$is_cached = $cache->is_cached('identity_keyword');

// Get the Data from URL and cache it
$data = $cache->get_data('identity_keyword', 'http://search.twitter.com/search.atom?q=from:chawroka&rpp=1');

// Helper function for retrieving data from url without cache
$data = $cache->get_contents('http://search.twitter.com/search.atom?q=from:chawroka&rpp=1');

// REMOVE a Cache item
$cache->delete('identity_keyword');

// REMOVES all Cache expired items
$cache->flush_expired();

// REMOVES all Cache items
$cache->flush();
```


## Configuration

```php
// Call EasyCache
$cache = new iProDev\Util\EasyCache();

// Path to cache folder (with trailing /)
$cache->cache_path = 'cache/';

// Cache file extension
$cache->cache_extension = '.cache';

// Length of time to cache a file (in seconds)
$cache->cache_time = 3600;

// Lossless data compression level. 1 - 9; 0 to disable
$cache->compress_level = 0;
```

## Credits

PHP EasyCache was created by [Hemn Chawroka](http://iprodev.com) from [iProDev](http://iprodev.com). Released under the MIT license.
