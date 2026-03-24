<?php

// MongoDB configuration
define('MONGO_URI', getenv('MONGO_URI') ?: 'mongodb://127.0.0.1:27017');
define('MONGO_DB_NAME', getenv('MONGO_DB_NAME') ?: 'insta_app');

// JWT Configuration
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'change_me_in_production_secret_key_1234567890');
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRY', 86400); // 24 hours

// Application settings
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', APP_ENV === 'development');
define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost:8080');
define('API_BASE_URL', BASE_URL . '/api');

// Upload settings
define('MAX_UPLOAD_SIZE', 100 * 1024 * 1024); // 100MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'webm']);
define('UPLOAD_DIR', __DIR__ . '/../public/assets/uploads/');

// Redis configuration
define('REDIS_HOST', getenv('REDIS_HOST') ?: 'localhost');
define('REDIS_PORT', getenv('REDIS_PORT') ?: 6379);
define('REDIS_DB', 0);

// S3/MinIO configuration
define('S3_ENDPOINT', getenv('S3_ENDPOINT') ?: 'http://minio:9000');
define('S3_KEY', getenv('S3_KEY') ?: 'minioadmin');
define('S3_SECRET', getenv('S3_SECRET') ?: 'minioadmin');
define('S3_BUCKET', getenv('S3_BUCKET') ?: 'insta-media');
define('S3_REGION', getenv('S3_REGION') ?: 'us-east-1');
define('USE_S3', getenv('USE_S3') ?: false);

// CORS configuration
define('ALLOWED_ORIGINS', [
    'http://localhost',
    'http://localhost:3000',
    'http://localhost:8080',
    'http://127.0.0.1:8080'
]);

// Rate limiting
define('RATE_LIMIT_ENABLED', true);
define('RATE_LIMIT_LOGIN', 0); // 0 disables login rate limiting
define('RATE_LIMIT_UPLOAD', 20); // 20 uploads per hour

// Google OAuth configuration
define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');
define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: '');
define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI') ?: BASE_URL . '/api/auth/google-callback');
define('RATE_LIMIT_API', 100); // 100 requests per hour

// Cache TTL
define('CACHE_TTL_FEED', 300); // 5 minutes
define('CACHE_TTL_SEARCH', 600); // 10 minutes
define('CACHE_TTL_MEDIA', 3600); // 1 hour

// Pagination
define('ITEMS_PER_PAGE', 20);
define('DEFAULT_PAGE', 1);

return [
    'mongo' => [
        'uri' => MONGO_URI,
        'db' => MONGO_DB_NAME
    ],
    'jwt' => [
        'secret' => JWT_SECRET,
        'algorithm' => JWT_ALGORITHM,
        'expiry' => JWT_EXPIRY
    ],
    's3' => [
        'endpoint' => S3_ENDPOINT,
        'key' => S3_KEY,
        'secret' => S3_SECRET,
        'bucket' => S3_BUCKET,
        'region' => S3_REGION,
        'use_s3' => USE_S3
    ],
    'redis' => [
        'host' => REDIS_HOST,
        'port' => REDIS_PORT,
        'db' => REDIS_DB
    ]
];
