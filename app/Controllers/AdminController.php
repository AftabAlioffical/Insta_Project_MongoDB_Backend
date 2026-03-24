<?php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Services\CacheService;
use App\Services\MongoDatabase;
use App\Services\Response;

class AdminController
{
    public static function createUser()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = self::requireAdmin();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || !isset($input['email']) || !isset($input['password']) || !isset($input['role'])) {
            return Response::send(Response::error('Email, password, and role required', 400));
        }

        $email = trim((string) $input['email']);
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

        $db = MongoDatabase::getInstance();
        $existing = $db->findOne('users', ['email' => $email], ['projection' => ['id' => 1]]);
        if ($existing) {
            return Response::send(Response::error('Email already exists', 409));
        }

        try {
            $userId = $db->insertOne('users', [
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
                'role' => $role
            ]);

            $user = $db->findOne('users', ['id' => intval($userId)], [
                'projection' => ['id' => 1, 'email' => 1, 'role' => 1, 'created_at' => 1, 'updated_at' => 1]
            ]);

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

        $auth = self::requireAdmin();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['limit']) ? min(200, max(1, intval($_GET['limit']))) : ITEMS_PER_PAGE;
        $query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
        $roleFilter = isset($_GET['role']) ? strtoupper(trim((string) $_GET['role'])) : '';

        $db = MongoDatabase::getInstance();
        $rows = $db->findMany('users', [], [
            'projection' => ['id' => 1, 'email' => 1, 'role' => 1, 'display_name' => 1, 'avatar_url' => 1, 'created_at' => 1, 'updated_at' => 1],
            'sort' => ['id' => -1]
        ]);

        $filtered = [];
        foreach ($rows as $user) {
            if ($query !== '') {
                $haystack = strtolower(
                    (string) ($user['email'] ?? '') . ' ' .
                    (string) ($user['role'] ?? '') . ' ' .
                    (string) ($user['display_name'] ?? '')
                );
                if (strpos($haystack, strtolower($query)) === false) {
                    continue;
                }
            }

            if ($roleFilter !== '' && strtoupper((string) ($user['role'] ?? '')) !== $roleFilter) {
                continue;
            }

            $filtered[] = [
                'id' => intval($user['id'] ?? 0),
                'email' => $user['email'] ?? '',
                'role' => $user['role'] ?? 'CONSUMER',
                'displayName' => $user['display_name'] ?? null,
                'avatarUrl' => $user['avatar_url'] ?? null,
                'created_at' => $user['created_at'] ?? null,
                'updated_at' => $user['updated_at'] ?? null
            ];
        }

        $total = count($filtered);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($filtered, intval($offset), intval($perPage));

        return Response::send(Response::paginated($items, $total, $page, $perPage));
    }

    public static function updateUser($userId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = self::requireAdmin();
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

        $db = MongoDatabase::getInstance();
        $user = $db->findOne('users', ['id' => $userId], ['projection' => ['id' => 1, 'email' => 1, 'role' => 1]]);
        if (!$user) {
            return Response::send(Response::error('User not found', 404));
        }

        $updates = [];

        if (isset($input['email'])) {
            $email = trim((string) $input['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Response::send(Response::error('Invalid email format', 400));
            }

            $existing = $db->findOne('users', ['email' => $email], ['projection' => ['id' => 1]]);
            if ($existing && intval($existing['id']) !== $userId) {
                return Response::send(Response::error('Email already exists', 409));
            }
            $updates['email'] = $email;
        }

        if (isset($input['role'])) {
            $role = strtoupper((string) $input['role']);
            if (!in_array($role, ['ADMIN', 'CREATOR', 'CONSUMER'], true)) {
                return Response::send(Response::error('Invalid role', 400));
            }

            if (($user['role'] ?? '') === 'ADMIN' && $role !== 'ADMIN') {
                if (self::countUsersByRole('ADMIN') <= 1) {
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
            $fresh = $db->findOne('users', ['id' => $userId], [
                'projection' => ['id' => 1, 'email' => 1, 'role' => 1, 'created_at' => 1, 'updated_at' => 1]
            ]);
            return Response::send(Response::success($fresh, 'No changes applied'));
        }

        try {
            $db->updateOne('users', ['id' => $userId], $updates);
            $fresh = $db->findOne('users', ['id' => $userId], [
                'projection' => ['id' => 1, 'email' => 1, 'role' => 1, 'created_at' => 1, 'updated_at' => 1]
            ]);
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

        $auth = self::requireAdmin();
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

        $db = MongoDatabase::getInstance();
        $user = $db->findOne('users', ['id' => $userId], ['projection' => ['id' => 1, 'role' => 1]]);
        if (!$user) {
            return Response::send(Response::error('User not found', 404));
        }

        if (($user['role'] ?? '') === 'ADMIN' && self::countUsersByRole('ADMIN') <= 1) {
            return Response::send(Response::error('Cannot delete the last admin', 400));
        }

        try {
            $db->deleteOne('users', ['id' => $userId]);
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

        $auth = self::requireAdmin();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['limit']) ? min(200, max(1, intval($_GET['limit']))) : ITEMS_PER_PAGE;
        $query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

        $db = MongoDatabase::getInstance();
        $rows = $db->findMany('media', [], ['sort' => ['id' => -1]]);

        $posts = [];
        foreach ($rows as $post) {
            $creator = $db->findOne('users', ['id' => intval($post['creator_id'] ?? 0)], ['projection' => ['email' => 1]]);
            $creatorEmail = (string) ($creator['email'] ?? '');

            if ($query !== '') {
                $haystack = strtolower(
                    (string) ($post['title'] ?? '') . ' ' .
                    (string) ($post['caption'] ?? '') . ' ' .
                    $creatorEmail
                );
                if (strpos($haystack, strtolower($query)) === false) {
                    continue;
                }
            }

            $postId = intval($post['id'] ?? 0);
            $posts[] = [
                'id' => $postId,
                'creator_id' => intval($post['creator_id'] ?? 0),
                'creator_email' => $creatorEmail,
                'type' => $post['type'] ?? 'image',
                'url' => $post['url'] ?? '',
                'thumbnail_url' => $post['thumbnail_url'] ?? null,
                'title' => $post['title'] ?? '',
                'caption' => $post['caption'] ?? '',
                'location' => $post['location'] ?? '',
                'created_at' => $post['created_at'] ?? null,
                'updated_at' => $post['updated_at'] ?? null,
                'comments_count' => $db->count('comments', ['media_id' => $postId]),
                'likes_count' => $db->count('likes', ['media_id' => $postId])
            ];
        }

        $total = count($posts);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($posts, intval($offset), intval($perPage));

        return Response::send(Response::paginated($items, $total, $page, $perPage));
    }

    public static function updatePost($postId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = self::requireAdmin();
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

        $db = MongoDatabase::getInstance();
        $existing = $db->findOne('media', ['id' => $postId], ['projection' => ['id' => 1]]);
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
            $db->updateOne('media', ['id' => $postId], $updates);
            CacheService::getInstance()->delete('media_' . $postId);
            CacheService::getInstance()->delete('media_v2_' . $postId);

            $post = $db->findOne('media', ['id' => $postId]);
            if ($post) {
                $creator = $db->findOne('users', ['id' => intval($post['creator_id'] ?? 0)], ['projection' => ['email' => 1]]);
                $post['creator_email'] = $creator['email'] ?? '';
            }

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

        $auth = self::requireAdmin();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $postId = intval($postId);
        if ($postId <= 0) {
            return Response::send(Response::error('Invalid post id', 400));
        }

        $db = MongoDatabase::getInstance();
        $existing = $db->findOne('media', ['id' => $postId], ['projection' => ['id' => 1]]);
        if (!$existing) {
            return Response::send(Response::error('Post not found', 404));
        }

        try {
            $db->deleteOne('media', ['id' => $postId]);
            $db->deleteMany('person_tags', ['media_id' => $postId]);
            $db->deleteMany('comments', ['media_id' => $postId]);
            $db->deleteMany('likes', ['media_id' => $postId]);
            $db->deleteMany('ratings', ['media_id' => $postId]);
            CacheService::getInstance()->delete('media_' . $postId);
            CacheService::getInstance()->delete('media_v2_' . $postId);
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

        $auth = self::requireAdmin();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['limit']) ? min(200, max(1, intval($_GET['limit']))) : ITEMS_PER_PAGE;
        $query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

        $db = MongoDatabase::getInstance();
        $rows = $db->findMany('comments', [], ['sort' => ['id' => -1]]);

        $comments = [];
        foreach ($rows as $comment) {
            $user = $db->findOne('users', ['id' => intval($comment['user_id'] ?? 0)], ['projection' => ['email' => 1]]);
            $media = $db->findOne('media', ['id' => intval($comment['media_id'] ?? 0)], ['projection' => ['title' => 1]]);

            $userEmail = (string) ($user['email'] ?? '');
            $mediaTitle = (string) ($media['title'] ?? 'Untitled');
            if ($mediaTitle === '') {
                $mediaTitle = 'Untitled';
            }

            if ($query !== '') {
                $haystack = strtolower((string) ($comment['text'] ?? '') . ' ' . $userEmail . ' ' . $mediaTitle);
                if (strpos($haystack, strtolower($query)) === false) {
                    continue;
                }
            }

            $comments[] = [
                'id' => intval($comment['id'] ?? 0),
                'media_id' => intval($comment['media_id'] ?? 0),
                'user_id' => intval($comment['user_id'] ?? 0),
                'reply_to_id' => isset($comment['reply_to_id']) ? intval($comment['reply_to_id']) : null,
                'text' => $comment['text'] ?? '',
                'created_at' => $comment['created_at'] ?? null,
                'updated_at' => $comment['updated_at'] ?? null,
                'user_email' => $userEmail,
                'media_title' => $mediaTitle
            ];
        }

        $total = count($comments);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($comments, intval($offset), intval($perPage));

        return Response::send(Response::paginated($items, $total, $page, $perPage));
    }

    public static function createComment()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = self::requireAdmin();
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

        $db = MongoDatabase::getInstance();
        if (!$db->findOne('media', ['id' => $mediaId], ['projection' => ['id' => 1]])) {
            return Response::send(Response::error('Media not found', 404));
        }
        if (!$db->findOne('users', ['id' => $userId], ['projection' => ['id' => 1]])) {
            return Response::send(Response::error('User not found', 404));
        }

        try {
            $commentId = $db->insertOne('comments', [
                'media_id' => $mediaId,
                'user_id' => $userId,
                'text' => $text
            ]);

            $comment = $db->findOne('comments', ['id' => intval($commentId)]);
            $user = $db->findOne('users', ['id' => $userId], ['projection' => ['email' => 1]]);
            $media = $db->findOne('media', ['id' => $mediaId], ['projection' => ['title' => 1]]);

            $comment['user_email'] = $user['email'] ?? '';
            $comment['media_title'] = $media['title'] ?? 'Untitled';

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

        $auth = self::requireAdmin();
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

        $db = MongoDatabase::getInstance();
        $existing = $db->findOne('comments', ['id' => $commentId], ['projection' => ['id' => 1]]);
        if (!$existing) {
            return Response::send(Response::error('Comment not found', 404));
        }

        try {
            $db->updateOne('comments', ['id' => $commentId], ['text' => $text]);

            $comment = $db->findOne('comments', ['id' => $commentId]);
            $user = $db->findOne('users', ['id' => intval($comment['user_id'] ?? 0)], ['projection' => ['email' => 1]]);
            $media = $db->findOne('media', ['id' => intval($comment['media_id'] ?? 0)], ['projection' => ['title' => 1]]);

            $comment['user_email'] = $user['email'] ?? '';
            $comment['media_title'] = $media['title'] ?? 'Untitled';

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

        $auth = self::requireAdmin();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $commentId = intval($commentId);
        if ($commentId <= 0) {
            return Response::send(Response::error('Invalid comment id', 400));
        }

        $db = MongoDatabase::getInstance();
        $existing = $db->findOne('comments', ['id' => $commentId], ['projection' => ['id' => 1]]);
        if (!$existing) {
            return Response::send(Response::error('Comment not found', 404));
        }

        try {
            $db->deleteOne('comments', ['id' => $commentId]);
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

        $auth = self::requireAdmin();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['limit']) ? min(200, max(1, intval($_GET['limit']))) : ITEMS_PER_PAGE;
        $query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

        $db = MongoDatabase::getInstance();
        $rows = $db->findMany('likes', [], ['sort' => ['id' => -1]]);

        $likes = [];
        foreach ($rows as $like) {
            $user = $db->findOne('users', ['id' => intval($like['user_id'] ?? 0)], ['projection' => ['email' => 1]]);
            $media = $db->findOne('media', ['id' => intval($like['media_id'] ?? 0)], ['projection' => ['title' => 1]]);

            $userEmail = (string) ($user['email'] ?? '');
            $mediaTitle = (string) ($media['title'] ?? 'Untitled');
            if ($mediaTitle === '') {
                $mediaTitle = 'Untitled';
            }

            if ($query !== '') {
                $haystack = strtolower($userEmail . ' ' . $mediaTitle);
                if (strpos($haystack, strtolower($query)) === false) {
                    continue;
                }
            }

            $likes[] = [
                'id' => intval($like['id'] ?? 0),
                'media_id' => intval($like['media_id'] ?? 0),
                'user_id' => intval($like['user_id'] ?? 0),
                'created_at' => $like['created_at'] ?? null,
                'user_email' => $userEmail,
                'media_title' => $mediaTitle
            ];
        }

        $total = count($likes);
        $offset = ($page - 1) * $perPage;
        $items = array_slice($likes, intval($offset), intval($perPage));

        return Response::send(Response::paginated($items, $total, $page, $perPage));
    }

    public static function createLike()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = self::requireAdmin();
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

        $db = MongoDatabase::getInstance();

        if (!$db->findOne('media', ['id' => $mediaId], ['projection' => ['id' => 1]])) {
            return Response::send(Response::error('Media not found', 404));
        }

        if (!$db->findOne('users', ['id' => $userId], ['projection' => ['id' => 1]])) {
            return Response::send(Response::error('User not found', 404));
        }

        $existing = $db->findOne('likes', ['media_id' => $mediaId, 'user_id' => $userId], ['projection' => ['id' => 1]]);
        if ($existing) {
            return Response::send(Response::error('Like already exists for this user and post', 409));
        }

        try {
            $likeId = $db->insertOne('likes', [
                'media_id' => $mediaId,
                'user_id' => $userId
            ]);

            $like = $db->findOne('likes', ['id' => intval($likeId)]);
            $user = $db->findOne('users', ['id' => $userId], ['projection' => ['email' => 1]]);
            $media = $db->findOne('media', ['id' => $mediaId], ['projection' => ['title' => 1]]);

            $like['user_email'] = $user['email'] ?? '';
            $like['media_title'] = $media['title'] ?? 'Untitled';

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

        $auth = self::requireAdmin();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $likeId = intval($likeId);
        if ($likeId <= 0) {
            return Response::send(Response::error('Invalid like id', 400));
        }

        $db = MongoDatabase::getInstance();
        $existing = $db->findOne('likes', ['id' => $likeId], ['projection' => ['id' => 1]]);
        if (!$existing) {
            return Response::send(Response::error('Like not found', 404));
        }

        try {
            $db->deleteOne('likes', ['id' => $likeId]);
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

        $auth = self::requireAdmin();
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

        $db = MongoDatabase::getInstance();

        $existing = $db->findOne('likes', ['id' => $likeId], ['projection' => ['id' => 1]]);
        if (!$existing) {
            return Response::send(Response::error('Like not found', 404));
        }

        if (!$db->findOne('media', ['id' => $mediaId], ['projection' => ['id' => 1]])) {
            return Response::send(Response::error('Media not found', 404));
        }

        if (!$db->findOne('users', ['id' => $userId], ['projection' => ['id' => 1]])) {
            return Response::send(Response::error('User not found', 404));
        }

        $duplicate = $db->findOne('likes', ['media_id' => $mediaId, 'user_id' => $userId], ['projection' => ['id' => 1]]);
        if ($duplicate && intval($duplicate['id']) !== $likeId) {
            return Response::send(Response::error('Another like already exists for this user and post', 409));
        }

        try {
            $db->updateOne('likes', ['id' => $likeId], [
                'media_id' => $mediaId,
                'user_id' => $userId
            ]);

            $like = $db->findOne('likes', ['id' => $likeId]);
            $user = $db->findOne('users', ['id' => $userId], ['projection' => ['email' => 1]]);
            $media = $db->findOne('media', ['id' => $mediaId], ['projection' => ['title' => 1]]);

            $like['user_email'] = $user['email'] ?? '';
            $like['media_title'] = $media['title'] ?? 'Untitled';

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

        $auth = self::requireAdmin();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 403));
        }

        $counts = [
            'ADMIN' => self::countUsersByRole('ADMIN'),
            'CREATOR' => self::countUsersByRole('CREATOR'),
            'CONSUMER' => self::countUsersByRole('CONSUMER')
        ];

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

    private static function requireAdmin()
    {
        return AuthMiddleware::checkRole('ADMIN');
    }

    private static function countUsersByRole($role)
    {
        return MongoDatabase::getInstance()->count('users', ['role' => strtoupper((string) $role)]);
    }
}
