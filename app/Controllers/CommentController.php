<?php

namespace App\Controllers;

use App\Services\Database;
use App\Services\Response;
use App\Middleware\AuthMiddleware;

class CommentController
{
    public static function getComments($mediaId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $mediaId = intval($mediaId);
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = ITEMS_PER_PAGE;
        $offset = ($page - 1) * $perPage;

        $db = Database::getInstance();

        // Check if media exists
        $media = $db->fetch('SELECT id FROM media WHERE id = ?', [$mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        $total = $db->fetch(
            'SELECT COUNT(*) as count FROM comments WHERE media_id = ?',
            [$mediaId]
        )['count'];

        $comments = $db->fetchAll(
            'SELECT c.*, u.email as user_email, u.email as user_name FROM comments c
             JOIN users u ON c.user_id = u.id
             WHERE c.media_id = ?
             ORDER BY c.created_at DESC LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset),
            [$mediaId]
        );

        // Sanitize comment text
        foreach ($comments as &$comment) {
            $comment['text'] = htmlspecialchars($comment['text'], ENT_QUOTES, 'UTF-8');
        }

        return Response::send(Response::paginated($comments, $total, $page, $perPage));
    }

    public static function addComment($mediaId)
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

        if (!isset($input['text']) || empty(trim($input['text']))) {
            return Response::send(Response::error('Comment text required', 400));
        }

        $text = trim($input['text']);

        if (strlen($text) > 2000) {
            return Response::send(Response::error('Comment too long', 400));
        }

        $db = Database::getInstance();

        // Check if media exists
        $media = $db->fetch('SELECT id FROM media WHERE id = ?', [$mediaId]);
        if (!$media) {
            return Response::send(Response::error('Media not found', 404));
        }

        try {
            $commentId = $db->insert('comments', [
                'media_id' => $mediaId,
                'user_id' => $auth['user']['userId'],
                'text' => $text
            ]);

            $comment = $db->fetch(
                'SELECT c.*, u.email as user_email FROM comments c
                 JOIN users u ON c.user_id = u.id
                 WHERE c.id = ?',
                [$commentId]
            );

            $comment['text'] = htmlspecialchars($comment['text'], ENT_QUOTES, 'UTF-8');

            return Response::send(Response::success($comment, 'Comment added successfully', 201));
        } catch (\Exception $e) {
            error_log("Comment creation error: " . $e->getMessage());
            return Response::send(Response::error('Failed to add comment', 500));
        }
    }

    public static function deleteComment($commentId)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $auth = AuthMiddleware::verify();

        if (!$auth['authenticated']) {
            return Response::send(Response::error($auth['error'], 401));
        }

        $commentId = intval($commentId);
        $db = Database::getInstance();

        $comment = $db->fetch('SELECT user_id FROM comments WHERE id = ?', [$commentId]);

        if (!$comment) {
            return Response::send(Response::error('Comment not found', 404));
        }

        // Check ownership (users can delete their own, admins can delete any)
        if ($auth['user']['role'] !== 'ADMIN' && $comment['user_id'] != $auth['user']['userId']) {
            return Response::send(Response::error('Unauthorized', 403));
        }

        try {
            $db->delete('comments', 'id = ?', [$commentId]);
            return Response::send(Response::success(null, 'Comment deleted successfully'));
        } catch (\Exception $e) {
            error_log("Comment deletion error: " . $e->getMessage());
            return Response::send(Response::error('Failed to delete comment', 500));
        }
    }
}
