<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\Database;
use App\Services\Response;

class UserController
{
    public static function getProfile($userId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $userId = intval($userId);
        $db = Database::getInstance();

        // Get user
        $user = $db->fetch(
            'SELECT id, email, role, display_name AS displayName, bio, avatar_url AS avatarUrl, created_at FROM users WHERE id = ?',
            [$userId]
        );

        if (!$user) {
            return Response::send(Response::error('User not found', 404));
        }

        $postCount = $db->fetch(
            'SELECT COUNT(*) as count FROM media WHERE creator_id = ?',
            [$userId]
        )['count'];

        // Get user's posts
        $posts = $db->fetchAll(
            'SELECT id, title, caption, url, type, created_at
             FROM media
             WHERE creator_id = ?
             ORDER BY created_at DESC
             LIMIT 20',
            [$userId]
        );

        // Get like count on all user's posts
        $likeCount = $db->fetch(
            'SELECT COUNT(*) as count FROM likes l 
             JOIN media m ON l.media_id = m.id 
             WHERE m.creator_id = ?',
            [$userId]
        );

        // Get comment count on all user's posts
        $commentCount = $db->fetch(
            'SELECT COUNT(*) as count FROM comments c 
             JOIN media m ON c.media_id = m.id 
             WHERE m.creator_id = ?',
            [$userId]
        );

        $user['displayName'] = self::resolveDisplayName($user['displayName'] ?? null, $user['email']);
        $user['bio'] = $user['bio'] ?? '';
        $user['posts'] = $posts;
        $user['totalLikes'] = intval($likeCount['count']);
        $user['totalComments'] = intval($commentCount['count']);
        $user['postCount'] = intval($postCount);

        return Response::send(Response::success($user));
    }

    public static function updateProfile()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::verify();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 401));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            return Response::send(Response::error('Invalid payload', 400));
        }

        $updates = [];

        if (array_key_exists('displayName', $input)) {
            $displayName = trim((string) $input['displayName']);
            if ($displayName !== '' && strlen($displayName) > 120) {
                return Response::send(Response::error('Display name must be 120 characters or fewer', 400));
            }
            $updates['display_name'] = $displayName !== '' ? $displayName : null;
        }

        if (array_key_exists('bio', $input)) {
            $bio = trim((string) $input['bio']);
            if (strlen($bio) > 500) {
                return Response::send(Response::error('Bio must be 500 characters or fewer', 400));
            }
            $updates['bio'] = $bio !== '' ? $bio : null;
        }

        if (empty($updates)) {
            return Response::send(Response::success(self::fetchUserProfile($auth['user']['userId']), 'No changes applied'));
        }

        $db = Database::getInstance();

        try {
            $db->update('users', $updates, 'id = ?', [$auth['user']['userId']]);
            return Response::send(Response::success(self::fetchUserProfile($auth['user']['userId']), 'Profile updated successfully'));
        } catch (\Exception $e) {
            error_log('User updateProfile error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to update profile', 500));
        }
    }

    public static function uploadAvatar()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::verify();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 401));
        }

        if (!isset($_FILES['avatar'])) {
            return Response::send(Response::error('Avatar file is required', 400));
        }

        $file = $_FILES['avatar'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return Response::send(Response::error('Avatar upload failed', 400));
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            return Response::send(Response::error('Avatar must be 5MB or smaller', 400));
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            return Response::send(Response::error('Avatar must be an image file', 400));
        }

        if (!is_dir(UPLOAD_DIR)) {
            mkdir(UPLOAD_DIR, 0755, true);
        }

        $db = Database::getInstance();
        $existing = $db->fetch('SELECT avatar_url FROM users WHERE id = ?', [$auth['user']['userId']]);

        $filename = 'avatar_' . intval($auth['user']['userId']) . '_' . uniqid() . '.' . $ext;
        $destination = UPLOAD_DIR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return Response::send(Response::error('Failed to save avatar file', 500));
        }

        try {
            if (!empty($existing['avatar_url']) && strpos($existing['avatar_url'], '/assets/uploads/') === 0) {
                $oldFile = UPLOAD_DIR . basename($existing['avatar_url']);
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }

            $avatarUrl = '/assets/uploads/' . $filename;
            $db->update('users', ['avatar_url' => $avatarUrl], 'id = ?', [$auth['user']['userId']]);

            return Response::send(Response::success(self::fetchUserProfile($auth['user']['userId']), 'Avatar updated successfully'));
        } catch (\Exception $e) {
            error_log('User uploadAvatar error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to update avatar', 500));
        }
    }

    public static function searchUsers()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $query = isset($_GET['q']) ? trim($_GET['q']) : '';
        if (empty($query) || strlen($query) < 1) {
            return Response::send(Response::error('Query too short', 400));
        }

        $db = Database::getInstance();

        // Prefix matches first, then any-position matches
        $users = $db->fetchAll(
            'SELECT id, email, role, display_name AS displayName, avatar_url AS avatarUrl
             FROM users
             WHERE email LIKE ? OR display_name LIKE ?
             ORDER BY
                CASE
                    WHEN display_name LIKE ? THEN 0
                    WHEN email LIKE ? THEN 1
                    ELSE 2
                END,
                COALESCE(NULLIF(display_name, ""), email) ASC
             LIMIT 10',
            [
                '%' . $query . '%',
                '%' . $query . '%',
                $query . '%',
                $query . '%'
            ]
        );

        return Response::send(Response::success($users));
    }

    public static function getPeoplePresent()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::verify();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 401));
        }

        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 8;
        $limit = max(1, min(20, $limit));

        $hours = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
        $hours = max(1, min(168, $hours));

        $db = Database::getInstance();

        $activitySql =
            'SELECT creator_id AS user_id, created_at AS activity_at FROM media WHERE created_at >= (NOW() - INTERVAL ' . intval($hours) . ' HOUR) ' .
            'UNION ALL ' .
            'SELECT user_id, created_at AS activity_at FROM comments WHERE created_at >= (NOW() - INTERVAL ' . intval($hours) . ' HOUR) ' .
            'UNION ALL ' .
            'SELECT user_id, created_at AS activity_at FROM likes WHERE created_at >= (NOW() - INTERVAL ' . intval($hours) . ' HOUR) ' .
            'UNION ALL ' .
            'SELECT user_id, created_at AS activity_at FROM ratings WHERE created_at >= (NOW() - INTERVAL ' . intval($hours) . ' HOUR)';

        $users = $db->fetchAll(
            'SELECT u.id,
                    u.email,
                    u.role,
                    COALESCE(NULLIF(u.display_name, ""), SUBSTRING_INDEX(u.email, "@", 1)) AS displayName,
                    u.avatar_url AS avatarUrl,
                    MAX(a.activity_at) AS lastActiveAt,
                    TIMESTAMPDIFF(MINUTE, MAX(a.activity_at), NOW()) AS minutesAgo
             FROM users u
             JOIN (' . $activitySql . ') a ON a.user_id = u.id
             GROUP BY u.id, u.email, u.role, u.display_name, u.avatar_url
             ORDER BY lastActiveAt DESC
             LIMIT ' . intval($limit)
        );

        if (empty($users)) {
            $users = $db->fetchAll(
                'SELECT id,
                        email,
                        role,
                        COALESCE(NULLIF(display_name, ""), SUBSTRING_INDEX(email, "@", 1)) AS displayName,
                        avatar_url AS avatarUrl,
                        updated_at AS lastActiveAt,
                        TIMESTAMPDIFF(MINUTE, updated_at, NOW()) AS minutesAgo
                 FROM users
                 ORDER BY updated_at DESC
                 LIMIT ' . intval($limit)
            );
        }

        foreach ($users as &$user) {
            $user['minutesAgo'] = max(0, intval($user['minutesAgo'] ?? 0));
            $user['isActive'] = $user['minutesAgo'] <= 5;
        }

        return Response::send(Response::success($users));
    }

    private static function fetchUserProfile($userId)
    {
        $db = Database::getInstance();
        $user = $db->fetch(
            'SELECT id, email, role, display_name AS displayName, bio, avatar_url AS avatarUrl, created_at
             FROM users
             WHERE id = ?',
            [$userId]
        );

        if (!$user) {
            return null;
        }

        $user['displayName'] = self::resolveDisplayName($user['displayName'] ?? null, $user['email']);
        $user['bio'] = $user['bio'] ?? '';

        return $user;
    }

    private static function resolveDisplayName($displayName, $email)
    {
        if ($displayName !== null && trim((string) $displayName) !== '') {
            return $displayName;
        }

        return explode('@', (string) $email)[0];
    }
}
