<?php

namespace App\Controllers;

use App\Services\Database;
use App\Services\Response;
use App\Middleware\AuthMiddleware;

class RatingController
{
    public static function getRatings($mediaId)
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

        $ratings = $db->fetchAll(
            'SELECT r.*, u.email as user_email FROM ratings r
             JOIN users u ON r.user_id = u.id
             WHERE r.media_id = ?
             ORDER BY r.created_at DESC',
            [$mediaId]
        );

        $stats = $db->fetch(
            'SELECT COUNT(*) as count, AVG(value) as average FROM ratings WHERE media_id = ?',
            [$mediaId]
        );

        return Response::send(Response::success([
            'ratings' => $ratings,
            'statistics' => [
                'totalRatings' => intval($stats['count']),
                'averageRating' => $stats['average'] ? round($stats['average'], 2) : 0
            ]
        ]));
    }

    public static function addRating($mediaId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::verify();

        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 401));
        }

        $mediaId = intval($mediaId);
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['value'])) {
            return Response::send(Response::error('Rating value required', 400));
        }

        $value = intval($input['value']);

        if ($value < 1 || $value > 5) {
            return Response::send(Response::error('Rating must be between 1 and 5', 400));
        }

        $db = Database::getInstance();

        // Check if media exists
        $media = $db->fetch('SELECT id FROM media WHERE id = ?', [$mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        try {
            // Check if user already rated this media
            $existing = $db->fetch(
                'SELECT id FROM ratings WHERE media_id = ? AND user_id = ?',
                [$mediaId, $auth['user']['userId']]
            );

            if ($existing) {
                // Update existing rating
                $db->update('ratings', ['value' => $value], 'id = ?', [$existing['id']]);
                $ratingId = $existing['id'];
                $message = 'Rating updated successfully';
                $statusCode = 200;
            } else {
                // Create new rating
                $ratingId = $db->insert('ratings', [
                    'media_id' => $mediaId,
                    'user_id' => $auth['user']['userId'],
                    'value' => $value
                ]);
                $message = 'Rating added successfully';
                $statusCode = 201;
            }

            $rating = $db->fetch(
                'SELECT r.*, u.email as user_email FROM ratings r
                 JOIN users u ON r.user_id = u.id
                 WHERE r.id = ?',
                [$ratingId]
            );

            return Response::send(Response::success($rating, $message, $statusCode));
        } catch (\Exception $e) {
            error_log("Rating creation error: " . $e->getMessage());
            return Response::send(Response::error('Failed to add rating', 500));
        }
    }

    public static function deleteRating($ratingId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::verify();

        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 401));
        }

        $ratingId = intval($ratingId);
        $db = Database::getInstance();

        $rating = $db->fetch('SELECT user_id FROM ratings WHERE id = ?', [$ratingId]);

        if (!$rating) {
            return Response::send(Response::error('Rating not found', 404));
        }

        // Check ownership
        if ($rating['user_id'] != $auth['user']['userId']) {
            return Response::send(Response::error('Unauthorized', 403));
        }

        try {
            $db->delete('ratings', 'id = ?', [$ratingId]);
            return Response::send(Response::success(null, 'Rating deleted successfully'));
        } catch (\Exception $e) {
            error_log("Rating deletion error: " . $e->getMessage());
            return Response::send(Response::error('Failed to delete rating', 500));
        }
    }
}
