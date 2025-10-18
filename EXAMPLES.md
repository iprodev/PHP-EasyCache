# EasyCache Practical Examples

This document contains real-world examples and use cases for PHP EasyCache.

## Table of Contents

- [Basic Usage](#basic-usage)
- [API Response Caching](#api-response-caching)
- [Database Query Caching](#database-query-caching)
- [Session Storage](#session-storage)
- [View Fragment Caching](#view-fragment-caching)
- [Rate Limiting](#rate-limiting)
- [Distributed Locking](#distributed-locking)
- [Computed Properties](#computed-properties)
- [Multi-Language Content](#multi-language-content)

---

## Basic Usage

### Simple Key-Value Storage

```php
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Compression\NullCompressor;

$cache = new MultiTierCache(
    [new FileStorage('/tmp/cache')],
    new NativeSerializer(),
    new NullCompressor()
);

// Store a string
$cache->set('greeting', 'Hello, World!', 3600);

// Store an array
$cache->set('user', [
    'id' => 1,
    'name' => 'John Doe',
    'email' => 'john@example.com'
], 3600);

// Store an object
$user = new stdClass();
$user->id = 1;
$user->name = 'Jane Doe';
$cache->set('user_object', $user, 3600);

// Retrieve
$greeting = $cache->get('greeting');
$user = $cache->get('user');
```

---

## API Response Caching

### External API with SWR

```php
use GuzzleHttp\Client;

class WeatherService
{
    private MultiTierCache $cache;
    private Client $httpClient;

    public function __construct(MultiTierCache $cache)
    {
        $this->cache = $cache;
        $this->httpClient = new Client();
    }

    public function getCurrentWeather(string $city): array
    {
        return $this->cache->getOrSetSWR(
            key: "weather_{$city}",
            producer: function() use ($city) {
                // Expensive API call
                $response = $this->httpClient->get(
                    "https://api.weather.com/v1/current",
                    ['query' => ['city' => $city]]
                );
                
                return json_decode($response->getBody(), true);
            },
            ttl: 300,                    // Fresh for 5 minutes
            swrSeconds: 60,              // Serve stale for 1 minute while refreshing
            staleIfErrorSeconds: 3600,   // If API is down, serve stale for 1 hour
            options: ['mode' => 'defer'] // Refresh after response
        );
    }
}

// Usage
$service = new WeatherService($cache);
$weather = $service->getCurrentWeather('Tehran');
```

### GraphQL Query Caching

```php
class GraphQLCache
{
    private MultiTierCache $cache;

    public function query(string $query, array $variables = []): array
    {
        $cacheKey = 'graphql_' . md5($query . json_encode($variables));

        return $this->cache->getOrSetSWR(
            key: $cacheKey,
            producer: fn() => $this->executeGraphQL($query, $variables),
            ttl: 600,          // 10 minutes
            swrSeconds: 120,   // 2 minutes SWR
            staleIfErrorSeconds: 1800 // 30 minutes if error
        );
    }

    private function executeGraphQL(string $query, array $variables): array
    {
        // Execute actual GraphQL query
        // ...
    }
}
```

---

## Database Query Caching

### Complex Query with Multiple Joins

```php
class ProductRepository
{
    private PDO $db;
    private MultiTierCache $cache;

    public function getFeaturedProducts(): array
    {
        return $this->cache->getOrSetSWR(
            key: 'products_featured',
            producer: function() {
                $stmt = $this->db->query("
                    SELECT p.*, c.name as category_name, 
                           COUNT(r.id) as review_count,
                           AVG(r.rating) as avg_rating
                    FROM products p
                    LEFT JOIN categories c ON p.category_id = c.id
                    LEFT JOIN reviews r ON p.id = r.product_id
                    WHERE p.featured = 1 AND p.active = 1
                    GROUP BY p.id
                    ORDER BY p.sort_order
                    LIMIT 20
                ");
                
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            },
            ttl: 300,         // 5 minutes fresh
            swrSeconds: 60,   // 1 minute stale
            staleIfErrorSeconds: 900 // 15 minutes if DB fails
        );
    }

    public function getProduct(int $id): ?array
    {
        $key = "product_{$id}";
        
        $product = $this->cache->get($key);
        if ($product !== null) {
            return $product;
        }

        $stmt = $this->db->prepare("
            SELECT * FROM products WHERE id = ? AND active = 1
        ");
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product) {
            $this->cache->set($key, $product, 3600); // 1 hour
        }

        return $product ?: null;
    }

    public function invalidateProduct(int $id): void
    {
        $this->cache->delete("product_{$id}");
    }
}
```

### Query Result Pagination

```php
class UserRepository
{
    private MultiTierCache $cache;

    public function getPaginatedUsers(int $page, int $perPage = 20): array
    {
        $key = "users_page_{$page}_{$perPage}";

        return $this->cache->get($key) ?? $this->loadAndCacheUsers($page, $perPage);
    }

    private function loadAndCacheUsers(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        
        // Load from database
        $stmt = $this->db->prepare("
            SELECT * FROM users 
            ORDER BY created_at DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$perPage, $offset]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Cache for 5 minutes
        $key = "users_page_{$page}_{$perPage}";
        $this->cache->set($key, $users, 300);

        return $users;
    }

    public function invalidateUserPages(): void
    {
        // Clear all user pagination caches
        for ($page = 1; $page <= 100; $page++) {
            $this->cache->delete("users_page_{$page}_20");
        }
    }
}
```

---

## Session Storage

### Custom Session Handler

```php
use SessionHandlerInterface;

class CacheSessionHandler implements SessionHandlerInterface
{
    private MultiTierCache $cache;
    private int $ttl;

    public function __construct(MultiTierCache $cache, int $ttl = 1440)
    {
        $this->cache = $cache;
        $this->ttl = $ttl;
    }

    public function open($path, $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string|false
    {
        $data = $this->cache->get("session_{$id}");
        return $data !== null ? $data : '';
    }

    public function write($id, $data): bool
    {
        return $this->cache->set("session_{$id}", $data, $this->ttl);
    }

    public function destroy($id): bool
    {
        return $this->cache->delete("session_{$id}");
    }

    public function gc($max_lifetime): int|false
    {
        // Cache handles expiration automatically
        return 0;
    }
}

// Usage
$handler = new CacheSessionHandler($cache, 3600);
session_set_save_handler($handler, true);
session_start();

$_SESSION['user_id'] = 123;
$_SESSION['username'] = 'john_doe';
```

---

## View Fragment Caching

### HTML Fragment Caching

```php
class ViewCache
{
    private MultiTierCache $cache;

    public function fragment(string $name, int $ttl, callable $render): string
    {
        $cached = $this->cache->get("fragment_{$name}");
        
        if ($cached !== null) {
            return $cached;
        }

        ob_start();
        $render();
        $html = ob_get_clean();

        $this->cache->set("fragment_{$name}", $html, $ttl);

        return $html;
    }
}

// Usage in a view/template
$viewCache = new ViewCache($cache);

echo $viewCache->fragment('sidebar', 3600, function() {
    ?>
    <div class="sidebar">
        <h3>Popular Posts</h3>
        <ul>
            <?php foreach (getPopularPosts() as $post): ?>
                <li><a href="<?= $post['url'] ?>"><?= $post['title'] ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
});
```

### Menu Caching

```php
class MenuBuilder
{
    private MultiTierCache $cache;

    public function buildMenu(string $location): string
    {
        return $this->cache->getOrSetSWR(
            key: "menu_{$location}",
            producer: fn() => $this->renderMenu($location),
            ttl: 3600,     // 1 hour
            swrSeconds: 300, // 5 min stale
            staleIfErrorSeconds: 7200 // 2 hours if error
        );
    }

    private function renderMenu(string $location): string
    {
        // Fetch menu items from database
        $items = $this->getMenuItems($location);

        // Render HTML
        $html = '<ul class="menu">';
        foreach ($items as $item) {
            $html .= sprintf(
                '<li><a href="%s">%s</a></li>',
                htmlspecialchars($item['url']),
                htmlspecialchars($item['title'])
            );
        }
        $html .= '</ul>';

        return $html;
    }
}
```

---

## Rate Limiting

### IP-Based Rate Limiting

```php
class RateLimiter
{
    private MultiTierCache $cache;

    public function __construct(MultiTierCache $cache)
    {
        $this->cache = $cache;
    }

    public function attempt(string $key, int $maxAttempts, int $decay): bool
    {
        $cacheKey = "rate_limit_{$key}";
        $attempts = $this->cache->get($cacheKey) ?? 0;

        if ($attempts >= $maxAttempts) {
            return false;
        }

        $this->cache->set($cacheKey, $attempts + 1, $decay);
        return true;
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        $attempts = $this->cache->get("rate_limit_{$key}") ?? 0;
        return max(0, $maxAttempts - $attempts);
    }

    public function reset(string $key): void
    {
        $this->cache->delete("rate_limit_{$key}");
    }
}

// Usage
$limiter = new RateLimiter($cache);

$ip = $_SERVER['REMOTE_ADDR'];

if (!$limiter->attempt($ip, 100, 3600)) {
    http_response_code(429);
    die('Too many requests. Please try again later.');
}

// Process request...
```

### API Token Rate Limiting

```php
class ApiRateLimiter
{
    private MultiTierCache $cache;

    public function checkLimit(string $token, string $endpoint): array
    {
        $key = "api_limit_{$token}_{$endpoint}";
        $data = $this->cache->get($key) ?? [
            'requests' => 0,
            'reset_at' => time() + 3600
        ];

        if (time() >= $data['reset_at']) {
            $data = ['requests' => 0, 'reset_at' => time() + 3600];
        }

        $limit = 1000; // 1000 requests per hour
        $remaining = max(0, $limit - $data['requests']);

        if ($remaining === 0) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset_at' => $data['reset_at']
            ];
        }

        $data['requests']++;
        $this->cache->set($key, $data, 3600);

        return [
            'allowed' => true,
            'remaining' => $remaining - 1,
            'reset_at' => $data['reset_at']
        ];
    }
}
```

---

## Distributed Locking

### Prevent Concurrent Execution

```php
class CriticalSection
{
    private MultiTierCache $cache;

    public function execute(string $lockKey, callable $callback, int $timeout = 30): mixed
    {
        $acquired = $this->acquireLock($lockKey, $timeout);
        
        if (!$acquired) {
            throw new \RuntimeException("Could not acquire lock for: {$lockKey}");
        }

        try {
            return $callback();
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    private function acquireLock(string $key, int $timeout): bool
    {
        $lockKey = "lock_{$key}";
        $lockValue = uniqid('', true);
        $start = time();

        while (time() - $start < $timeout) {
            $existing = $this->cache->get($lockKey);
            
            if ($existing === null) {
                $this->cache->set($lockKey, $lockValue, $timeout);
                return true;
            }

            usleep(100000); // Wait 100ms
        }

        return false;
    }

    private function releaseLock(string $key): void
    {
        $this->cache->delete("lock_{$key}");
    }
}

// Usage
$critical = new CriticalSection($cache);

$critical->execute('invoice_generation', function() {
    // Only one process at a time can execute this
    generateInvoices();
});
```

---

## Computed Properties

### Expensive Calculations

```php
class Analytics
{
    private MultiTierCache $cache;

    public function getUserStatistics(int $userId): array
    {
        $key = "user_stats_{$userId}";

        return $this->cache->getOrSetSWR(
            key: $key,
            producer: fn() => $this->computeStatistics($userId),
            ttl: 3600,      // 1 hour fresh
            swrSeconds: 300, // 5 min stale
            staleIfErrorSeconds: 7200 // 2 hours if compute fails
        );
    }

    private function computeStatistics(int $userId): array
    {
        // Expensive calculations
        $totalOrders = $this->db->query("
            SELECT COUNT(*) FROM orders WHERE user_id = {$userId}
        ")->fetchColumn();

        $totalSpent = $this->db->query("
            SELECT SUM(total) FROM orders WHERE user_id = {$userId}
        ")->fetchColumn();

        $avgOrderValue = $totalOrders > 0 ? $totalSpent / $totalOrders : 0;

        return [
            'total_orders' => $totalOrders,
            'total_spent' => $totalSpent,
            'avg_order_value' => $avgOrderValue,
            'computed_at' => time()
        ];
    }
}
```

### Aggregated Data

```php
class DashboardData
{
    private MultiTierCache $cache;

    public function getDailySummary(string $date): array
    {
        return $this->cache->getOrSetSWR(
            key: "dashboard_summary_{$date}",
            producer: fn() => $this->computeDailySummary($date),
            ttl: 3600,
            swrSeconds: 600,
            staleIfErrorSeconds: 3600
        );
    }

    private function computeDailySummary(string $date): array
    {
        return [
            'total_sales' => $this->getTotalSales($date),
            'total_orders' => $this->getTotalOrders($date),
            'new_customers' => $this->getNewCustomers($date),
            'revenue' => $this->getRevenue($date),
            'popular_products' => $this->getPopularProducts($date, 10)
        ];
    }
}
```

---

## Multi-Language Content

### Translation Caching

```php
class TranslationCache
{
    private MultiTierCache $cache;

    public function translate(string $key, string $locale, array $params = []): string
    {
        $cacheKey = "trans_{$locale}_{$key}";

        $translation = $this->cache->get($cacheKey);
        
        if ($translation === null) {
            $translation = $this->loadTranslation($key, $locale);
            $this->cache->set($cacheKey, $translation, 86400); // 24 hours
        }

        return $this->interpolate($translation, $params);
    }

    public function loadLanguage(string $locale): array
    {
        return $this->cache->getOrSetSWR(
            key: "language_{$locale}",
            producer: fn() => $this->loadAllTranslations($locale),
            ttl: 86400,    // 24 hours
            swrSeconds: 3600, // 1 hour stale
            staleIfErrorSeconds: 86400 * 7 // 7 days if loading fails
        );
    }

    private function interpolate(string $text, array $params): string
    {
        foreach ($params as $key => $value) {
            $text = str_replace("{{$key}}", $value, $text);
        }
        return $text;
    }
}

// Usage
$trans = new TranslationCache($cache);
echo $trans->translate('welcome.message', 'fa_IR', ['name' => 'علی']);
```

---

## Tips and Tricks

### Cache Warming

```php
class CacheWarmer
{
    private MultiTierCache $cache;

    public function warmUp(): void
    {
        // Warm up frequently accessed data
        $this->warmPopularProducts();
        $this->warmUserProfiles();
        $this->warmConfiguration();
    }

    private function warmPopularProducts(): void
    {
        $products = $this->fetchPopularProducts();
        
        foreach ($products as $product) {
            $this->cache->set(
                "product_{$product['id']}", 
                $product, 
                3600
            );
        }
    }
}
```

### Cache Tags (Manual Implementation)

```php
class TaggedCache
{
    private MultiTierCache $cache;

    public function tags(array $tags): self
    {
        $this->currentTags = $tags;
        return $this;
    }

    public function put(string $key, $value, int $ttl): bool
    {
        // Store the actual value
        $result = $this->cache->set($key, $value, $ttl);

        // Store tag references
        foreach ($this->currentTags as $tag) {
            $tagKey = "tag_{$tag}";
            $keys = $this->cache->get($tagKey) ?? [];
            $keys[] = $key;
            $this->cache->set($tagKey, array_unique($keys), $ttl);
        }

        return $result;
    }

    public function flush(): void
    {
        foreach ($this->currentTags as $tag) {
            $tagKey = "tag_{$tag}";
            $keys = $this->cache->get($tagKey) ?? [];
            
            foreach ($keys as $key) {
                $this->cache->delete($key);
            }
            
            $this->cache->delete($tagKey);
        }
    }
}

// Usage
$taggedCache = new TaggedCache($cache);
$taggedCache->tags(['products', 'category:electronics'])
    ->put('product_123', $product, 3600);

// Flush all products in electronics category
$taggedCache->tags(['category:electronics'])->flush();
```

---

For more examples and use cases, check the [test files](tests/) in the repository.
