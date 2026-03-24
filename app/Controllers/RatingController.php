<?php

namespace App\Controllers;

use App\Services\MongoDatabase;
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

        $db = MongoDatabase::getInstance();

        // Check if media exists
        $media = $db->findOne('media', ['id' => $mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        $ratings = $db->findMany('ratings', ['media_id' => $mediaId], ['sort' => ['id' => -1]]);
        foreach ($ratings as &$rating) {
            $user = $db->findOne('users', ['id' => intval($rating['user_id'])], ['projection' => ['email' => 1]]);
            $rating['user_email'] = $user['email'] ?? '';
        }

        $count = count($ratings);
        $sum = 0;
        foreach ($ratings as $r) {
            $sum += intval($r['value'] ?? 0);
        }
        $average = $count > 0 ? round($sum / $count, 2) : 0;

        return Response::send(Response::success([
            'ratings' => $ratings,
            'statistics' => [
                'totalRatings' => $count,
                'averageRating' => $average
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

        $db = MongoDatabase::getInstance();

        // Check if media exists
        $media = $db->findOne('media', ['id' => $mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        try {
            // Check if user already rated this media
            $existing = $db->findOne('ratings', [
                'media_id' => $mediaId,
                'user_id' => intval($auth['user']['userId'])
            ]);

            if ($existing) {
                // Update existing rating
                $ratingId = intval($existing['id']);
                $db->updateOne('ratings', ['id' => $ratingId], ['value' => $value]);
                $message = 'Rating updated successfully';
                $statusCode = 200;
            } else {
                // Create new rating
                $ratingId = $db->insertOne('ratings', [
                    'media_id' => $mediaId,
                    'user_id' => intval($auth['user']['userId']),
                    'value' => $value
                ]);
                $message = 'Rating added successfully';
                $statusCode = 201;
            }

            $rating = $db->findOne('ratings', ['id' => intval($ratingId)]);
            $user = $db->findOne('users', ['id' => intval($rating['user_id'] ?? 0)], ['projection' => ['email' => 1]]);
            $rating['user_email'] = $user['email'] ?? '';

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
        $db = MongoDatabase::getInstance();

        $rating = $db->findOne('ratings', ['id' => $ratingId], ['projection' => ['user_id' => 1]]);

        if (!$rating) {
            return Response::send(Response::error('Rating not found', 404));
        }

        // Check ownership
        if ($rating['user_id'] != $auth['user']['userId']) {
            return Response::send(Response::error('Unauthorized', 403));
        }

        try {
            $db->deleteOne('ratings', ['id' => $ratingId]);
            return Response::send(Response::success(null, 'Rating deleted successfully'));
        } catch (\Exception $e) {
            error_log("Rating deletion error: " . $e->getMessage());
            return Response::send(Response::error('Failed to delete rating', 500));
        }
    }
}
