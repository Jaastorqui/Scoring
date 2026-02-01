<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'name' => 'ClickHouse Scoring Analytics API',
    'version' => '1.0.0',
    'endpoints' => [
        'POST /api/v1/scoring-analytics' => 'Query scoring analytics data',
    ],
]);
