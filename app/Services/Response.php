<?php

namespace App\Services;

class Response
{
    public static function success($data = null, $message = 'Success', $statusCode = 200)
    {
        http_response_code($statusCode);
        return json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data ?? []
        ]);
    }

    public static function error($message = 'Error', $statusCode = 400, $error = null)
    {
        http_response_code($statusCode);
        return json_encode([
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $statusCode
            ],
            'data' => $error
        ]);
    }

    public static function paginated($items, $total, $page, $perPage)
    {
        $totalPages = ceil($total / $perPage);

        http_response_code(200);
        return json_encode([
            'success' => true,
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'currentPage' => $page,
                'perPage' => $perPage,
                'totalPages' => $totalPages
            ]
        ]);
    }

    public static function send($content)
    {
        echo $content;
        exit();
    }
}
