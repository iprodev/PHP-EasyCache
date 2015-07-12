# PHP EasyCache

The PHP EasyCache Class is an easy & fast way to cache 3rd party API calls with lossless data compression support.

## Install

Install via [composer](https://getcomposer.org):

```javascript
{
    "require": {
        "iprodev/php-easycache": "~1.0"
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

## Credits

PHP EasyCache was created by [Hemn Chawroka](http://iprodev.com) from [iProDev](http://iprodev.com). Released under the MIT license.
