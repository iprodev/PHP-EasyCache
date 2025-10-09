<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Serialization;

final class JsonSerializer implements SerializerInterface
{
    public function serialize(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    public function deserialize(string $payload): mixed
    {
        return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    }
    public function name(): string { return 'json'; }
}
