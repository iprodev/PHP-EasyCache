<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Iprodev\EasyCache\Storage\PdoStorage;

final class PdoStorageSqliteTest extends TestCase
{
    private string $db;

    protected function setUp(): void
    {
        $this->db = sys_get_temp_dir() . '/ec-v3-' . bin2hex(random_bytes(4)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->db)) { @unlink($this->db); }
    }

    public function testSetGet(): void
    {
        $pdo = new PDO('sqlite:' . $this->db);
        $s = new PdoStorage($pdo);
        $s->ensureTable();
        $this->assertTrue($s->set('a', 'payload', 2));
        $this->assertSame('payload', $s->get('a'));
        sleep(3);
        $this->assertNull($s->get('a'));
    }
}
