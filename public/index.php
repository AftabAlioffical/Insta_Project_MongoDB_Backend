<?php

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Autoloader
require_once __DIR__ . '/../config/config.php';

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    if (strpos($class, $prefix) === 0) {
        $file = __DIR__ . '/../app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

// Middleware
use App\Middleware\CORSMiddleware;

CORSMiddleware::handle();

// Routing logic
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestUri = str_replace('/api', '', $requestUri);
$requestUri = rtrim($requestUri, '/');
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Route handler
$routes = [
    // Health checks
    'GET,/health' => ['App\Controllers\HealthController', 'health'],
    'GET,/ready' => ['App\Controllers\HealthController', 'ready'],

    // Authentication
    'POST,/auth/login' => ['App\Controllers\AuthController', 'login'],
    'POST,/auth/register' => ['App\Controllers\AuthController', 'register'],
    'GET,/auth/me' => ['App\Controllers\AuthController', 'me'],
    'GET,/auth/google-oauth' => ['App\Controllers\AuthController', 'googleOAuth'],
    'GET,/auth/google-callback' => ['App\Controllers\AuthController', 'googleOAuth'],

    // Current user profile
    'PUT,/users/me' => ['App\Controllers\UserController', 'updateProfile'],
    'POST,/users/me/avatar' => ['App\Controllers\UserController', 'uploadAvatar'],

    // Admin
    'POST,/admin/users' => ['App\Controllers\AdminController', 'createUser'],
    'GET,/admin/users' => ['App\Controllers\AdminController', 'getUsers'],
    'GET,/admin/posts' => ['App\Controllers\AdminController', 'getPosts'],
    'GET,/admin/comments' => ['App\Controllers\AdminController', 'getComments'],
    'POST,/admin/comments' => ['App\Controllers\AdminController', 'createComment'],
    'GET,/admin/likes' => ['App\Controllers\AdminController', 'getLikes'],
    'POST,/admin/likes' => ['App\Controllers\AdminController', 'createLike'],
    'GET,/admin/roles' => ['App\Controllers\AdminController', 'getRoles'],

    // Media
    'POST,/media' => ['App\Controllers\MediaController', 'upload'],
    'GET,/media' => ['App\Controllers\MediaController', 'getMedia'],

    // Search
    'GET,/search' => ['App\Controllers\SearchController', 'search'],
];

// Check for matching route
$matched = false;

// Static routes
$routeKey = "{$requestMethod},{$requestUri}";
if (isset($routes[$routeKey])) {
    list($controller, $method) = $routes[$routeKey];
    $controller::$method();
    $matched = true;
}

// Dynamic routes with ID
if (!$matched) {
    // Pattern: /resource/{id} or /resource/{id}/subresource
    $segments = array_values(array_filter(explode('/', $requestUri)));
    
    if (count($segments) >= 2) {
        // Media routes
        if ($segments[0] === 'media') {
            $mediaId = $segments[1] ?? null;

            if (is_numeric($mediaId)) {
                if (count($segments) === 2) {
                    // /media/{id}
                    if ($requestMethod === 'GET') {
                        \App\Controllers\MediaController::getMedia($mediaId);
                        $matched = true;
                    } elseif ($requestMethod === 'DELETE') {
                        \App\Controllers\MediaController::deleteMedia($mediaId);
                        $matched = true;
                    }
                } elseif (count($segments) >= 3) {
                    $subResource = $segments[2];

                    // /media/{id}/comments
                    if ($subResource === 'comments') {
                        if ($requestMethod === 'GET') {
                            \App\Controllers\CommentController::getComments($mediaId);
                            $matched = true;
                        } elseif ($requestMethod === 'POST') {
                            \App\Controllers\CommentController::addComment($mediaId);
                            $matched = true;
                        }
                    }

                    // /media/{id}/ratings
                    if ($subResource === 'ratings') {
                        if ($requestMethod === 'GET') {
                            \App\Controllers\RatingController::getRatings($mediaId);
                            $matched = true;
                        } elseif ($requestMethod === 'POST') {
                            \App\Controllers\RatingController::addRating($mediaId);
                            $matched = true;
                        }
                    }

                    // /media/{id}/likes
                    if ($subResource === 'likes') {
                        if ($requestMethod === 'GET') {
                            \App\Controllers\LikeController::getLikes($mediaId);
                            $matched = true;
                        } elseif ($requestMethod === 'POST') {
                            \App\Controllers\LikeController::toggleLike($mediaId);
                            $matched = true;
                        }
                    }
                }
            }
        }

        // Users routes
        if ($segments[0] === 'users' && is_numeric($segments[1])) {
            $userId = $segments[1];
            
            if ($requestMethod === 'GET' && count($segments) === 2) {
                \App\Controllers\UserController::getProfile($userId);
                $matched = true;
            }
        }

        // User search
        if ($segments[0] === 'users' && isset($segments[1]) && $segments[1] === 'search') {
            if ($requestMethod === 'GET') {
                \App\Controllers\UserController::searchUsers();
                $matched = true;
            }
        }

        // Comments routes
        if ($segments[0] === 'comments' && is_numeric($segments[1])) {
            $commentId = $segments[1];
            
            if ($requestMethod === 'DELETE') {
                \App\Controllers\CommentController::deleteComment($commentId);
                $matched = true;
            }
        }

        // Ratings routes
        if ($segments[0] === 'ratings' && is_numeric($segments[1])) {
            $ratingId = $segments[1];
            
            if ($requestMethod === 'DELETE') {
                \App\Controllers\RatingController::deleteRating($ratingId);
                $matched = true;
            }
        }

        // Admin routes
        if ($segments[0] === 'admin' && count($segments) >= 3) {
            // /admin/users/{id}
            if ($segments[1] === 'users' && is_numeric($segments[2])) {
                $userId = $segments[2];

                if ($requestMethod === 'PUT') {
                    \App\Controllers\AdminController::updateUser($userId);
                    $matched = true;
                } elseif ($requestMethod === 'DELETE') {
                    \App\Controllers\AdminController::deleteUser($userId);
                    $matched = true;
                }
            }

            // /admin/posts/{id}
            if ($segments[1] === 'posts' && is_numeric($segments[2])) {
                $postId = $segments[2];

                if ($requestMethod === 'PUT') {
                    \App\Controllers\AdminController::updatePost($postId);
                    $matched = true;
                } elseif ($requestMethod === 'DELETE') {
                    \App\Controllers\AdminController::deletePost($postId);
                    $matched = true;
                }
            }

            // /admin/comments/{id}
            if ($segments[1] === 'comments' && is_numeric($segments[2])) {
                $commentId = $segments[2];

                if ($requestMethod === 'PUT') {
                    \App\Controllers\AdminController::updateComment($commentId);
                    $matched = true;
                } elseif ($requestMethod === 'DELETE') {
                    \App\Controllers\AdminController::deleteComment($commentId);
                    $matched = true;
                }
            }

            // /admin/likes/{id}
            if ($segments[1] === 'likes' && is_numeric($segments[2])) {
                $likeId = $segments[2];

                if ($requestMethod === 'PUT') {
                    \App\Controllers\AdminController::updateLike($likeId);
                    $matched = true;
                } elseif ($requestMethod === 'DELETE') {
                    \App\Controllers\AdminController::deleteLike($likeId);
                    $matched = true;
                }
            }
        }
    }
}

// 404 response
if (!$matched) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => 'Route not found',
            'code' => 404
        ]
    ]);
}
