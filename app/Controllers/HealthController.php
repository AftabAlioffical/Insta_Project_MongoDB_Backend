<?php

namespace App\Controllers;

use App\Services\Response;
use App\Services\Database;
use App\Services\CacheService;

class HealthController
{
    public static function health()
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'checks' => []
        ];

        // Check database
        try {
            $db = Database::getInstance();
            $db->fetch('SELECT 1');
            $health['checks']['database'] = ['status' => 'ok'];
        } catch (\Exception $e) {
            $health['checks']['database'] = ['status' => 'error', 'error' => $e->getMessage()];
            $health['status'] = 'unhealthy';
        }

        // Check cache
        $cache = CacheService::getInstance();
        $health['checks']['cache'] = ['status' => $cache->isEnabled() ? 'ok' : 'degraded'];

        return Response::send(Response::success($health));
    }

    public static function ready()
    {
        try {
            $db = Database::getInstance();
            $db->fetch('SELECT 1');

            $ready = [
                'ready' => true,
                'timestamp' => date('c')
            ];

            return Response::send(Response::success($ready));
        } catch (\Exception $e) {
            return Response::send(Response::error('Service not ready', 503));
        }
    }
}
