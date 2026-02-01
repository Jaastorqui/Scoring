<?php

declare(strict_types=1);

namespace App\Service;

use ClickHouseDB\Client;

class ClickHouseService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'host' => $this->parseHost(getenv('CLICKHOUSE_URL') ?: 'http://clickhouse:8123'),
            'port' => $this->parsePort(getenv('CLICKHOUSE_URL') ?: 'http://clickhouse:8123'),
            'username' => getenv('CLICKHOUSE_USER') ?: 'app',
            'password' => getenv('CLICKHOUSE_PASSWORD') ?: 'app',
        ]);

        $this->client->database(getenv('CLICKHOUSE_DB') ?: 'scoring');
        $this->client->setTimeout(10);
        $this->client->setConnectTimeOut(5);
    }

    private function parseHost(string $url): string
    {
        $parsed = parse_url($url);
        return $parsed['host'] ?? 'clickhouse';
    }

    private function parsePort(string $url): int
    {
        $parsed = parse_url($url);
        return $parsed['port'] ?? 8123;
    }

    /**
     * Execute a SELECT query with bindings
     *
     * @param string $sql SQL query with placeholders
     * @param array $bindings Parameter bindings
     * @return array Query results
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->client->select($sql, $bindings);
        return $statement->rows();
    }

    /**
     * Execute a SELECT query and return metadata
     *
     * @param string $sql SQL query with placeholders
     * @param array $bindings Parameter bindings
     * @return array{rows: array, count: int, time: float}
     */
    public function selectWithMeta(string $sql, array $bindings = []): array
    {
        $startTime = microtime(true);
        $statement = $this->client->select($sql, $bindings);
        $endTime = microtime(true);

        return [
            'rows' => $statement->rows(),
            'count' => $statement->count(),
            'time' => round(($endTime - $startTime) * 1000, 2),
        ];
    }

    /**
     * Ping ClickHouse server
     *
     * @return bool
     */
    public function ping(): bool
    {
        return $this->client->ping(false);
    }

    /**
     * Get the underlying client for advanced operations
     *
     * @return Client
     */
    public function getClient(): Client
    {
        return $this->client;
    }
}
