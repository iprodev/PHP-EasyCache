<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Cache;

use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Clock\FrozenClock;
use Iprodev\EasyCache\Lock\NullLockProvider;
use Iprodev\EasyCache\Serialization\JsonSerializer;
use Iprodev\EasyCache\Serialization\NativeSerializer;
use Iprodev\EasyCache\Serialization\SerializerInterface;
use Iprodev\EasyCache\Tests\Support\ArrayStorage;
use Iprodev\EasyCache\Tests\Support\MemoryLogger;
use Iprodev\EasyCache\Tests\Support\WakeupProbe;
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    private const KEY = 'a-very-secret-signing-key-0123456789';

    private FrozenClock $clock;
    private ArrayStorage $storage;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->storage = new ArrayStorage($this->clock);
        WakeupProbe::$wakeups = 0;
    }

    private function cache(
        ?SerializerInterface $serializer = null,
        ?string $key = null,
        ?MemoryLogger $logger = null
    ): MultiTierCache
    {
        return new MultiTierCache(
            [$this->storage],
            $serializer,
            null,
            3600,
            $logger,
            null,
            $this->clock,
            $key,
            new NullLockProvider()
        );
    }

    /**
     * Build an EC03 entry by hand, as an attacker with write access to the storage could.
     */
    private function forgeEntry(string $serializer, string $body): string
    {
        return 'EC03' . chr(0) . pack('JNN', 0, 0, 0)
            . chr(strlen($serializer)) . $serializer
            . chr(4) . 'none'
            . $body;
    }

    public function testHeaderIsNeverUnserialized(): void
    {
        // Pre-3.1 attack: a serialized object in the record envelope.
        $envelope = ['e' => 0, 'v' => '"x"', 'sn' => 'json', 'p' => new WakeupProbe()];
        $legacy = 'EC02' . chr(0) . chr(4) . 'json' . serialize($envelope);
        $this->storage->rawSet('legacy', $legacy);

        $this->assertSame('miss', $this->cache(new JsonSerializer())->get('legacy', 'miss'));
        $this->assertSame(0, WakeupProbe::$wakeups);
    }

    public function testJsonSerializerNeverInstantiatesObjects(): void
    {
        $this->storage->rawSet('k', $this->forgeEntry('json', serialize(new WakeupProbe())));
        $this->assertSame('miss', $this->cache(new JsonSerializer())->get('k', 'miss'));
        $this->assertSame(0, WakeupProbe::$wakeups);
    }

    public function testNativeSerializerAllowedClassesBlocksObjectInjection(): void
    {
        $this->storage->rawSet('k', $this->forgeEntry('php', serialize(['x' => new WakeupProbe()])));
        $value = $this->cache(new NativeSerializer(false))->get('k');

        $this->assertSame(0, WakeupProbe::$wakeups);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $value['x']);
    }

    public function testSignedEntriesRoundTrip(): void
    {
        $cache = $this->cache(null, self::KEY);
        $cache->set('k', ['secure' => true]);
        $this->assertSame(['secure' => true], $cache->get('k'));
        $this->assertTrue($cache->has('k'));
    }

    public function testUnsignedEntriesAreRejectedWhenSigningIsEnabled(): void
    {
        $this->storage->rawSet('k', $this->forgeEntry('php', serialize(new WakeupProbe())));
        $logger = new MemoryLogger();

        $this->assertSame('miss', $this->cache(null, self::KEY, $logger)->get('k', 'miss'));
        $this->assertSame(0, WakeupProbe::$wakeups);
        $this->assertTrue($logger->hasMessageContaining('unsigned'));
    }

    public function testTamperedEntriesAreRejected(): void
    {
        $cache = $this->cache(null, self::KEY);
        $cache->set('k', 'original');

        $raw = (string) $this->storage->rawGet('k');
        $tampered = substr($raw, 0, -strlen(serialize('original'))) . serialize('modified');
        $this->storage->rawSet('k', $tampered);
        $this->assertSame('miss', $cache->get('k', 'miss'));

        // Changing the expiry in the header also breaks the signature.
        $cache->set('k2', 'v', 10);
        $raw = (string) $this->storage->rawGet('k2');
        $this->storage->rawSet('k2', substr($raw, 0, 5) . pack('J', 0) . substr($raw, 13));
        $this->assertSame('miss', $cache->get('k2', 'miss'));
    }

    public function testEntriesSignedWithAnotherKeyAreRejected(): void
    {
        $this->cache(null, self::KEY)->set('k', 'v');
        $this->assertSame('miss', $this->cache(null, str_repeat('x', 32))->get('k', 'miss'));
    }

    public function testShortSigningKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cache(null, 'short');
    }

    public function testCorruptNativePayloadIsAMissNotFalse(): void
    {
        $this->storage->rawSet('k', $this->forgeEntry('php', '{"json":"not php"}'));
        $this->assertSame('miss', $this->cache()->get('k', 'miss'));
    }
}
