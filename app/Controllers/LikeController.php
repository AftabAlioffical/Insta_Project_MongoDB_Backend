<?php

namespace App\Controllers;

use App\Services\Database;
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
        $db = Database::getInstance();

        // Check if media exists
        $media = $db->fetch('SELECT id FROM media WHERE id = ?', [$mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        // Get like count
        $result = $db->fetch(
            'SELECT COUNT(*) as count FROM likes WHERE media_id = ?',
            [$mediaId]
        );

        // Check if current user liked this
        $auth = AuthMiddleware::verify();
        $userLiked = false;
        if ($auth['authenticated']) {
            $like = $db->fetch(
                'SELECT id FROM likes WHERE media_id = ? AND user_id = ?',
                [$mediaId, $auth['user']['userId']]
            );
            $userLiked = !empty($like);
        }

        return Response::send(Response::success([
            'count' => $result['count'],
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
        $db = Database::getInstance();

        // Check if media exists
        $media = $db->fetch('SELECT id FROM media WHERE id = ?', [$mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        try {
            // Check if already liked
            $existing = $db->fetch(
                'SELECT id FROM likes WHERE media_id = ? AND user_id = ?',
                [$mediaId, $auth['user']['userId']]
            );

            if ($existing) {
                // Unlike
                $db->execute('DELETE FROM likes WHERE media_id = ? AND user_id = ?', 
                    [$mediaId, $auth['user']['userId']]);
                $liked = false;
            } else {
                // Like
                $db->insert('likes', [
                    'media_id' => $mediaId,
                    'user_id' => $auth['user']['userId']
                ]);
                $liked = true;
            }

            // Get updated like count
            $result = $db->fetch(
                'SELECT COUNT(*) as count FROM likes WHERE media_id = ?',
                [$mediaId]
            );

            return Response::send(Response::success([
                'liked' => $liked,
                'count' => $result['count']
            ], $liked ? 'Liked' : 'Unliked'));
        } catch (\Exception $e) {
            return Response::send(Response::error($e->getMessage(), 500));
        }
    }
}
