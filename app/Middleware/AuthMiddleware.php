<?php

namespace App\Middleware;

use App\Services\JWTService;
use App\Services\MongoDatabase;

class AuthMiddleware
{
    public static function verify()
    {
        $headers = apache_request_headers();
        $authHeader = $headers['Authorization'] ?? '';

        if (empty($authHeader)) {
            return self::unauthorized('No authorization header');
        }

        $parts = explode(' ', $authHeader);
        if (count($parts) !== 2 || $parts[0] !== 'Bearer') {
            return self::unauthorized('Invalid authorization header');
        }

        $token = $parts[1];
        $result = JWTService::verifyToken($token);

        if (!$result['valid']) {
            return self::unauthorized($result['error']);
        }

        return ['authenticated' => true, 'user' => $result['data']];
    }

    public static function checkRole($requiredRole)
    {
        $auth = self::verify();

        if (!$auth['authenticated']) {
            return $auth;
        }

        // Resolve role from database so role updates apply immediately.
        $db = MongoDatabase::getInstance();
        $user = $db->findOne('users', ['id' => intval($auth['user']['userId'])], ['projection' => ['role' => 1]]);

        if (!$user) {
            return ['authenticated' => false, 'error' => 'User not found'];
        }

        $userRole = strtoupper((string) ($user['role'] ?? ''));
        $auth['user']['role'] = $userRole;

        // Admin has access to everything
        if ($userRole === 'ADMIN') {
            return $auth;
        }

        if (!is_array($requiredRole)) {
            $requiredRole = [$requiredRole];
        }

        $requiredRole = array_map(function ($role) {
            return strtoupper((string) $role);
        }, $requiredRole);

        if (!in_array($userRole, $requiredRole, true)) {
            return ['authenticated' => false, 'error' => 'Insufficient permissions'];
        }

        return $auth;
    }

    private static function unauthorized($message = 'Unauthorized')
    {
        return ['authenticated' => false, 'error' => $message];
    }
}
