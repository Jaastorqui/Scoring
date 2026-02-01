<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ClickHouseService;

class ScoringAnalyticsController
{
    private ClickHouseService $clickHouse;

    private const MAX_LIMIT = 10000;
    private const DEFAULT_LIMIT = 1000;
    private const DAILY_TABLE_MAX_DAYS = 90;

    private const ALLOWED_GROUP_BY_FIELDS = [
        'companyId' => 'company_id',
        'userId' => 'user_id',
        'scoreContext' => 'score_context',
        'day' => 'day',
        'month' => 'month',
    ];

    private const ALLOWED_ORDER_BY_FIELDS = [
        'companyId' => 'company_id',
        'userId' => 'user_id',
        'scoreContext' => 'score_context',
        'day' => 'day',
        'month' => 'month',
        'total_points' => 'total_points',
        'decay_points' => 'decay_points',
        'events_count' => 'events_count',
        'days' => 'days',
    ];

    public function __construct()
    {
        $this->clickHouse = new ClickHouseService();
    }

    /**
     * Handle POST /api/v1/scoring-analytics
     *
     * @param array $payload Request payload
     * @return array Response data
     */
    public function handle(array $payload): array
    {
        $validation = $this->validatePayload($payload);
        if ($validation !== null) {
            return $validation;
        }

        $filter = $payload['filter'];
        $groupBy = $payload['group_by'] ?? [];
        $limit = min($payload['limit'] ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $orderBy = $payload['order_by'] ?? [];

        $dateFrom = $filter['date_from'];
        $dateTo = $filter['date_to'];

        $table = $this->selectTable($dateFrom, $dateTo);
        $dateField = $table === 'company_scores_monthly' ? 'month' : 'day';

        $query = $this->buildQuery($table, $dateField, $groupBy, $orderBy, $filter, $limit);

        $this->logQuery($query['sql'], $query['bindings']);

        try {
            $result = $this->clickHouse->selectWithMeta($query['sql'], $query['bindings']);
        } catch (\Exception $e) {
            error_log('ClickHouse query error: ' . $e->getMessage());
            return [
                'error' => 'Database query failed',
                'code' => 'DATABASE_ERROR',
            ];
        }

        if (empty($result['rows'])) {
            return [
                'error' => 'No data found for specified filters',
                'code' => 'NO_DATA',
            ];
        }

        $data = $this->transformRows($result['rows'], $groupBy, $dateField);

        return [
            'data' => $data,
            'meta' => [
                'total_rows' => $result['count'],
                'limit' => $limit,
                'table_used' => $table,
                'query_time_ms' => $result['time'],
            ],
        ];
    }

    /**
     * Validate the request payload
     *
     * @param array $payload
     * @return array|null Error response or null if valid
     */
    private function validatePayload(array $payload): ?array
    {
        if (!isset($payload['filter']) || !is_array($payload['filter'])) {
            return [
                'error' => 'Missing required field: filter',
                'code' => 'VALIDATION_ERROR',
            ];
        }

        $filter = $payload['filter'];

        if (!isset($filter['date_from'])) {
            return [
                'error' => 'Missing required field: filter.date_from',
                'code' => 'VALIDATION_ERROR',
            ];
        }

        if (!isset($filter['date_to'])) {
            return [
                'error' => 'Missing required field: filter.date_to',
                'code' => 'VALIDATION_ERROR',
            ];
        }

        if (!$this->isValidDate($filter['date_from'])) {
            return [
                'error' => 'Invalid date_from format. Expected YYYY-MM-DD',
                'code' => 'VALIDATION_ERROR',
            ];
        }

        if (!$this->isValidDate($filter['date_to'])) {
            return [
                'error' => 'Invalid date_to format. Expected YYYY-MM-DD',
                'code' => 'VALIDATION_ERROR',
            ];
        }

        if ($filter['date_from'] > $filter['date_to']) {
            return [
                'error' => 'date_from must be less than or equal to date_to',
                'code' => 'VALIDATION_ERROR',
            ];
        }

        if (isset($filter['companyId'])) {

            if (!isset($filter['companyId']['include']) || !is_bool($filter['companyId']['include'])) {
                return [
                    'error' => 'filter.companyId.include must be a boolean',
                    'code' => 'VALIDATION_ERROR',
                ];
            }

            if (isset($filter['companyId']['value']) && is_array($filter['companyId']['value'])) {
                return [
                    'error' => 'filter.companyId.value must be an integer',
                    'code' => 'VALIDATION_ERROR',
                ];
            }
        }

        if (isset($payload['group_by'])) {
            if (!is_array($payload['group_by'])) {
                return [
                    'error' => 'group_by must be an array of strings',
                    'code' => 'VALIDATION_ERROR',
                ];
            }

            foreach ($payload['group_by'] as $field) {
                if (!is_string($field) || !isset(self::ALLOWED_GROUP_BY_FIELDS[$field])) {
                    return [
                        'error' => "Invalid group_by field: {$field}. Allowed: " . implode(', ', array_keys(self::ALLOWED_GROUP_BY_FIELDS)),
                        'code' => 'VALIDATION_ERROR',
                    ];
                }
            }
        }

        if (isset($payload['limit'])) {
            if (!is_int($payload['limit']) || $payload['limit'] < 1 || $payload['limit'] > self::MAX_LIMIT) {
                return [
                    'error' => 'limit must be an integer between 1 and ' . self::MAX_LIMIT,
                    'code' => 'VALIDATION_ERROR',
                ];
            }
        }

        if (isset($payload['order_by'])) {
            if (!is_array($payload['order_by'])) {
                return [
                    'error' => 'order_by must be an array of objects',
                    'code' => 'VALIDATION_ERROR',
                ];
            }

            foreach ($payload['order_by'] as $order) {
                if (!is_array($order)) {
                    return [
                        'error' => 'order_by items must be objects with field and order properties',
                        'code' => 'VALIDATION_ERROR',
                    ];
                }

                if (!isset($order['field']) || !is_string($order['field'])) {
                    return [
                        'error' => 'order_by.field is required and must be a string',
                        'code' => 'VALIDATION_ERROR',
                    ];
                }

                if (!isset(self::ALLOWED_ORDER_BY_FIELDS[$order['field']])) {
                    return [
                        'error' => "Invalid order_by field: {$order['field']}. Allowed: " . implode(', ', array_keys(self::ALLOWED_ORDER_BY_FIELDS)),
                        'code' => 'VALIDATION_ERROR',
                    ];
                }

                if (!isset($order['order']) || !in_array(strtolower($order['order']), ['asc', 'desc'], true)) {
                    return [
                        'error' => 'order_by.order must be "asc" or "desc"',
                        'code' => 'VALIDATION_ERROR',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Validate date format YYYY-MM-DD
     *
     * @param string $date
     * @return bool
     */
    private function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $parts = explode('-', $date);
        return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
    }

    /**
     * Select appropriate table based on date range
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @return string Table name
     */
    private function selectTable(string $dateFrom, string $dateTo): string
    {
        $from = new \DateTime($dateFrom);
        $to = new \DateTime($dateTo);
        $diff = $from->diff($to)->days;

        return $diff >= self::DAILY_TABLE_MAX_DAYS ? 'company_scores_monthly' : 'company_scores_daily';
    }

    /**
     * Build the SQL query
     *
     * @param string $table
     * @param string $dateField
     * @param array $groupBy
     * @param array $orderBy
     * @param array $filter
     * @param int $limit
     * @return array{sql: string, bindings: array}
     */
    private function buildQuery(
        string $table,
        string $dateField,
        array $groupBy,
        array $orderBy,
        array $filter,
        int $limit
    ): array {
        $bindings = [];

        $groupByDbFields = [];
        foreach ($groupBy as $field) {
            $dbField = self::ALLOWED_GROUP_BY_FIELDS[$field];
            if ($dbField === 'day' && $dateField === 'month') {
                $dbField = 'month';
            }
            $groupByDbFields[] = $dbField;
        }

        $selectFields = empty($groupByDbFields)
            ? ''
            : implode(', ', $groupByDbFields) . ',';

        $sql = "SELECT 
    {$selectFields}
    sum(total_points) AS total_points,
    sum(decay_points) AS decay_points,
    sum(events_count) AS events_count,
    count() AS days
FROM {$table}
WHERE 1=1";

        $sql .= " AND {$dateField} >= :date_from";
        $bindings['date_from'] = $filter['date_from'];

        $sql .= " AND {$dateField} <= :date_to";
        $bindings['date_to'] = $filter['date_to'];

        if (isset($filter['companyId']) && !empty($filter['companyId']['value'])) {
            $companyId = $filter['companyId']['value'];
            $include = $filter['companyId']['include'];

            $operator = $include ? '=' : '!=';
            $sql .= " AND company_id {$operator} $companyId";
        }

        if (!empty($groupByDbFields)) {
            $sql .= "\nGROUP BY " . implode(', ', $groupByDbFields);
        }

        if (!empty($orderBy)) {
            $orderClauses = [];
            foreach ($orderBy as $order) {
                $dbField = self::ALLOWED_ORDER_BY_FIELDS[$order['field']];
                if ($dbField === 'day' && $dateField === 'month') {
                    $dbField = 'month';
                }
                $direction = strtoupper($order['order']);
                $orderClauses[] = "{$dbField} {$direction}";
            }
            $sql .= "\nORDER BY " . implode(', ', $orderClauses);
        }

        $sql .= "\nLIMIT {$limit}";

        return [
            'sql' => $sql,
            'bindings' => $bindings,
        ];
    }

    /**
     * Transform database rows to API response format
     *
     * @param array $rows
     * @param array $groupBy
     * @param string $dateField
     * @return array
     */
    private function transformRows(array $rows, array $groupBy, string $dateField): array
    {
        $result = [];

        foreach ($rows as $row) {
            $item = [];

            foreach ($groupBy as $field) {
                $dbField = self::ALLOWED_GROUP_BY_FIELDS[$field];
                if ($dbField === 'day' && $dateField === 'month') {
                    $dbField = 'month';
                }

                if (isset($row[$dbField])) {
                    $item[$field] = $row[$dbField];
                }
            }

            $item['total_points'] = (int)($row['total_points'] ?? 0);
            $item['decay_points'] = (int)($row['decay_points'] ?? 0);
            $item['events_count'] = (int)($row['events_count'] ?? 0);
            $item['days'] = (int)($row['days'] ?? 0);

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Log the generated SQL query
     *
     * @param string $sql
     * @param array $bindings
     */
    private function logQuery(string $sql, array $bindings): void
    {
        $logMessage = sprintf(
            "[%s] SQL Query:\n%s\nBindings: %s",
            date('Y-m-d H:i:s'),
            $sql,
            json_encode($bindings)
        );
        error_log($logMessage);
    }
}
