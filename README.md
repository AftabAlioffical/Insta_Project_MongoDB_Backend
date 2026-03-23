# Insta_Project

Insta_Project is a Dockerized Instagram-style media sharing application with a PHP API, a static HTML frontend, MySQL persistence, Redis-backed caching/rate limiting, and an admin dashboard that can manage users, posts, comments, likes, and role assignment.

## Current State

- Frontend URL: `http://localhost:8080/login.html`
- Main user dashboard: `http://localhost:8080/dashboard.html`
- Current admin UI: `http://localhost:8080/admin-dashboard.html`
- Uploads are stored locally in `public/assets/uploads`
- Redis is actively used for feed/search caching and rate limiting
- MinIO is provisioned in Docker, but the current upload controller still writes files to local disk
- Profile display name, bio, and avatar edits are currently stored in browser `localStorage`, not persisted in MySQL

## Architecture

| Service | Tech | Port | Responsibility |
| --- | --- | --- | --- |
| `frontend` | Nginx | `8080` | Serves static HTML/CSS/JS from `public/` and proxies `/api/*` to PHP |
| `php` | PHP 8.2 + Apache | internal | Runs the REST API and router in `public/index.php` |
| `mysql` | MySQL 8.0 | `3306` | Stores users, posts, comments, ratings, likes, and tags |
| `redis` | Redis 7 | `6379` | Used for login/upload throttling and feed/search/media cache entries |
| `minio` | MinIO | `9000`, `9001` | Available for S3-compatible storage, not currently used by `MediaController` |

Request flow:

1. The browser loads a page from the Nginx frontend container.
2. Frontend JavaScript calls `/api/...`.
3. Nginx proxies `/api` requests to the PHP container.
4. PHP routes the request in `public/index.php` and dispatches a controller.
5. Controllers use `Database`, `CacheService`, `JWTService`, and `AuthMiddleware` as needed.

## Key Features

- JWT authentication with roles: `ADMIN`, `CREATOR`, `CONSUMER`
- Registration flow that creates `CONSUMER` users by default
- Media upload for authenticated users with title, caption, location, and person tags
- Feed, search, ratings, comments, and likes
- User dashboard with profile editing, avatar upload, and personal post grid
- Admin dashboard with CRUD operations for users, posts, comments, and likes
- Role overview panel showing the supported roles and user counts

## Project Structure

```text
app/
   Controllers/    API handlers
   Middleware/     CORS and authentication/role checks
   Services/       DB, JWT, cache, rate limit, response helpers
config/
   config.php      Runtime constants and defaults
database/
   schema.sql              Base schema
   seed.sql                Seed users and sample content
   add_likes_replies.sql   Extra schema required for likes and comment replies
   init.sh                 Database bootstrap script
nginx/
   default.conf     Frontend static hosting and /api proxy
public/
   *.html           Static frontend pages
   assets/          CSS, JS, uploads
   index.php        PHP router entrypoint
docker-compose.yml
README.md
```

## Quick Start

### Docker setup

1. Start the stack:

    ```bash
    docker compose up --build -d
    ```

2. Initialize the database:

    ```bash
    docker compose exec php bash /var/www/html/database/init.sh
    ```

3. Open the app:

    - Login: `http://localhost:8080/login.html`
    - Admin dashboard: `http://localhost:8080/admin-dashboard.html`
    - MinIO console: `http://localhost:9001`

4. Log in with one of the seeded accounts listed below.

Notes:

- For the Docker workflow, copying `.env.example` to `.env` is optional. `docker-compose.yml` already provides the runtime container environment.
- `database/init.sh` now applies `schema.sql`, `seed.sql`, and `add_likes_replies.sql`, so a fresh setup includes the `likes` table and `comments.reply_to_id` column required by the current codebase.

### Clean reset

Use this when you want a fully fresh database and cache state:

```bash
docker compose down -v
docker compose up --build -d
docker compose exec php bash /var/www/html/database/init.sh
```

## Seeded Accounts

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@insta.local` | `admin123` |
| Creator | `creator1@insta.local` | `creator123` |
| Creator | `creator2@insta.local` | `creator123` |
| Consumer | `consumer1@insta.local` | `consumer123` |
| Consumer | `consumer2@insta.local` | `consumer123` |

## Frontend Pages

| Page | URL | Purpose |
| --- | --- | --- |
| Login | `/login.html` | Main entry page for authentication |
| Signup | `/signup.html` | Self-registration as a consumer |
| Consumer feed | `/consumer-feed.html` | Main feed experience |
| Dashboard | `/dashboard.html` | Current user dashboard with upload modal and profile editing |
| Dashboard fallback | `/dashboard-simple.html` | Simplified dashboard variant |
| Admin dashboard | `/admin-dashboard.html` | Current admin CRUD interface |
| Legacy admin page | `/admin.html` | Older admin page kept in `public/` |
| Creator dashboard | `/creator-dashboard.html` | Alternate creator-focused page |
| Feed | `/feed.html` | Alternate feed page |
| Media detail | `/media-detail.html` | Single-media view |
| Search | `/search.html` | Search UI |
| User profile | `/user-profile.html` | Public user profile page |
| Test feed | `/test-feed.html` | Debug/test page |

## Authentication and Roles

- `POST /api/auth/login` returns a JWT and the current user object.
- The frontend stores auth state in `localStorage` keys such as `token` and `user`.
- `GET /api/auth/me` is the source of truth for the signed-in user.
- Role checks in `AuthMiddleware` are DB-backed, so admin access follows the current database role even if an old JWT or cached frontend user object is stale.
- Supported roles are fixed to:
   - `ADMIN`: full admin CRUD access
   - `CREATOR`: content creation and own-post management intent
   - `CONSUMER`: browsing, rating, liking, and commenting intent
- Self-registration always creates a `CONSUMER` user.
- The current upload flow allows any authenticated user to upload media.

## API Conventions

- Base URL: `http://localhost:8080/api`
- Most endpoints return JSON in one of these shapes:

Success:

```json
{
   "success": true,
   "message": "...",
   "data": {}
}
```

Paginated:

```json
{
   "success": true,
   "data": [],
   "pagination": {
      "total": 0,
      "currentPage": 1,
      "perPage": 20,
      "totalPages": 0
   }
}
```

Error:

```json
{
   "success": false,
   "error": {
      "message": "...",
      "code": 400
   },
   "data": null
}
```

## API Reference

### Health and auth

| Method | Route | Notes |
| --- | --- | --- |
| `GET` | `/api/health` | Liveness check |
| `GET` | `/api/ready` | Readiness check |
| `POST` | `/api/auth/login` | JSON body: `email`, `password` |
| `POST` | `/api/auth/register` | JSON body: `email`, `password`; creates `CONSUMER` |
| `GET` | `/api/auth/me` | Requires bearer token |

### Media and engagement

| Method | Route | Notes |
| --- | --- | --- |
| `GET` | `/api/media` | Feed listing; supports `page` and optional `creator_id` (`me` or numeric id) |
| `POST` | `/api/media` | Multipart upload; requires auth |
| `GET` | `/api/media/{id}` | Single media item |
| `DELETE` | `/api/media/{id}` | Owner or admin only |
| `GET` | `/api/media/{id}/comments` | Paginated comments |
| `POST` | `/api/media/{id}/comments` | JSON body: `text`; requires auth |
| `GET` | `/api/media/{id}/ratings` | Ratings list and statistics |
| `POST` | `/api/media/{id}/ratings` | JSON body: `value` from `1` to `5`; requires auth |
| `GET` | `/api/media/{id}/likes` | Like count and `userLiked` when authenticated |
| `POST` | `/api/media/{id}/likes` | Toggle like; requires auth |
| `DELETE` | `/api/comments/{id}` | Delete own comment or admin delete |
| `DELETE` | `/api/ratings/{id}` | Delete own rating |

Upload fields for `POST /api/media`:

- `file`: required multipart file
- `title`: optional, defaults to `Untitled`
- `caption`: optional
- `location`: optional
- `personTags`: optional comma-separated string

### Users and search

| Method | Route | Notes |
| --- | --- | --- |
| `GET` | `/api/users/{id}` | Returns user summary, latest posts, total likes, total comments |
| `GET` | `/api/users/search?q=...` | Query must be at least 2 characters |
| `GET` | `/api/search` | Supports `q`, `location`, `person`, `page` |

### Admin API

All admin endpoints require an `ADMIN` role.

| Method | Route | Notes |
| --- | --- | --- |
| `GET` | `/api/admin/users` | List users; supports `page`, `limit`, `q` |
| `POST` | `/api/admin/users` | Create user with `email`, `password`, `role` |
| `PUT` | `/api/admin/users/{id}` | Update `email`, `password`, and/or `role` |
| `DELETE` | `/api/admin/users/{id}` | Cannot delete yourself or the last admin |
| `GET` | `/api/admin/posts` | List posts with comment and like counts |
| `PUT` | `/api/admin/posts/{id}` | Update `title`, `caption`, `location`, `type` |
| `DELETE` | `/api/admin/posts/{id}` | Delete post |
| `GET` | `/api/admin/comments` | List comments |
| `POST` | `/api/admin/comments` | Create comment with `media_id`, `text`, optional `user_id` |
| `PUT` | `/api/admin/comments/{id}` | Update comment text |
| `DELETE` | `/api/admin/comments/{id}` | Delete comment |
| `GET` | `/api/admin/likes` | List likes |
| `POST` | `/api/admin/likes` | Create like with `media_id`, `user_id` |
| `PUT` | `/api/admin/likes/{id}` | Reassign like to a different media/user pair |
| `DELETE` | `/api/admin/likes/{id}` | Delete like |
| `GET` | `/api/admin/roles` | Returns fixed role definitions, permissions, and current counts |

Important role note:

- The admin dashboard includes a role-design panel, but current roles are fixed to `ADMIN`, `CREATOR`, and `CONSUMER`. It is a role overview and assignment flow, not a custom role builder.

## Sample Requests

Login:

```bash
curl -X POST http://localhost:8080/api/auth/login \
   -H "Content-Type: application/json" \
   -d '{"email":"admin@insta.local","password":"admin123"}'
```

Upload media:

```bash
curl -X POST http://localhost:8080/api/media \
   -H "Authorization: Bearer <token>" \
   -F "file=@sample.jpg" \
   -F "title=Sunset" \
   -F "caption=Weekend upload" \
   -F "location=Beach" \
   -F "personTags=Alex,Sarah"
```

## Data Model

Current application behavior depends on these tables:

- `users`: accounts and role assignment
- `media`: uploaded posts and metadata
- `person_tags`: comma-separated upload tags normalized into rows
- `comments`: comments on posts
- `ratings`: 1-to-5 post ratings
- `likes`: per-user likes on posts

Schema detail:

- `database/schema.sql` provides the base schema.
- `database/add_likes_replies.sql` adds the `likes` table and `comments.reply_to_id`.
- `database/init.sh` applies both so new environments match the current code.

Behavior detail:

- Public comment creation currently writes top-level comments only.
- Admin comment listing exposes `reply_to_id` when present.
- `thumbnail_url` is stored for media records, but the app does not currently generate thumbnails.

## Caching, Rate Limiting, and Storage

- Feed, media, and search reads use Redis-backed caching when Redis is available.
- Login throttling is enforced with Redis using `rl_login_*` keys.
- Upload throttling is enforced with Redis using `rl_upload_*` keys.
- A `RATE_LIMIT_API` constant exists in configuration, but there is no global API-wide rate limiter currently wired into all controllers.
- Uploaded files are saved in `public/assets/uploads`, so they live in the workspace filesystem.
- MinIO configuration exists in `config/config.php` and `docker-compose.yml`, but `MediaController` currently stores uploads locally even when MinIO is running.

## Configuration Notes

Key runtime defaults:

| Setting | Current default | Source |
| --- | --- | --- |
| Database host | `mysql` in Docker, `127.0.0.1` fallback | `docker-compose.yml`, `config/config.php` |
| Database name | `insta_app` | Compose and config |
| JWT secret | `supersecretkey123` in Docker | `docker-compose.yml` |
| Frontend base URL | `http://localhost:8080` | `config/config.php` / `.env.example` |
| Redis host | `redis` in Docker | `docker-compose.yml` |
| S3 usage flag | `false` by default | `.env.example`, `config/config.php` |

`.env.example` is a reference for non-Docker or overridden local runs. The current Docker Compose stack does not require it to boot.

## Troubleshooting

### Too many login attempts

The login endpoint is rate-limited. For local development, either wait for the limiter window to expire or clear Redis:

```bash
docker compose exec redis redis-cli FLUSHDB
```

This clears cache entries and local rate-limit keys.

### Admin dashboard says access denied

- Confirm the user has role `ADMIN` in the `users` table.
- Log out and log back in.
- Use `GET /api/auth/me` to verify the current role being resolved by the backend.

### Fresh setup has likes-related SQL errors

Run the bootstrap script again:

```bash
docker compose exec php bash /var/www/html/database/init.sh
```

The current bootstrap now applies `database/add_likes_replies.sql` in addition to the base schema.

### Upload succeeds but media files are missing from the UI

- Confirm the file exists under `public/assets/uploads`
- Confirm you are opening the app through `http://localhost:8080`
- Check `docker compose ps` to ensure both `frontend` and `php` are running

### Frontend loads but API requests 404 or fail

- Verify the frontend container is running
- Verify `nginx/default.conf` is mounted correctly
- Make sure requests are sent to `/api/...`, not directly to PHP container internals

## Known Limitations

- No automated test suite is included yet.
- MinIO is provisioned but not integrated into the live upload path.
- Profile avatar, display name, and bio edits are local-browser state, not shared server-side.
- Thumbnail URLs are stored, but thumbnail generation is not implemented.
- `public/` still contains some older or alternate pages alongside the current dashboard flows.
