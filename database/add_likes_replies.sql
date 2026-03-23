-- Add likes table
CREATE TABLE IF NOT EXISTS likes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    media_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_like (media_id, user_id),
    INDEX idx_media (media_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add replies to comments
ALTER TABLE comments ADD COLUMN reply_to_id INT DEFAULT NULL AFTER user_id;
ALTER TABLE comments ADD FOREIGN KEY (reply_to_id) REFERENCES comments(id) ON DELETE CASCADE;
ALTER TABLE comments ADD INDEX idx_reply_to (reply_to_id);
