<?php

namespace App\Services;

class RateLimitService
{
    private $redis;

    public function __construct()
    {
        try {
            $this->redis = new \Redis();
            $this->redis->connect(REDIS_HOST, REDIS_PORT);
            $this->redis->select(REDIS_DB);
        } catch (\Exception $e) {
            $this->redis = null;
        }
    }

    private function isEnabled()
    {
        return $this->redis !== null;
    }

    public function check($key, $limit, $interval)
    {
        if ($limit <= 0) {
            return true;
        }

        if (!$this->isEnabled()) {
            return true; // skip if redis unavailable
        }

        $count = $this->redis->incr($key);
        if ($count == 1) {
            $this->redis->expire($key, $interval);
        }

        return $count <= $limit;
    }

    public function remaining($key, $limit)
    {
        if ($limit <= 0) return PHP_INT_MAX;
        if (!$this->isEnabled()) return $limit;
        $count = $this->redis->get($key);
        return max(0, $limit - ($count ?: 0));
    }
}
