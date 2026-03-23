<?php

namespace App\Controllers;

use App\Services\Database;
use App\Services\Response;
use App\Services\CacheService;

class SearchController
{
    public static function search()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return Response::send(Response::error('Method not allowed', 405));
        }

        $query = isset($_GET['q']) ? trim($_GET['q']) : '';
        $id = isset($_GET['id']) ? trim($_GET['id']) : '';
        $name = isset($_GET['name']) ? trim($_GET['name']) : '';
        $location = isset($_GET['location']) ? trim($_GET['location']) : '';
        $person = isset($_GET['person']) ? trim($_GET['person']) : '';
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

        // Validate input
        if (empty($query) && empty($id) && empty($name) && empty($location) && empty($person)) {
            return Response::send(Response::error('At least one search parameter required', 400));
        }

        // Create cache key
        $cacheKey = 'search_v2_' . md5($query . $id . $name . $location . $person . $page);
        $cache = CacheService::getInstance();
        $cached = $cache->get($cacheKey);

        if ($cached !== null) {
            return Response::send(Response::paginated($cached['data'], $cached['total'], $page, ITEMS_PER_PAGE));
        }

        $perPage = ITEMS_PER_PAGE;
        $offset = ($page - 1) * $perPage;

        $db = Database::getInstance();
        $params = [];
        $conditions = [];

        // Build search conditions
        if (!empty($query)) {
            $conditions[] = '(MATCH(title, caption) AGAINST(? IN BOOLEAN MODE) OR title LIKE ? OR caption LIKE ?)';
            $params[] = $query;
            $params[] = "%{$query}%";
            $params[] = "%{$query}%";
        }

        if (!empty($id)) {
            $conditions[] = 'm.id = ?';
            $params[] = intval($id);
        }

        if (!empty($name)) {
            $conditions[] = '(u.display_name LIKE ? OR SUBSTRING_INDEX(u.email, "@", 1) LIKE ?)';
            $params[] = "%{$name}%";
            $params[] = "%{$name}%";
        }

        if (!empty($location)) {
            $conditions[] = 'location LIKE ?';
            $params[] = "%{$location}%";
        }

        if (!empty($person)) {
            // Search for person tags
                $sql = 'SELECT m.*, u.email as creator_email,
                    COALESCE(NULLIF(u.display_name, ""), SUBSTRING_INDEX(u.email, "@", 1)) as creatorName,
                    u.avatar_url as creatorAvatarUrl
                    FROM media m 
                    JOIN users u ON m.creator_id = u.id
                    JOIN person_tags pt ON m.id = pt.media_id
                    WHERE pt.name LIKE ? ';
            
            if (!empty($query)) {
                $sql .= 'AND (MATCH(m.title, m.caption) AGAINST(? IN BOOLEAN MODE) OR m.title LIKE ? OR m.caption LIKE ?)';
            }

            if (!empty($id)) {
                $sql .= ' AND m.id = ?';
            }

            if (!empty($name)) {
                $sql .= ' AND (u.display_name LIKE ? OR SUBSTRING_INDEX(u.email, "@", 1) LIKE ?)';
            }
            
            if (!empty($location)) {
                $sql .= ' AND m.location LIKE ?';
            }

            $whereParams = ["%{$person}%"];
            
            if (!empty($query)) {
                $whereParams[] = $query;
                $whereParams[] = "%{$query}%";
                $whereParams[] = "%{$query}%";
            }

            if (!empty($id)) {
                $whereParams[] = intval($id);
            }

            if (!empty($name)) {
                $whereParams[] = "%{$name}%";
                $whereParams[] = "%{$name}%";
            }
            
            if (!empty($location)) {
                $whereParams[] = "%{$location}%";
            }

            // Count total
            $countSql = 'SELECT COUNT(DISTINCT m.id) as count FROM media m 
                         JOIN person_tags pt ON m.id = pt.media_id
                         JOIN users u ON m.creator_id = u.id
                         WHERE pt.name LIKE ? ';
            
            if (!empty($query)) {
                $countSql .= 'AND (MATCH(m.title, m.caption) AGAINST(? IN BOOLEAN MODE) OR m.title LIKE ? OR m.caption LIKE ?)';
            }

            if (!empty($id)) {
                $countSql .= ' AND m.id = ?';
            }

            if (!empty($name)) {
                $countSql .= ' AND (u.display_name LIKE ? OR SUBSTRING_INDEX(u.email, "@", 1) LIKE ?)';
            }
            
            if (!empty($location)) {
                $countSql .= ' AND m.location LIKE ?';
            }

            $total = $db->fetch($countSql, $whereParams)['count'];

            $sql .= ' GROUP BY m.id ORDER BY m.created_at DESC LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset);

            $mediaList = $db->fetchAll($sql, $whereParams);
        } else {
            $whereClause = implode(' AND ', $conditions);

            $total = $db->fetch(
                'SELECT COUNT(DISTINCT m.id) as count FROM media m 
                 JOIN users u ON m.creator_id = u.id
                 WHERE ' . $whereClause,
                $params
            )['count'];

            $mediaList = $db->fetchAll(
                'SELECT m.*, u.email as creator_email,
                 COALESCE(NULLIF(u.display_name, ""), SUBSTRING_INDEX(u.email, "@", 1)) as creatorName,
                 u.avatar_url as creatorAvatarUrl FROM media m 
                 JOIN users u ON m.creator_id = u.id
                 WHERE ' . $whereClause . '
                 ORDER BY m.created_at DESC LIMIT ' . intval($perPage) . ' OFFSET ' . intval($offset),
                $params
            );
        }

        // Fetch additional data
        foreach ($mediaList as &$item) {
            $item['ratings'] = $db->fetch(
                'SELECT COUNT(*) as count, AVG(value) as average FROM ratings WHERE media_id = ?',
                [$item['id']]
            );

            $item['commentsCount'] = $db->fetch(
                'SELECT COUNT(*) as count FROM comments WHERE media_id = ?',
                [$item['id']]
            )['count'];

            $item['likesCount'] = $db->fetch(
                'SELECT COUNT(*) as count FROM likes WHERE media_id = ?',
                [$item['id']]
            )['count'];

            $item['tags'] = $db->fetchAll(
                'SELECT name FROM person_tags WHERE media_id = ?',
                [$item['id']]
            );
        }

        // Cache results
        $cache->set($cacheKey, [
            'data' => $mediaList,
            'total' => $total
        ], CACHE_TTL_SEARCH);

        return Response::send(Response::paginated($mediaList, $total, $page, $perPage));
    }
}
