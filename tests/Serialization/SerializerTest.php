<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Serialization;

use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Serialization\JsonSerializer;
use PHPUnit\Framework\TestCase;

class SerializerTest extends TestCase
{
    public function testNativeSerializerWithPrimitives(): void
    {
        $serializer = new NativeSerializer();

        $this->assertEquals('php', $serializer->name());

        $data = 'test string';
        $serialized = $serializer->serialize($data);
        $this->assertIsString($serialized);
        $this->assertEquals($data, $serializer->deserialize($serialized));
    }

    public function testNativeSerializerWithArray(): void
    {
        $serializer = new NativeSerializer();

        $data = ['key1' => 'value1', 'key2' => 42, 'nested' => ['a' => 'b']];
        $serialized = $serializer->serialize($data);
        $this->assertEquals($data, $serializer->deserialize($serialized));
    }

    public function testNativeSerializerWithObject(): void
    {
        $serializer = new NativeSerializer();

        $data = (object)['prop1' => 'value1', 'prop2' => 42];
        $serialized = $serializer->serialize($data);
        $deserialized = $serializer->deserialize($serialized);

        $this->assertEquals($data->prop1, $deserialized->prop1);
        $this->assertEquals($data->prop2, $deserialized->prop2);
    }

    public function testJsonSerializerWithPrimitives(): void
    {
        $serializer = new JsonSerializer();

        $this->assertEquals('json', $serializer->name());

        $data = 'test string';
        $serialized = $serializer->serialize($data);
        $this->assertIsString($serialized);
        $this->assertEquals($data, $serializer->deserialize($serialized));
    }

    public function testJsonSerializerWithArray(): void
    {
        $serializer = new JsonSerializer();

        $data = ['key1' => 'value1', 'key2' => 42, 'nested' => ['a' => 'b']];
        $serialized = $serializer->serialize($data);
        $this->assertEquals($data, $serializer->deserialize($serialized));
    }

    public function testJsonSerializerWithNumbers(): void
    {
        $serializer = new JsonSerializer();

        $data = ['int' => 42, 'float' => 3.14, 'bool' => true, 'null' => null];
        $serialized = $serializer->serialize($data);
        $deserialized = $serializer->deserialize($serialized);

        $this->assertSame(42, $deserialized['int']);
        $this->assertSame(3.14, $deserialized['float']);
        $this->assertSame(true, $deserialized['bool']);
        $this->assertNull($deserialized['null']);
    }

    public function testJsonSerializerWithUnicode(): void
    {
        $serializer = new JsonSerializer();

        $data = ['persian' => 'سلام', 'emoji' => '🎉', 'mixed' => 'Hello سلام 👋'];
        $serialized = $serializer->serialize($data);
        $this->assertEquals($data, $serializer->deserialize($serialized));
    }

    public function testJsonSerializerThrowsOnInvalidJson(): void
    {
        $serializer = new JsonSerializer();

        $this->expectException(\JsonException::class);
        $serializer->deserialize('invalid json {]');
    }
}
