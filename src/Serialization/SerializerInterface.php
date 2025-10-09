<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Serialization;

interface SerializerInterface
{
    public function serialize(mixed $value): string;
    public function deserialize(string $payload): mixed;
    public function name(): string;
}
