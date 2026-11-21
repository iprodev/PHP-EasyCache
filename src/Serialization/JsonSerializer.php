<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Serialization;

/**
 * JSON serialization. Safe against object injection, but lossy: objects come
 * back as associative arrays and floats without a fractional part
 * (e.g. 1.0) come back as integers.
 */
final class JsonSerializer implements SerializerInterface
{
    /** @var int<1, max> */
    private int $depth;

    public function __construct(int $depth = 512)
    {
        $this->depth = max(1, $depth);
    }

    public function serialize(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            $this->depth
        );
    }

    public function deserialize(string $payload): mixed
    {
        return json_decode($payload, true, $this->depth, JSON_THROW_ON_ERROR);
    }

    public function name(): string
    {
        return 'json';
    }
}
