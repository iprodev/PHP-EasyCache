<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Serialization;

final class NativeSerializer implements SerializerInterface
{
    public function serialize(mixed $value): string { return serialize($value); }
    public function deserialize(string $payload): mixed { return unserialize($payload, ['allowed_classes' => true]); }
    public function name(): string { return 'php'; }
}
