<?php

namespace App\Controllers;

use App\Services\Database;
use App\Services\JWTService;
use App\Services\Response;

class AuthController
{
    public static function login()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        // rate limiting using Redis
        if (RATE_LIMIT_ENABLED && RATE_LIMIT_LOGIN > 0) {
            $rate = new \App\Services\RateLimitService();
            $key = 'rl_login_' . ($_SERVER['REMOTE_ADDR'] ?? 'anon');
            if (!$rate->check($key, RATE_LIMIT_LOGIN, 3600)) {
                return Response::send(Response::error('Too many login attempts, please try again later', 429));
            }
        }

        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['email']) || !isset($input['password'])) {
            return Response::send(Response::error('Email and password required', 400));
        }

        $email = trim($input['email']);
        $password = $input['password'];
        $displayNameInput = isset($input['displayName']) ? trim((string) $input['displayName']) : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::send(Response::error('Invalid email format', 400));
        }

        $db = Database::getInstance();
        $user = $db->fetch(
            'SELECT id, email, display_name, password_hash, role, bio, avatar_url FROM users WHERE email = ?',
            [$email]
        );

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return Response::send(Response::error('Invalid credentials', 401));
        }

        $token = JWTService::generateToken($user['id'], $user['email'], $user['role']);

        return Response::send(Response::success([
            'token' => $token,
            'user' => self::formatUserPayload($user)
        ], 'Login successful', 200));
    }

    public static function me()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = \App\Middleware\AuthMiddleware::verify();

        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 401));
        }

        $db = Database::getInstance();
        $user = $db->fetch(
            'SELECT id, email, display_name AS displayName, role, bio, avatar_url AS avatarUrl FROM users WHERE id = ?',
            [$auth['user']['userId']]
        );

        if (!$user) {
            return Response::send(Response::error('User not found', 404));
        }

        return Response::send(Response::success(self::formatUserPayload($user)));
    }

    public static function register()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['email']) || !isset($input['password'])) {
            return Response::send(Response::error('Email and password required', 400));
        }

        $email = trim($input['email']);
        $password = $input['password'];
        $displayNameInput = isset($input['displayName']) ? trim((string) $input['displayName']) : '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::send(Response::error('Invalid email format', 400));
        }

        if (strlen($password) < 6) {
            return Response::send(Response::error('Password must be at least 6 characters', 400));
        }

        if ($displayNameInput !== '' && strlen($displayNameInput) < 2) {
            return Response::send(Response::error('Name must be at least 2 characters', 400));
        }

        if (strlen($displayNameInput) > 60) {
            return Response::send(Response::error('Name must be at most 60 characters', 400));
        }

        $db = Database::getInstance();
        // check for existing user
        $existing = $db->fetch('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing) {
            return Response::send(Response::error('Email already registered', 409));
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = 'CONSUMER';
        $displayName = $displayNameInput !== '' ? $displayNameInput : explode('@', $email)[0];
        $db->execute(
            'INSERT INTO users (email, display_name, password_hash, role) VALUES (?, ?, ?, ?)',
            [$email, $displayName, $hash, $role]
        );
        $userId = $db->lastInsertId();

        $token = JWTService::generateToken($userId, $email, $role);

        $user = $db->fetch(
            'SELECT id, email, display_name AS displayName, role, bio, avatar_url AS avatarUrl FROM users WHERE id = ?',
            [$userId]
        );

        return Response::send(Response::success([
            'token' => $token,
            'user' => self::formatUserPayload($user)
        ], 'Registration successful', 201));
    }

    private static function formatUserPayload($user)
    {
        $email = $user['email'] ?? '';
        $displayName = $user['displayName'] ?? $user['display_name'] ?? null;

        return [
            'id' => intval($user['id'] ?? 0),
            'email' => $email,
            'role' => strtoupper((string) ($user['role'] ?? 'CONSUMER')),
            'displayName' => $displayName && trim((string) $displayName) !== '' ? $displayName : explode('@', $email)[0],
            'bio' => $user['bio'] ?? '',
            'avatarUrl' => $user['avatarUrl'] ?? $user['avatar_url'] ?? null
        ];
    }

    public static function googleOAuth()
    {
        // Check if Google OAuth is configured
        if (!GOOGLE_CLIENT_ID || !GOOGLE_CLIENT_SECRET) {
            return Response::send(Response::error('Google OAuth is not configured. Please contact the administrator.', 400));
        }

        // Step 1: Check if this is a callback
        if (isset($_GET['code'])) {
            return self::handleGoogleCallback($_GET['code'], GOOGLE_CLIENT_SECRET, GOOGLE_REDIRECT_URI);
        }

        // Step 2: Redirect to Google OAuth consent screen
        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;

        $googleAuthUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => GOOGLE_CLIENT_ID,
            'redirect_uri' => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'offline'
        ]);

        header('Location: ' . $googleAuthUrl);
        exit;
    }

    private static function handleGoogleCallback($code, $clientSecret, $redirectUri)
    {
        // Exchange authorization code for access token
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $tokenData = [
            'code' => $code,
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $tokenUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($tokenData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $tokenResponse = json_decode($response, true);

        if (!isset($tokenResponse['access_token'])) {
            return Response::send(Response::error('Failed to get access token from Google', 400));
        }

        // Get user info from Google
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://www.googleapis.com/oauth2/v2/userinfo',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokenResponse['access_token']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $userInfoResponse = curl_exec($ch);
        curl_close($ch);

        $googleUser = json_decode($userInfoResponse, true);

        if (!isset($googleUser['email'])) {
            return Response::send(Response::error('Failed to get user info from Google', 400));
        }

        // Create or update user in database
        $db = Database::getInstance();
        $email = $googleUser['email'];
        $googleId = $googleUser['id'];
        $displayName = $googleUser['name'] ?? explode('@', $email)[0];
        $avatarUrl = $googleUser['picture'] ?? null;

        // Check if user exists
        $user = $db->fetch('SELECT id, email, display_name, role FROM users WHERE email = ?', [$email]);

        if (!$user) {
            // Create new user from Google signup
            $db->execute(
                'INSERT INTO users (email, display_name, role, avatar_url, password_hash) VALUES (?, ?, ?, ?, ?)',
                [$email, $displayName, 'CONSUMER', $avatarUrl, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]
            );
            $userId = $db->lastInsertId();
            $role = 'CONSUMER';
        } else {
            // Update existing user's avatar if available
            $userId = $user['id'];
            $role = $user['role'];
            if ($avatarUrl) {
                $db->execute('UPDATE users SET avatar_url = ? WHERE id = ?', [$avatarUrl, $userId]);
            }
        }

        $token = JWTService::generateToken($userId, $email, $role);

        $userData = $db->fetch(
            'SELECT id, email, display_name AS displayName, role, bio, avatar_url AS avatarUrl FROM users WHERE id = ?',
            [$userId]
        );

        // Redirect to frontend with token (using POST to be safer with tokens)
        header('Location: /consumer-feed.html?token=' . urlencode($token));
        exit;
    }
}
