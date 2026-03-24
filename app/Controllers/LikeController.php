<?php

namespace App\Controllers;

use App\Services\MongoDatabase;
use App\Services\Response;
use App\Middleware\AuthMiddleware;

class LikeController
{
    public static function getLikes($mediaId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $mediaId = intval($mediaId);
        $db = MongoDatabase::getInstance();

        // Check if media exists
        $media = $db->findOne('media', ['id' => $mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        $count = $db->count('likes', ['media_id' => $mediaId]);

        // Check if current user liked this
        $auth = AuthMiddleware::verify();
        $userLiked = false;
        if ($auth['authenticated']) {
            $like = $db->findOne('likes', [
                'media_id' => $mediaId,
                'user_id' => intval($auth['user']['userId'])
            ]);
            $userLiked = !empty($like);
        }

        return Response::send(Response::success([
            'count' => $count,
            'userLiked' => $userLiked
        ]));
    }

    public static function toggleLike($mediaId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::verify();
        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 401));
        }

        $mediaId = intval($mediaId);
        $db = MongoDatabase::getInstance();

        // Check if media exists
        $media = $db->findOne('media', ['id' => $mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        try {
            // Check if already liked
            $existing = $db->findOne('likes', [
                'media_id' => $mediaId,
                'user_id' => intval($auth['user']['userId'])
            ]);

            if ($existing) {
                // Unlike
                $db->deleteOne('likes', ['id' => intval($existing['id'])]);
                $liked = false;
            } else {
                // Like
                $db->insertOne('likes', [
                    'media_id' => $mediaId,
                    'user_id' => intval($auth['user']['userId'])
                ]);
                $liked = true;
            }

            $count = $db->count('likes', ['media_id' => $mediaId]);

            return Response::send(Response::success([
                'liked' => $liked,
                'count' => $count
            ], $liked ? 'Liked' : 'Unliked'));
        } catch (\Exception $e) {
            return Response::send(Response::error($e->getMessage(), 500));
        }
    }
}
