<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Support\Redis;

use Predis\ClientContextInterface;
use Predis\ClientInterface;
use Predis\Response\ErrorInterface;
use Predis\Response\Status;
use Throwable;

/**
 * @internal
 */
final class PredisConnection extends RedisConnection
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function get(string $key): ?string
    {
        $value = $this->client->get($key);
        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        $result = $ttl > 0 ? $this->client->setex($key, $ttl, $value) : $this->client->set($key, $value);
        return self::isOk($result);
    }

    public function setIfAbsent(string $key, string $value, int $ttlMs): bool
    {
        return self::isOk($this->client->set($key, $value, 'PX', $ttlMs, 'NX'));
    }

    public function delete(array $keys): void
    {
        if ($keys !== []) {
            $this->client->del($keys);
        }
    }

    public function exists(string $key): bool
    {
        return $this->client->exists($key) > 0;
    }

    public function getMany(array $keys): array
    {
        $values = $this->client->mget($keys);
        $result = [];
        foreach (array_keys($keys) as $i) {
            $value = $values[$i] ?? null;
            $result[] = is_string($value) ? $value : null;
        }
        return $result;
    }

    public function setMany(array $values, int $ttl): bool
    {
        $ok = true;
        // One round trip with a pipeline when the client supports it.
        if ($this->client instanceof \Predis\Client) {
            $results = $this->client->pipeline(function (ClientContextInterface $pipe) use ($values, $ttl): void {
                foreach ($values as $key => $value) {
                    if ($ttl > 0) {
                        $pipe->setex((string) $key, $ttl, $value);
                    } else {
                        $pipe->set((string) $key, $value);
                    }
                }
            });
            foreach (is_array($results) ? $results : [false] as $result) {
                $ok = self::isOk($result) && $ok;
            }
            return $ok;
        }

        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }
        return $ok;
    }

    public function deleteByPrefix(string $prefix, int $batchSize): void
    {
        // A client-level prefix (the "prefix" option) is added to DEL arguments
        // by Predis but not to the SCAN MATCH pattern, and SCAN returns full names.
        $clientPrefix = $this->clientPrefix();
        $pattern = self::escapeGlob($clientPrefix . $prefix) . '*';
        $cursor = '0';

        do {
            $response = $this->client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => $batchSize]);
            $next = $response[0] ?? null;
            $keys = $response[1] ?? null;
            if (!is_scalar($next) || !is_array($keys)) {
                throw new \RuntimeException('Unexpected SCAN response');
            }
            $cursor = (string) $next;
            $this->delete(self::stripPrefix($keys, $clientPrefix));
        } while ($cursor !== '0');
    }

    public function deleteIfEquals(string $key, string $value): void
    {
        $this->client->eval(self::DELETE_IF_EQUALS_SCRIPT, 1, $key, $value);
    }

    private function clientPrefix(): string
    {
        try {
            $prefix = $this->client->getOptions()->prefix ?? null;
            if (is_object($prefix) && method_exists($prefix, 'getPrefix')) {
                $value = $prefix->getPrefix();
                return is_string($value) ? $value : '';
            }
        } catch (Throwable) {
        }

        return '';
    }

    private static function isOk(mixed $result): bool
    {
        if ($result instanceof ErrorInterface) {
            return false;
        }
        if ($result instanceof Status) {
            return $result->getPayload() === 'OK';
        }

        return $result === true || $result === 'OK' || $result === 1;
    }
}
