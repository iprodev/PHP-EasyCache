<?php
namespace Iprodev\EasyCache;

class EasyCache {
    private $cache_path;
    private $default_cache_time;
    private $cache_extension;
    private $compression_level;
    private $logger;

    public function __construct($config = []) {
        $this->cache_path = $config['cache_path'] ?? __DIR__ . '/../cache/';
        $this->default_cache_time = $config['cache_time'] ?? 3600;
        $this->cache_extension = $config['cache_extension'] ?? '.cache';
        $this->compression_level = $config['compression_level'] ?? 0;
        $this->logger = $config['logger'] ?? null;

        if (!file_exists($this->cache_path)) {
            mkdir($this->cache_path, 0777, true);
        }
    }

    private function sanitize_key($key) {
        return preg_replace('/[^A-Za-z0-9\-_]/', '_', $key);
    }

    private function get_cache_file($key) {
        $key = $this->sanitize_key($key);
        return $this->cache_path . md5($key) . $this->cache_extension;
    }

    public function set($key, $data, $ttl = null) {
        $file = $this->get_cache_file($key);
        $ttl = $ttl ?? $this->default_cache_time;
        $content = [
            'expires' => time() + $ttl,
            'data' => $data
        ];

        $encoded = serialize($content);
        if ($this->compression_level > 0) {
            $encoded = gzcompress($encoded, $this->compression_level);
        }

        $fp = fopen($file, 'w');
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $encoded);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    public function get($key) {
        $file = $this->get_cache_file($key);
        if (!file_exists($file)) return null;

        $content = file_get_contents($file);
        if ($this->compression_level > 0) {
            $content = @gzuncompress($content);
        }

        $data = @unserialize($content);
        if (!$data || time() > $data['expires']) {
            unlink($file);
            return null;
        }

        return $data['data'];
    }

    public function flush_expired() {
        foreach (glob($this->cache_path . '/*' . $this->cache_extension) as $file) {
            $content = file_get_contents($file);
            $decoded = @unserialize(@gzuncompress($content));
            if ($decoded && isset($decoded['expires']) && $decoded['expires'] < time()) {
                unlink($file);
            }
        }
    }

    public function flush() {
        foreach (glob($this->cache_path . '/*' . $this->cache_extension) as $file) {
            unlink($file);
        }
    }
}
?>