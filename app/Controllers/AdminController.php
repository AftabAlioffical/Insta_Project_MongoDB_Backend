<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\CacheService;
use App\Services\Database;
use App\Services\Response;

class AdminController
{
    public static function createUser()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['email']) || !isset($input['password']) || !isset($input['role'])) {
            return Response::send(Response::error('Email, password, and role required', 400));
        }

        $email = trim($input['email']);
        $password = (string) $input['password'];
        $role = strtoupper((string) $input['role']);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::send(Response::error('Invalid email format', 400));
        }

        if (!in_array($role, ['ADMIN', 'CREATOR', 'CONSUMER'], true)) {
            return Response::send(Response::error('Invalid role', 400));
        }

        if (strlen($password) < 6) {
            return Response::send(Response::error('Password must be at least 6 characters', 400));
        }

        $db = Database::getInstance();
        $existing = $db->fetch('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing) {
            return Response::send(Response::error('Email already exists', 409));
        }

        try {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
            $userId = $db->insert('users', [
                'email' => $email,
                'password_hash' => $passwordHash,
                'role' => $role
            ]);

            $user = $db->fetch('SELECT id, email, role, created_at, updated_at FROM users WHERE id = ?', [$userId]);
            return Response::send(Response::success($user, 'User created successfully', 201));
        } catch (\Exception $e) {
            error_log('Admin createUser error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to create user', 500));
        }
    }

    public static function getUsers()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['limit']) ? min(200, max(1, intval($_GET['limit']))) : ITEMS_PER_PAGE;
        $offset = ($page - 1) * $perPage;
        $query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $roleFilter = isset($_GET['role']) ? strtoupper(trim((string) $_GET['role'])) : '';

        $db = Database::getInstance();

        $conditions = [];
        $params = [];

        if ($query !== '') {
            $needle = '%' . $query . '%';
            $conditions[] = '(email LIKE ? OR role LIKE ? OR display_name LIKE ?)';
            $params[] = $needle;
            $params[] = strtoupper($needle);
            $params[] = $needle;
        }

        if ($roleFilter !== '') {
            $conditions[] = 'role = ?';
            $params[] = $roleFilter;
        }

        $where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $total = $db->fetch("SELECT COUNT(*) as count FROM users $where", $params)['count'];

        $users = $db->fetchAll(
            "SELECT id, email, role, display_name AS displayName, avatar_url AS avatarUrl, created_at, updated_at
             FROM users
             $where
             ORDER BY created_at DESC
             LIMIT " . intval($perPage) . ' OFFSET ' . intval($offset),
            $params
        );

        return Response::send(Response::paginated($users, $total, $page, $perPage));
    }

    public static function updateUser($userId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $userId = intval($userId);
        if ($userId <= 0) {
            return Response::send(Response::error('Invalid user id', 400));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            return Response::send(Response::error('Invalid payload', 400));
        }

        $db = Database::getInstance();
        $user = $db->fetch('SELECT id, email, role FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return Response::send(Response::error('User not found', 404));
        }

        $updates = [];

        if (isset($input['email'])) {
            $email = trim((string) $input['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Response::send(Response::error('Invalid email format', 400));
            }

            $existing = $db->fetch('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $userId]);
            if ($existing) {
                return Response::send(Response::error('Email already exists', 409));
            }
            $updates['email'] = $email;
        }

        if (isset($input['role'])) {
            $role = strtoupper((string) $input['role']);
            if (!in_array($role, ['ADMIN', 'CREATOR', 'CONSUMER'], true)) {
                return Response::send(Response::error('Invalid role', 400));
            }

            if ($user['role'] === 'ADMIN' && $role !== 'ADMIN') {
                $adminCount = $db->fetch('SELECT COUNT(*) as count FROM users WHERE role = ?', ['ADMIN'])['count'];
                if (intval($adminCount) <= 1) {
                    return Response::send(Response::error('Cannot remove the last admin', 400));
                }
            }

            $updates['role'] = $role;
        }

        if (isset($input['password']) && trim((string) $input['password']) !== '') {
            $password = (string) $input['password'];
            if (strlen($password) < 6) {
                return Response::send(Response::error('Password must be at least 6 characters', 400));
            }
            $updates['password_hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
        }

        if (empty($updates)) {
            $fresh = $db->fetch('SELECT id, email, role, created_at, updated_at FROM users WHERE id = ?', [$userId]);
            return Response::send(Response::success($fresh, 'No changes applied'));
        }

        try {
            $db->update('users', $updates, 'id = ?', [$userId]);
            $fresh = $db->fetch('SELECT id, email, role, created_at, updated_at FROM users WHERE id = ?', [$userId]);
            return Response::send(Response::success($fresh, 'User updated successfully'));
        } catch (\Exception $e) {
            error_log('Admin updateUser error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to update user', 500));
        }
    }

    public static function deleteUser($userId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $userId = intval($userId);
        if ($userId <= 0) {
            return Response::send(Response::error('Invalid user id', 400));
        }

        if ($userId === intval($auth['user']['userId'])) {
            return Response::send(Response::error('Cannot delete your own account', 400));
        }

        $db = Database::getInstance();
        $user = $db->fetch('SELECT id, role FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return Response::send(Response::error('User not found', 404));
        }

        if ($user['role'] === 'ADMIN') {
            $adminCount = $db->fetch('SELECT COUNT(*) as count FROM users WHERE role = ?', ['ADMIN'])['count'];
            if (intval($adminCount) <= 1) {
                return Response::send(Response::error('Cannot delete the last admin', 400));
            }
        }

        try {
            $db->delete('users', 'id = ?', [$userId]);
            return Response::send(Response::success(null, 'User deleted successfully'));
        } catch (\Exception $e) {
            error_log('Admin deleteUser error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to delete user', 500));
        }
    }

    public static function getPosts()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['limit']) ? min(200, max(1, intval($_GET['limit']))) : ITEMS_PER_PAGE;
        $offset = ($page - 1) * $perPage;
        $query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

        $db = Database::getInstance();

        if ($query !== '') {
            $needle = '%' . $query . '%';
            $total = $db->fetch(
                'SELECT COUNT(*) as count
                 FROM media m
                 JOIN users u ON m.creator_id = u.id
                 WHERE m.title LIKE ? OR m.caption LIKE ? OR u.email LIKE ?',
                [$needle, $needle, $needle]
            )['count'];

            $posts = $db->fetchAll(
                'SELECT m.id, m.creator_id, u.email as creator_email, m.type, m.url, m.thumbnail_url,
                        m.title, m.caption, m.location, m.created_at, m.updated_at,
                        (SELECT COUNT(*) FROM comments c WHERE c.media_id = m.id) as comments_count,
                        (SELECT COUNT(*) FROM likes l WHERE l.media_id = m.id) as likes_count
                 FROM media m
                 JOIN users u ON m.creator_id = u.id
                 WHERE m.title LIKE ? OR m.caption LIKE ? OR u.email LIKE ?
                 ORDER BY m.created_at DESC
                 LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset),
                [$needle, $needle, $needle]
            );
        } else {
            $total = $db->fetch('SELECT COUNT(*) as count FROM media')['count'];
            $posts = $db->fetchAll(
                'SELECT m.id, m.creator_id, u.email as creator_email, m.type, m.url, m.thumbnail_url,
                        m.title, m.caption, m.location, m.created_at, m.updated_at,
                        (SELECT COUNT(*) FROM comments c WHERE c.media_id = m.id) as comments_count,
                        (SELECT COUNT(*) FROM likes l WHERE l.media_id = m.id) as likes_count
                 FROM media m
                 JOIN users u ON m.creator_id = u.id
                 ORDER BY m.created_at DESC
                 LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset)
            );
        }

        return Response::send(Response::paginated($posts, $total, $page, $perPage));
    }

    public static function updatePost($postId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $postId = intval($postId);
        if ($postId <= 0) {
            return Response::send(Response::error('Invalid post id', 400));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            return Response::send(Response::error('Invalid payload', 400));
        }

        $db = Database::getInstance();
        $existing = $db->fetch('SELECT id FROM media WHERE id = ?', [$postId]);
        if (!$existing) {
            return Response::send(Response::error('Post not found', 404));
        }

        $updates = [];
        if (array_key_exists('title', $input)) {
            $updates['title'] = trim((string) $input['title']);
        }
        if (array_key_exists('caption', $input)) {
            $updates['caption'] = trim((string) $input['caption']);
        }
        if (array_key_exists('location', $input)) {
            $updates['location'] = trim((string) $input['location']);
        }
        if (array_key_exists('type', $input)) {
            $type = strtolower((string) $input['type']);
            if (!in_array($type, ['image', 'video'], true)) {
                return Response::send(Response::error('Invalid media type', 400));
            }
            $updates['type'] = $type;
        }

        if (empty($updates)) {
            return Response::send(Response::error('No fields to update', 400));
        }

        try {
            $db->update('media', $updates, 'id = ?', [$postId]);
            CacheService::getInstance()->delete('media_' . $postId);

            $post = $db->fetch(
                'SELECT m.id, m.creator_id, u.email as creator_email, m.type, m.url, m.thumbnail_url,
                        m.title, m.caption, m.location, m.created_at, m.updated_at
                 FROM media m JOIN users u ON m.creator_id = u.id WHERE m.id = ?',
                [$postId]
            );

            return Response::send(Response::success($post, 'Post updated successfully'));
        } catch (\Exception $e) {
            error_log('Admin updatePost error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to update post', 500));
        }
    }

    public static function deletePost($postId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $postId = intval($postId);
        if ($postId <= 0) {
            return Response::send(Response::error('Invalid post id', 400));
        }

        $db = Database::getInstance();
        $existing = $db->fetch('SELECT id FROM media WHERE id = ?', [$postId]);
        if (!$existing) {
            return Response::send(Response::error('Post not found', 404));
        }

        try {
            $db->delete('media', 'id = ?', [$postId]);
            CacheService::getInstance()->delete('media_' . $postId);
            return Response::send(Response::success(null, 'Post deleted successfully'));
        } catch (\Exception $e) {
            error_log('Admin deletePost error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to delete post', 500));
        }
    }

    public static function getComments()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['limit']) ? min(200, max(1, intval($_GET['limit']))) : ITEMS_PER_PAGE;
        $offset = ($page - 1) * $perPage;
        $query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

        $db = Database::getInstance();

        if ($query !== '') {
            $needle = '%' . $query . '%';
            $total = $db->fetch(
                'SELECT COUNT(*) as count
                 FROM comments c
                 JOIN users u ON c.user_id = u.id
                 JOIN media m ON c.media_id = m.id
                 WHERE c.text LIKE ? OR u.email LIKE ? OR m.title LIKE ?',
                [$needle, $needle, $needle]
            )['count'];

            $comments = $db->fetchAll(
                'SELECT c.id, c.media_id, c.user_id, c.reply_to_id, c.text, c.created_at, c.updated_at,
                        u.email as user_email,
                        COALESCE(m.title, "Untitled") as media_title
                 FROM comments c
                 JOIN users u ON c.user_id = u.id
                 JOIN media m ON c.media_id = m.id
                 WHERE c.text LIKE ? OR u.email LIKE ? OR m.title LIKE ?
                 ORDER BY c.created_at DESC
                 LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset),
                [$needle, $needle, $needle]
            );
        } else {
            $total = $db->fetch('SELECT COUNT(*) as count FROM comments')['count'];
            $comments = $db->fetchAll(
                'SELECT c.id, c.media_id, c.user_id, c.reply_to_id, c.text, c.created_at, c.updated_at,
                        u.email as user_email,
                        COALESCE(m.title, "Untitled") as media_title
                 FROM comments c
                 JOIN users u ON c.user_id = u.id
                 JOIN media m ON c.media_id = m.id
                 ORDER BY c.created_at DESC
                 LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset)
            );
        }

        return Response::send(Response::paginated($comments, $total, $page, $perPage));
    }

    public static function createComment()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['media_id']) || !isset($input['text'])) {
            return Response::send(Response::error('media_id and text are required', 400));
        }

        $mediaId = intval($input['media_id']);
        $userId = isset($input['user_id']) ? intval($input['user_id']) : intval($auth['user']['userId']);
        $text = trim((string) $input['text']);

        if ($mediaId <= 0 || $userId <= 0) {
            return Response::send(Response::error('Invalid media_id or user_id', 400));
        }
        if ($text === '') {
            return Response::send(Response::error('Comment text required', 400));
        }
        if (strlen($text) > 2000) {
            return Response::send(Response::error('Comment too long', 400));
        }

        $db = Database::getInstance();
        $media = $db->fetch('SELECT id FROM media WHERE id = ?', [$mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        $user = $db->fetch('SELECT id FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return Response::send(Response::error('User not found', 404));
        }

        try {
            $commentId = $db->insert('comments', [
                'media_id' => $mediaId,
                'user_id' => $userId,
                'text' => $text
            ]);

            $comment = $db->fetch(
                'SELECT c.id, c.media_id, c.user_id, c.reply_to_id, c.text, c.created_at, c.updated_at,
                        u.email as user_email,
                        COALESCE(m.title, "Untitled") as media_title
                 FROM comments c
                 JOIN users u ON c.user_id = u.id
                 JOIN media m ON c.media_id = m.id
                 WHERE c.id = ?',
                [$commentId]
            );

            return Response::send(Response::success($comment, 'Comment created successfully', 201));
        } catch (\Exception $e) {
            error_log('Admin createComment error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to create comment', 500));
        }
    }

    public static function updateComment($commentId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $commentId = intval($commentId);
        if ($commentId <= 0) {
            return Response::send(Response::error('Invalid comment id', 400));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $text = trim((string) ($input['text'] ?? ''));

        if ($text === '') {
            return Response::send(Response::error('Comment text required', 400));
        }
        if (strlen($text) > 2000) {
            return Response::send(Response::error('Comment too long', 400));
        }

        $db = Database::getInstance();
        $existing = $db->fetch('SELECT id FROM comments WHERE id = ?', [$commentId]);
        if (!$existing) {
            return Response::send(Response::error('Comment not found', 404));
        }

        try {
            $db->update('comments', ['text' => $text], 'id = ?', [$commentId]);

            $comment = $db->fetch(
                'SELECT c.id, c.media_id, c.user_id, c.reply_to_id, c.text, c.created_at, c.updated_at,
                        u.email as user_email,
                        COALESCE(m.title, "Untitled") as media_title
                 FROM comments c
                 JOIN users u ON c.user_id = u.id
                 JOIN media m ON c.media_id = m.id
                 WHERE c.id = ?',
                [$commentId]
            );

            return Response::send(Response::success($comment, 'Comment updated successfully'));
        } catch (\Exception $e) {
            error_log('Admin updateComment error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to update comment', 500));
        }
    }

    public static function deleteComment($commentId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $commentId = intval($commentId);
        if ($commentId <= 0) {
            return Response::send(Response::error('Invalid comment id', 400));
        }

        $db = Database::getInstance();
        $existing = $db->fetch('SELECT id FROM comments WHERE id = ?', [$commentId]);
        if (!$existing) {
            return Response::send(Response::error('Comment not found', 404));
        }

        try {
            $db->delete('comments', 'id = ?', [$commentId]);
            return Response::send(Response::success(null, 'Comment deleted successfully'));
        } catch (\Exception $e) {
            error_log('Admin deleteComment error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to delete comment', 500));
        }
    }

    public static function getLikes()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['limit']) ? min(200, max(1, intval($_GET['limit']))) : ITEMS_PER_PAGE;
        $offset = ($page - 1) * $perPage;
        $query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

        $db = Database::getInstance();

        if ($query !== '') {
            $needle = '%' . $query . '%';
            $total = $db->fetch(
                'SELECT COUNT(*) as count
                 FROM likes l
                 JOIN users u ON l.user_id = u.id
                 JOIN media m ON l.media_id = m.id
                 WHERE u.email LIKE ? OR m.title LIKE ?',
                [$needle, $needle]
            )['count'];

            $likes = $db->fetchAll(
                'SELECT l.id, l.media_id, l.user_id, l.created_at,
                        u.email as user_email,
                        COALESCE(m.title, "Untitled") as media_title
                 FROM likes l
                 JOIN users u ON l.user_id = u.id
                 JOIN media m ON l.media_id = m.id
                 WHERE u.email LIKE ? OR m.title LIKE ?
                 ORDER BY l.created_at DESC
                 LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset),
                [$needle, $needle]
            );
        } else {
            $total = $db->fetch('SELECT COUNT(*) as count FROM likes')['count'];
            $likes = $db->fetchAll(
                'SELECT l.id, l.media_id, l.user_id, l.created_at,
                        u.email as user_email,
                        COALESCE(m.title, "Untitled") as media_title
                 FROM likes l
                 JOIN users u ON l.user_id = u.id
                 JOIN media m ON l.media_id = m.id
                 ORDER BY l.created_at DESC
                 LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset)
            );
        }

        return Response::send(Response::paginated($likes, $total, $page, $perPage));
    }

    public static function createLike()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['media_id']) || !isset($input['user_id'])) {
            return Response::send(Response::error('media_id and user_id are required', 400));
        }

        $mediaId = intval($input['media_id']);
        $userId = intval($input['user_id']);
        if ($mediaId <= 0 || $userId <= 0) {
            return Response::send(Response::error('Invalid media_id or user_id', 400));
        }

        $db = Database::getInstance();

        $media = $db->fetch('SELECT id FROM media WHERE id = ?', [$mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        $user = $db->fetch('SELECT id FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return Response::send(Response::error('User not found', 404));
        }

        $existing = $db->fetch('SELECT id FROM likes WHERE media_id = ? AND user_id = ?', [$mediaId, $userId]);
        if ($existing) {
            return Response::send(Response::error('Like already exists for this user and post', 409));
        }

        try {
            $likeId = $db->insert('likes', [
                'media_id' => $mediaId,
                'user_id' => $userId
            ]);

            $like = $db->fetch(
                'SELECT l.id, l.media_id, l.user_id, l.created_at,
                        u.email as user_email,
                        COALESCE(m.title, "Untitled") as media_title
                 FROM likes l
                 JOIN users u ON l.user_id = u.id
                 JOIN media m ON l.media_id = m.id
                 WHERE l.id = ?',
                [$likeId]
            );

            return Response::send(Response::success($like, 'Like created successfully', 201));
        } catch (\Exception $e) {
            error_log('Admin createLike error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to create like', 500));
        }
    }

    public static function deleteLike($likeId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $likeId = intval($likeId);
        if ($likeId <= 0) {
            return Response::send(Response::error('Invalid like id', 400));
        }

        $db = Database::getInstance();
        $existing = $db->fetch('SELECT id FROM likes WHERE id = ?', [$likeId]);
        if (!$existing) {
            return Response::send(Response::error('Like not found', 404));
        }

        try {
            $db->delete('likes', 'id = ?', [$likeId]);
            return Response::send(Response::success(null, 'Like deleted successfully'));
        } catch (\Exception $e) {
            error_log('Admin deleteLike error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to delete like', 500));
        }
    }

    public static function updateLike($likeId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $likeId = intval($likeId);
        if ($likeId <= 0) {
            return Response::send(Response::error('Invalid like id', 400));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['media_id']) || !isset($input['user_id'])) {
            return Response::send(Response::error('media_id and user_id are required', 400));
        }

        $mediaId = intval($input['media_id']);
        $userId = intval($input['user_id']);
        if ($mediaId <= 0 || $userId <= 0) {
            return Response::send(Response::error('Invalid media_id or user_id', 400));
        }

        $db = Database::getInstance();

        $existing = $db->fetch('SELECT id FROM likes WHERE id = ?', [$likeId]);
        if (!$existing) {
            return Response::send(Response::error('Like not found', 404));
        }

        $media = $db->fetch('SELECT id FROM media WHERE id = ?', [$mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        $user = $db->fetch('SELECT id FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return Response::send(Response::error('User not found', 404));
        }

        $duplicate = $db->fetch(
            'SELECT id FROM likes WHERE media_id = ? AND user_id = ? AND id <> ?',
            [$mediaId, $userId, $likeId]
        );
        if ($duplicate) {
            return Response::send(Response::error('Another like already exists for this user and post', 409));
        }

        try {
            $db->update('likes', [
                'media_id' => $mediaId,
                'user_id' => $userId
            ], 'id = ?', [$likeId]);

            $like = $db->fetch(
                'SELECT l.id, l.media_id, l.user_id, l.created_at,
                        u.email as user_email,
                        COALESCE(m.title, "Untitled") as media_title
                 FROM likes l
                 JOIN users u ON l.user_id = u.id
                 JOIN media m ON l.media_id = m.id
                 WHERE l.id = ?',
                [$likeId]
            );

            return Response::send(Response::success($like, 'Like updated successfully'));
        } catch (\Exception $e) {
            error_log('Admin updateLike error: ' . $e->getMessage());
            return Response::send(Response::error('Failed to update like', 500));
        }
    }

    public static function getRoles()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::checkRole('ADMIN');
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $db = Database::getInstance();
        $rows = $db->fetchAll('SELECT role, COUNT(*) as count FROM users GROUP BY role');

        $counts = [
            'ADMIN' => 0,
            'CREATOR' => 0,
            'CONSUMER' => 0
        ];

        foreach ($rows as $row) {
            $role = strtoupper($row['role']);
            if (isset($counts[$role])) {
                $counts[$role] = intval($row['count']);
            }
        }

        $roles = [
            [
                'name' => 'ADMIN',
                'count' => $counts['ADMIN'],
                'description' => 'Full system access',
                'permissions' => ['users:crud', 'posts:crud', 'comments:crud', 'likes:crud', 'roles:view']
            ],
            [
                'name' => 'CREATOR',
                'count' => $counts['CREATOR'],
                'description' => 'Can create and manage own content',
                'permissions' => ['posts:create', 'posts:read', 'posts:update:own', 'posts:delete:own', 'comments:read']
            ],
            [
                'name' => 'CONSUMER',
                'count' => $counts['CONSUMER'],
                'description' => 'Can browse, like, and comment',
                'permissions' => ['posts:read', 'comments:create', 'likes:create']
            ]
        ];

        return Response::send(Response::success($roles));
    }
}
