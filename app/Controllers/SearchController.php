<?php

namespace App\Controllers;

use App\Services\MongoDatabase;
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

        $db = MongoDatabase::getInstance();
        $allMedia = $db->findMany('media', [], ['sort' => ['id' => -1]]);
        $filtered = [];

        foreach ($allMedia as $item) {
            $creator = $db->findOne('users', ['id' => intval($item['creator_id'] ?? 0)], [
                'projection' => ['email' => 1, 'display_name' => 1, 'avatar_url' => 1]
            ]);
            $itemTags = $db->findMany('person_tags', ['media_id' => intval($item['id'])], ['projection' => ['name' => 1]]);
            $tagNames = array_map(function ($t) {
                return (string) ($t['name'] ?? '');
            }, $itemTags);

            if (!self::matchesSearch($item, $creator, $tagNames, $query, $id, $name, $location, $person)) {
                continue;
            }

            $email = (string) ($creator['email'] ?? '');
            $displayName = trim((string) ($creator['display_name'] ?? ''));
            $item['creator_email'] = $email;
            $item['creatorName'] = $displayName !== '' ? $displayName : explode('@', $email)[0];
            $item['creatorAvatarUrl'] = $creator['avatar_url'] ?? null;

            $ratingDocs = $db->findMany('ratings', ['media_id' => intval($item['id'])]);
            $ratingCount = count($ratingDocs);
            $ratingSum = 0;
            foreach ($ratingDocs as $ratingDoc) {
                $ratingSum += intval($ratingDoc['value'] ?? 0);
            }

            $item['ratings'] = [
                'count' => $ratingCount,
                'average' => $ratingCount > 0 ? round($ratingSum / $ratingCount, 2) : 0
            ];
            $item['commentsCount'] = $db->count('comments', ['media_id' => intval($item['id'])]);
            $item['likesCount'] = $db->count('likes', ['media_id' => intval($item['id'])]);
            $item['tags'] = $itemTags;
            $filtered[] = $item;
        }

        $total = count($filtered);
        $mediaList = array_slice($filtered, intval($offset), intval($perPage));

        // Cache results
        $cache->set($cacheKey, [
            'data' => $mediaList,
            'total' => $total
        ], CACHE_TTL_SEARCH);

        return Response::send(Response::paginated($mediaList, $total, $page, $perPage));
    }

    private static function matchesSearch($item, $creator, $tagNames, $query, $id, $name, $location, $person)
    {
        if ($query !== '') {
            $title = (string) ($item['title'] ?? '');
            $caption = (string) ($item['caption'] ?? '');
            if (stripos($title, $query) === false && stripos($caption, $query) === false) {
                return false;
            }
        }

        if ($id !== '' && intval($item['id'] ?? 0) !== intval($id)) {
            return false;
        }

        if ($name !== '') {
            $displayName = (string) ($creator['display_name'] ?? '');
            $email = (string) ($creator['email'] ?? '');
            $emailPrefix = explode('@', $email)[0];
            if (stripos($displayName, $name) === false && stripos($emailPrefix, $name) === false) {
                return false;
            }
        }

        if ($location !== '') {
            $mediaLocation = (string) ($item['location'] ?? '');
            if (stripos($mediaLocation, $location) === false) {
                return false;
            }
        }

        if ($person !== '') {
            $matched = false;
            foreach ($tagNames as $tagName) {
                if (stripos($tagName, $person) !== false) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    }
}
