-- Seed data for database
USE insta_app;

-- Insert admin user (password: admin123)
INSERT INTO users (email, password_hash, role) VALUES
('admin@insta.local', '$2y$10$N9qo8uLOickgx2ZMRZoMye/pxOLUqPW6K6zTk2P0rQcKi3x/PpqSO', 'ADMIN');

-- Insert creator users (password: creator123)
INSERT INTO users (email, password_hash, role) VALUES
('creator1@insta.local', '$2y$10$i9xDpPWMxAKqvOhNwQSQ4OE6WM6UqLqG4wLqH6c8vZp2nLqM5yd0a', 'CREATOR'),
('creator2@insta.local', '$2y$10$i9xDpPWMxAKqvOhNwQSQ4OE6WM6UqLqG4wLqH6c8vZp2nLqM5yd0a', 'CREATOR');

-- Insert consumer users (password: consumer123)
INSERT INTO users (email, password_hash, role) VALUES
('consumer1@insta.local', '$2y$10$8qHP2Pq0C6pxfL6sL3f2u.v4O1zJ0XnV5cA5kE8nU3pT9qR3Z1b6m', 'CONSUMER'),
('consumer2@insta.local', '$2y$10$8qHP2Pq0C6pxfL6sL3f2u.v4O1zJ0XnV5cA5kE8nU3pT9qR3Z1b6m', 'CONSUMER');

-- Insert sample media from creator 1
INSERT INTO media (creator_id, type, url, thumbnail_url, title, caption, location) VALUES
(2, 'image', 'https://via.placeholder.com/600x400?text=Beach+Sunset', 'https://via.placeholder.com/150x150?text=Beach', 'Beautiful Beach Sunset', 'Amazing sunset at the beach today!', 'Malibu Beach, CA'),
(2, 'image', 'https://via.placeholder.com/600x400?text=Mountain+View', 'https://via.placeholder.com/150x150?text=Mountain', 'Mountain Peak Adventure', 'Reached the summit today with amazing views!', 'Rocky Mountains, CO');

-- Insert sample media from creator 2
INSERT INTO media (creator_id, type, url, thumbnail_url, title, caption, location) VALUES
(3, 'image', 'https://via.placeholder.com/600x400?text=City+Lights', 'https://via.placeholder.com/150x150?text=City', 'City Lights at Night', 'Downtown skyline never looked better', 'New York City, NY'),
(3, 'image', 'https://via.placeholder.com/600x400?text=Forest+Trail', 'https://via.placeholder.com/150x150?text=Forest', 'Forest Hiking Trail', 'Nature walk through the enchanted forest', 'Portland, OR');

-- Insert person tags
INSERT INTO person_tags (media_id, name) VALUES
(1, 'John'),
(1, 'Sarah'),
(2, 'Alex'),
(3, 'Mike'),
(4, 'Emma');

-- Insert sample comments
INSERT INTO comments (media_id, user_id, text) VALUES
(1, 4, 'Absolutely stunning! 😍'),
(1, 5, 'Wish I was there!'),
(2, 5, 'This is amazing! Great shots'),
(3, 4, 'The city never sleeps!'),
(4, 4, 'Beautiful nature photography');

-- Insert sample ratings
INSERT INTO ratings (media_id, user_id, value) VALUES
(1, 4, 5),
(1, 5, 5),
(2, 4, 4),
(2, 5, 5),
(3, 4, 5),
(3, 5, 4),
(4, 4, 5),
(4, 5, 5);
