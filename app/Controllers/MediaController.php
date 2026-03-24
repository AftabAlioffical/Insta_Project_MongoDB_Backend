<?php

namespace App\Controllers;

use App\Services\MongoDatabase;
use App\Services\Response;
use App\Services\CacheService;
use App\Middleware\AuthMiddleware;

class MediaController
{
    public static function upload()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        // Only CREATOR and ADMIN roles can upload content
        $auth = AuthMiddleware::checkRole(['CREATOR', 'ADMIN']);
        
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'] ?? 'Only creators can upload content', 403));
        }

        // User is CREATOR or ADMIN - allowed to upload.
        $db = MongoDatabase::getInstance();

        if (!isset($_FILES['file'])) {
            return Response::send(Response::error('No file uploaded', 400));
        }

        // rate limit uploads
        if (RATE_LIMIT_ENABLED) {
            $rate = new \App\Services\RateLimitService();
            $key = 'rl_upload_' . $auth['user']['userId'];
            if (!$rate->check($key, RATE_LIMIT_UPLOAD, 3600)) {
                return Response::send(Response::error('Upload limit reached, please try later', 429));
            }
        }

        $file = $_FILES['file'];
        $title = $_POST['title'] ?? 'Untitled';
        $caption = $_POST['caption'] ?? '';
        $location = $_POST['location'] ?? '';
        $personTags = isset($_POST['personTags']) ? explode(',', $_POST['personTags']) : [];

        // Validate file
        $validation = self::validateFile($file);
        if (!$validation['valid']) {
            return Response::send(Response::error($validation['error'], 400));
        }

        try {
            // Generate filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('media_') . '.' . strtolower($ext);
            $filePath = UPLOAD_DIR . $filename;

            // Create uploads directory if it doesn't exist
            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0755, true);
            }

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                return Response::send(Response::error('Failed to upload file', 500));
            }

            // Determine media type
            $type = in_array(strtolower($ext), ['mp4', 'webm']) ? 'video' : 'image';

            // Insert media record
            $mediaId = $db->insertOne('media', [
                'creator_id' => $auth['user']['userId'],
                'type' => $type,
                'url' => '/assets/uploads/' . $filename,
                'thumbnail_url' => '/assets/uploads/thumb_' . $filename,
                'title' => $title,
                'caption' => $caption,
                'location' => $location
            ]);

            // Insert person tags
            foreach (array_filter(array_map('trim', $personTags)) as $tag) {
                $db->insertOne('person_tags', [
                    'media_id' => $mediaId,
                    'name' => $tag
                ]);
            }

            self::clearMediaCaches($mediaId);

            $media = self::fetchMediaRecord($db, $mediaId);
            self::enrichMediaItem($db, $media);

            return Response::send(Response::success($media, 'Media uploaded successfully', 201));
        } catch (\Exception $e) {
            error_log("Media upload error: " . $e->getMessage());
            return Response::send(Response::error('Failed to upload media', 500));
        }
    }

    public static function getMedia($mediaId = null)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        if ($mediaId) {
            return self::getMediaById($mediaId);
        }

        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = isset($_GET['limit']) ? min(100, max(1, intval($_GET['limit']))) : ITEMS_PER_PAGE;
        $offset = ($page - 1) * $perPage;
        $cached = null;

        // optional filter by creator
        $creatorId = null;
        if (isset($_GET['creator_id'])) {
            $raw = $_GET['creator_id'];
            if ($raw === 'me') {
                $auth = \App\Middleware\AuthMiddleware::verify();
                if (!$auth['authenticated']) {
                    return Response::send(Response::error($auth['error'], 401));
                }
                $creatorId = $auth['user']['userId'];
            } elseif (is_numeric($raw)) {
                $creatorId = intval($raw);
            }
        }

        // Check cache (only for unfiltered feed)
        $cacheKey = 'feed_cache_v2_page_' . $page . '_limit_' . $perPage;
        if (!$creatorId) {
            $cache = CacheService::getInstance();
            $cached = $cache->get($cacheKey);
            if ($cached !== null) {
                return Response::send(Response::paginated($cached['data'], $cached['total'], $page, $perPage));
            }
        }

        $db = MongoDatabase::getInstance();

        if ($creatorId) {
            $filter = ['creator_id' => $creatorId];
            $total = $db->count('media', $filter);
            $mediaList = $db->findMany('media', $filter, [
                'sort' => ['id' => -1],
                'limit' => intval($perPage),
                'skip' => intval($offset)
            ]);
        } else {
            $total = $db->count('media', []);
            $mediaList = $db->findMany('media', [], [
                'sort' => ['id' => -1],
                'limit' => intval($perPage),
                'skip' => intval($offset)
            ]);
        }

        foreach ($mediaList as &$item) {
            self::enrichMediaItem($db, $item);
        }

        // Cache results for unfiltered feed only
        if (!$creatorId) {
            $cache->set($cacheKey, [
                'data' => $mediaList,
                'total' => $total
            ], CACHE_TTL_FEED);
        }

        return Response::send(Response::paginated($mediaList, $total, $page, $perPage));
    }

    private static function getMediaById($mediaId)
    {
        $mediaId = intval($mediaId);

        // Check cache
        $cacheKey = 'media_v2_' . $mediaId;
        $cache = CacheService::getInstance();
        $cached = $cache->get($cacheKey);

        if ($cached !== null) {
            return Response::send(Response::success($cached));
        }

        $db = MongoDatabase::getInstance();

        $media = self::fetchMediaRecord($db, $mediaId);

        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        self::enrichMediaItem($db, $media);

        // Cache result
        $cache->set($cacheKey, $media, CACHE_TTL_MEDIA);

        return Response::send(Response::success($media));
    }

    public static function deleteMedia($mediaId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::verify();

        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 401));
        }

        $mediaId = intval($mediaId);
        $db = MongoDatabase::getInstance();

        $media = $db->findOne('media', ['id' => $mediaId], ['projection' => ['creator_id' => 1]]);

        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        $currentRole = strtoupper((string) ($auth['user']['role'] ?? ''));
        if ($currentRole === '') {
            $currentUser = $db->findOne('users', ['id' => intval($auth['user']['userId'])], ['projection' => ['role' => 1]]);
            $currentRole = strtoupper((string) ($currentUser['role'] ?? ''));
        }

        // Check ownership (creators can delete their own, admins can delete any)
        if ($currentRole !== 'ADMIN' && $media['creator_id'] != $auth['user']['userId']) {
            return Response::send(Response::error('Unauthorized', 403));
        }

        try {
            $db->deleteOne('media', ['id' => $mediaId]);
            $db->deleteMany('person_tags', ['media_id' => $mediaId]);
            $db->deleteMany('comments', ['media_id' => $mediaId]);
            $db->deleteMany('likes', ['media_id' => $mediaId]);
            $db->deleteMany('ratings', ['media_id' => $mediaId]);
            self::clearMediaCaches($mediaId);

            return Response::send(Response::success(null, 'Media deleted successfully'));
        } catch (\Exception $e) {
            error_log("Media deletion error: " . $e->getMessage());
            return Response::send(Response::error('Failed to delete media', 500));
        }
    }

    private static function validateFile($file)
    {
        if (!is_array($file) || !isset($file['error'])) {
            return ['valid' => false, 'error' => 'Invalid upload payload'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => self::uploadErrorMessage((int) $file['error'])];
        }

        if ($file['size'] > MAX_UPLOAD_SIZE) {
            return ['valid' => false, 'error' => 'File size exceeds maximum allowed (' . self::formatBytes(MAX_UPLOAD_SIZE) . ')'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ALLOWED_EXTENSIONS)) {
            return ['valid' => false, 'error' => 'File type not allowed'];
        }

        return ['valid' => true];
    }

    private static function uploadErrorMessage($errorCode)
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'File is too large. Maximum allowed size is ' . self::formatBytes(MAX_UPLOAD_SIZE) . '.';
            case UPLOAD_ERR_PARTIAL:
                return 'File upload was interrupted. Please try again.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was selected for upload.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Server upload temp directory is missing.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Server could not write the uploaded file.';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload blocked by a server extension.';
            default:
                return 'File upload error.';
        }
    }

    private static function formatBytes($bytes)
    {
        if ($bytes >= 1024 * 1024) {
            return intval($bytes / (1024 * 1024)) . 'MB';
        }

        if ($bytes >= 1024) {
            return intval($bytes / 1024) . 'KB';
        }

        return intval($bytes) . 'B';
    }

    private static function fetchMediaRecord($db, $mediaId)
    {
        $item = $db->findOne('media', ['id' => intval($mediaId)]);
        if (!$item) {
            return null;
        }

        self::attachCreator($db, $item);
        return $item;
    }

    private static function enrichMediaItem($db, &$item)
    {
        if (!$item || !isset($item['id'])) {
            return;
        }

        self::attachCreator($db, $item);

        $ratingDocs = $db->findMany('ratings', ['media_id' => intval($item['id'])]);
        $ratingCount = count($ratingDocs);
        $ratingSum = 0;
        foreach ($ratingDocs as $ratingDoc) {
            $ratingSum += intval($ratingDoc['value'] ?? 0);
        }
        $item['ratings'] = [
            'count' => $ratingCount,
            'average' => $ratingCount > 0 ? round($ratingSum / $ratingCount, 2) : 0
        ];

        $item['commentsCount'] = $db->count('comments', ['media_id' => intval($item['id'])]);
        $item['likesCount'] = $db->count('likes', ['media_id' => intval($item['id'])]);
        $item['tags'] = $db->findMany('person_tags', ['media_id' => intval($item['id'])], ['projection' => ['name' => 1]]);
    }

    private static function attachCreator($db, &$item)
    {
        $creator = $db->findOne('users', ['id' => intval($item['creator_id'] ?? 0)], [
            'projection' => ['email' => 1, 'display_name' => 1, 'avatar_url' => 1]
        ]);

        $email = (string) ($creator['email'] ?? '');
        $displayName = trim((string) ($creator['display_name'] ?? ''));
        $item['creator_email'] = $email;
        $item['creatorEmail'] = $email;
        $item['creatorName'] = $displayName !== '' ? $displayName : explode('@', $email)[0];
        $item['creator_avatar_url'] = $creator['avatar_url'] ?? null;
        $item['creatorAvatarUrl'] = $creator['avatar_url'] ?? null;
    }

    private static function clearMediaCaches($mediaId = null)
    {
        $cache = CacheService::getInstance();
        $cache->delete('feed_cache');
        $cache->delete('feed_cache_page_1');
        $cache->delete('feed_cache_v2_page_1_limit_' . ITEMS_PER_PAGE);

        if ($mediaId !== null) {
            $cache->delete('media_' . $mediaId);
            $cache->delete('media_v2_' . $mediaId);
        }
    }
}
