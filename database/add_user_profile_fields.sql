USE insta_app;

SET @display_name_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'display_name'
);
SET @display_name_sql = IF(
    @display_name_exists = 0,
    'ALTER TABLE users ADD COLUMN display_name VARCHAR(120) NULL AFTER email',
    'SELECT 1'
);
PREPARE display_name_stmt FROM @display_name_sql;
EXECUTE display_name_stmt;
DEALLOCATE PREPARE display_name_stmt;

SET @bio_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'bio'
);
SET @bio_sql = IF(
    @bio_exists = 0,
    'ALTER TABLE users ADD COLUMN bio TEXT NULL AFTER role',
    'SELECT 1'
);
PREPARE bio_stmt FROM @bio_sql;
EXECUTE bio_stmt;
DEALLOCATE PREPARE bio_stmt;

SET @avatar_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar_url'
);
SET @avatar_sql = IF(
    @avatar_exists = 0,
    'ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500) NULL AFTER bio',
    'SELECT 1'
);
PREPARE avatar_stmt FROM @avatar_sql;
EXECUTE avatar_stmt;
DEALLOCATE PREPARE avatar_stmt;

UPDATE users
SET display_name = SUBSTRING_INDEX(email, '@', 1)
WHERE display_name IS NULL OR TRIM(display_name) = '';