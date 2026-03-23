<?php

namespace App\Services;

class CacheService
{
    private static $instance = null;
    private $redis = null;
    private $enabled = false;

    private function __construct()
    {
        try {
            $this->redis = new \Redis();
            $this->redis->connect(REDIS_HOST, REDIS_PORT);
            $this->redis->select(REDIS_DB);
            $this->enabled = true;
        } catch (\Exception $e) {
            error_log("Redis connection failed: " . $e->getMessage());
            $this->enabled = false;
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get($key)
    {
        if (!$this->enabled) return null;

        try {
            $value = $this->redis->get($key);
            if ($value === false) return null;
            return json_decode($value, true);
        } catch (\Exception $e) {
            error_log("Cache get error: " . $e->getMessage());
            return null;
        }
    }

    public function set($key, $value, $ttl = 3600)
    {
        if (!$this->enabled) return false;

        try {
            return $this->redis->setex($key, $ttl, json_encode($value));
        } catch (\Exception $e) {
            error_log("Cache set error: " . $e->getMessage());
            return false;
        }
    }

    public function delete($key)
    {
        if (!$this->enabled) return false;

        try {
            return $this->redis->del($key) > 0;
        } catch (\Exception $e) {
            error_log("Cache delete error: " . $e->getMessage());
            return false;
        }
    }

    public function flush()
    {
        if (!$this->enabled) return false;

        try {
            return $this->redis->flushDB();
        } catch (\Exception $e) {
            error_log("Cache flush error: " . $e->getMessage());
            return false;
        }
    }

    public function isEnabled()
    {
        return $this->enabled;
    }

    public function __clone()
    {
        throw new \Exception("Cannot clone singleton");
    }
}
