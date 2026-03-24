<?php

namespace App\Services;

use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;

class MongoDatabase
{
    private static $instance = null;
    private $manager;
    private $dbName;

    private function __construct()
    {
        $this->manager = new Manager(MONGO_URI);
        $this->dbName = MONGO_DB_NAME;
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function insertOne($collection, array $document)
    {
        if (!isset($document['id'])) {
            $document['id'] = $this->nextId($collection);
        }

        if (!isset($document['created_at'])) {
            $document['created_at'] = date('Y-m-d H:i:s');
        }

        if (!isset($document['updated_at'])) {
            $document['updated_at'] = date('Y-m-d H:i:s');
        }

        $bulk = new BulkWrite();
        $id = $bulk->insert($this->normalizeDates($document));
        $this->manager->executeBulkWrite($this->namespace($collection), $bulk);
        return $document['id'];
    }

    public function updateOne($collection, array $filter, array $update, array $options = [])
    {
        if (!isset($update['updated_at'])) {
            $update['updated_at'] = date('Y-m-d H:i:s');
        }

        $bulk = new BulkWrite();
        $bulk->update($filter, ['$set' => $this->normalizeDates($update)], $options + ['multi' => false, 'upsert' => false]);
        $result = $this->manager->executeBulkWrite($this->namespace($collection), $bulk);
        return $result->getModifiedCount();
    }

    public function updateMany($collection, array $filter, array $update, array $options = [])
    {
        if (!isset($update['updated_at'])) {
            $update['updated_at'] = date('Y-m-d H:i:s');
        }

        $bulk = new BulkWrite();
        $bulk->update($filter, ['$set' => $this->normalizeDates($update)], $options + ['multi' => true, 'upsert' => false]);
        $result = $this->manager->executeBulkWrite($this->namespace($collection), $bulk);
        return $result->getModifiedCount();
    }

    public function deleteOne($collection, array $filter)
    {
        $bulk = new BulkWrite();
        $bulk->delete($filter, ['limit' => 1]);
        $result = $this->manager->executeBulkWrite($this->namespace($collection), $bulk);
        return $result->getDeletedCount();
    }

    public function deleteMany($collection, array $filter)
    {
        $bulk = new BulkWrite();
        $bulk->delete($filter, ['limit' => 0]);
        $result = $this->manager->executeBulkWrite($this->namespace($collection), $bulk);
        return $result->getDeletedCount();
    }

    public function findOne($collection, array $filter = [], array $options = [])
    {
        $query = new Query($filter, $options + ['limit' => 1]);
        $cursor = $this->manager->executeQuery($this->namespace($collection), $query);
        $rows = $this->toArray($cursor);
        return $rows[0] ?? null;
    }

    public function findMany($collection, array $filter = [], array $options = [])
    {
        $query = new Query($filter, $options);
        $cursor = $this->manager->executeQuery($this->namespace($collection), $query);
        return $this->toArray($cursor);
    }

    public function count($collection, array $filter = [])
    {
        $query = empty($filter) ? (object) [] : $filter;
        $command = new Command([
            'count' => $collection,
            'query' => $query
        ]);
        $result = $this->manager->executeCommand($this->dbName, $command)->toArray();
        return (int) ($result[0]->n ?? 0);
    }

    public function ping()
    {
        $command = new Command(['ping' => 1]);
        $result = $this->manager->executeCommand($this->dbName, $command)->toArray();
        return isset($result[0]->ok) && (float) $result[0]->ok === 1.0;
    }

    private function nextId($collection)
    {
        $command = new Command([
            'findAndModify' => 'counters',
            'query' => ['_id' => $collection],
            'update' => ['$inc' => ['seq' => 1]],
            'new' => true,
            'upsert' => true
        ]);

        $result = $this->manager->executeCommand($this->dbName, $command)->toArray();
        $value = $result[0]->value ?? null;

        if ($value && isset($value->seq)) {
            return (int) $value->seq;
        }

        $docs = $this->findMany($collection, [], ['sort' => ['id' => -1], 'limit' => 1]);
        if (!empty($docs) && isset($docs[0]['id'])) {
            return intval($docs[0]['id']) + 1;
        }

        return 1;
    }

    private function namespace($collection)
    {
        return $this->dbName . '.' . $collection;
    }

    private function toArray($cursor)
    {
        $rows = [];
        foreach ($cursor as $document) {
            $rows[] = json_decode(json_encode($document), true);
        }
        return $rows;
    }

    private function normalizeDates(array $data)
    {
        // Keep date fields as SQL-style strings for API response compatibility.
        return $data;
    }
}