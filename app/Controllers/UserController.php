<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\MongoDatabase;
use App\Services\Response;

class UserController
{
    public static function getProfile($userId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $userId = intval($userId);
        $db = MongoDatabase::getInstance();

        // Get user
        $rawUser = $db->findOne('users', ['id' => $userId], [
            'projection' => ['id' => 1, 'email' => 1, 'role' => 1, 'display_name' => 1, 'bio' => 1, 'avatar_url' => 1, 'created_at' => 1]
        ]);

        $user = self::toProfileUser($rawUser);

        if (!$user) {
            return Response::send(Response::error('User not found', 404));
        }

        $postCount = $db->count('media', ['creator_id' => $userId]);

        // Get user's posts
        $posts = $db->findMany('media', ['creator_id' => $userId], [
            'projection' => ['id' => 1, 'title' => 1, 'caption' => 1, 'url' => 1, 'type' => 1, 'created_at' => 1],
            'sort' => ['id' => -1],
            'limit' => 20
        ]);

        // Get like count on all user's posts
        $likeCount = 0;
        $commentCount = 0;
        foreach ($posts as $post) {
            $postId = intval($post['id'] ?? 0);
            $likeCount += $db->count('likes', ['media_id' => $postId]);
            $commentCount += $db->count('comments', ['media_id' => $postId]);
        }

        $user['displayName'] = self::resolveDisplayName($user['displayName'] ?? null, $user['email']);
        $user['bio'] = $user['bio'] ?? '';
        $user['posts'] = $posts;
        $user['totalLikes'] = intval($likeCount);
        $user['totalComments'] = intval($commentCount);
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

        $db = MongoDatabase::getInstance();

        try {
            $db->updateOne('users', ['id' => intval($auth['user']['userId'])], $updates);
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

        $db = MongoDatabase::getInstance();
        $existing = $db->findOne('users', ['id' => intval($auth['user']['userId'])], ['projection' => ['avatar_url' => 1]]);

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
            $db->updateOne('users', ['id' => intval($auth['user']['userId'])], ['avatar_url' => $avatarUrl]);

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

        $db = MongoDatabase::getInstance();
        $candidates = $db->findMany('users', [
            '$or' => [
                ['email' => ['$regex' => $query, '$options' => 'i']],
                ['display_name' => ['$regex' => $query, '$options' => 'i']]
            ]
        ], [
            'projection' => ['id' => 1, 'email' => 1, 'role' => 1, 'display_name' => 1, 'avatar_url' => 1],
            'limit' => 50
        ]);

        usort($candidates, function ($a, $b) use ($query) {
            $aDisplay = (string) ($a['display_name'] ?? '');
            $bDisplay = (string) ($b['display_name'] ?? '');
            $aEmail = (string) ($a['email'] ?? '');
            $bEmail = (string) ($b['email'] ?? '');

            $aRank = (stripos($aDisplay, $query) === 0) ? 0 : ((stripos($aEmail, $query) === 0) ? 1 : 2);
            $bRank = (stripos($bDisplay, $query) === 0) ? 0 : ((stripos($bEmail, $query) === 0) ? 1 : 2);

            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }

            $aKey = strtolower(trim($aDisplay) !== '' ? $aDisplay : $aEmail);
            $bKey = strtolower(trim($bDisplay) !== '' ? $bDisplay : $bEmail);
            return strcmp($aKey, $bKey);
        });

        $users = [];
        foreach (array_slice($candidates, 0, 10) as $u) {
            $users[] = [
                'id' => intval($u['id'] ?? 0),
                'email' => $u['email'] ?? '',
                'role' => $u['role'] ?? 'CONSUMER',
                'displayName' => $u['display_name'] ?? null,
                'avatarUrl' => $u['avatar_url'] ?? null
            ];
        }

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

        $roleAuth = AuthMiddleware::checkRole(['ADMIN', 'CREATOR']);
        if (!$roleAuth['authenticated']) {
            return Response::send(Response::error($roleAuth['error'], 403));
        }

        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 8;
        $limit = max(1, min(20, $limit));

        $hours = isset($_GET['hours']) ? intval($_GET['hours']) : 24;
        $hours = max(1, min(168, $hours));

        $db = MongoDatabase::getInstance();

        $threshold = strtotime('-' . intval($hours) . ' hours');
        $activity = [];

        foreach ($db->findMany('media', [], ['projection' => ['creator_id' => 1, 'created_at' => 1]]) as $m) {
            self::collectActivity($activity, intval($m['creator_id'] ?? 0), $m['created_at'] ?? null, $threshold);
        }
        foreach ($db->findMany('comments', [], ['projection' => ['user_id' => 1, 'created_at' => 1]]) as $c) {
            self::collectActivity($activity, intval($c['user_id'] ?? 0), $c['created_at'] ?? null, $threshold);
        }
        foreach ($db->findMany('likes', [], ['projection' => ['user_id' => 1, 'created_at' => 1]]) as $l) {
            self::collectActivity($activity, intval($l['user_id'] ?? 0), $l['created_at'] ?? null, $threshold);
        }
        foreach ($db->findMany('ratings', [], ['projection' => ['user_id' => 1, 'created_at' => 1]]) as $r) {
            self::collectActivity($activity, intval($r['user_id'] ?? 0), $r['created_at'] ?? null, $threshold);
        }

        arsort($activity);

        $users = [];
        foreach (array_slice(array_keys($activity), 0, intval($limit)) as $activeUserId) {
            $u = $db->findOne('users', ['id' => intval($activeUserId)], [
                'projection' => ['id' => 1, 'email' => 1, 'role' => 1, 'display_name' => 1, 'avatar_url' => 1]
            ]);
            if (!$u) {
                continue;
            }

            $minutesAgo = intval(max(0, floor((time() - intval($activity[$activeUserId])) / 60)));
            $users[] = [
                'id' => intval($u['id']),
                'email' => $u['email'] ?? '',
                'role' => $u['role'] ?? 'CONSUMER',
                'displayName' => self::resolveDisplayName($u['display_name'] ?? null, $u['email'] ?? ''),
                'avatarUrl' => $u['avatar_url'] ?? null,
                'lastActiveAt' => date('Y-m-d H:i:s', intval($activity[$activeUserId])),
                'minutesAgo' => $minutesAgo
            ];
        }

        if (empty($users)) {
            $fallbackUsers = $db->findMany('users', [], [
                'projection' => ['id' => 1, 'email' => 1, 'role' => 1, 'display_name' => 1, 'avatar_url' => 1, 'updated_at' => 1],
                'sort' => ['id' => -1],
                'limit' => intval($limit)
            ]);

            foreach ($fallbackUsers as $u) {
                $updatedTs = strtotime((string) ($u['updated_at'] ?? ''));
                if ($updatedTs === false) {
                    $updatedTs = time();
                }

                $users[] = [
                    'id' => intval($u['id'] ?? 0),
                    'email' => $u['email'] ?? '',
                    'role' => $u['role'] ?? 'CONSUMER',
                    'displayName' => self::resolveDisplayName($u['display_name'] ?? null, $u['email'] ?? ''),
                    'avatarUrl' => $u['avatar_url'] ?? null,
                    'lastActiveAt' => date('Y-m-d H:i:s', $updatedTs),
                    'minutesAgo' => intval(max(0, floor((time() - $updatedTs) / 60)))
                ];
            }
        }

        foreach ($users as &$user) {
            $user['minutesAgo'] = max(0, intval($user['minutesAgo'] ?? 0));
            $user['isActive'] = $user['minutesAgo'] <= 5;
        }

        return Response::send(Response::success($users));
    }

    private static function fetchUserProfile($userId)
    {
        $db = MongoDatabase::getInstance();
        $raw = $db->findOne('users', ['id' => intval($userId)], [
            'projection' => ['id' => 1, 'email' => 1, 'role' => 1, 'display_name' => 1, 'bio' => 1, 'avatar_url' => 1, 'created_at' => 1]
        ]);

        $user = self::toProfileUser($raw);

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

    private static function toProfileUser($raw)
    {
        if (!$raw) {
            return null;
        }

        return [
            'id' => intval($raw['id'] ?? 0),
            'email' => $raw['email'] ?? '',
            'role' => $raw['role'] ?? 'CONSUMER',
            'displayName' => $raw['display_name'] ?? null,
            'bio' => $raw['bio'] ?? null,
            'avatarUrl' => $raw['avatar_url'] ?? null,
            'created_at' => $raw['created_at'] ?? null
        ];
    }

    private static function collectActivity(&$activity, $userId, $dateValue, $threshold)
    {
        if ($userId <= 0 || empty($dateValue)) {
            return;
        }

        $ts = strtotime((string) $dateValue);
        if ($ts === false || $ts < $threshold) {
            return;
        }

        if (!isset($activity[$userId]) || $ts > $activity[$userId]) {
            $activity[$userId] = $ts;
        }
    }
}
