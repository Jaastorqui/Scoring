<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');

$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

$path = parse_url($requestUri, PHP_URL_PATH);
$path = rtrim($path, '/');

if ($path === '/api/v1/scoring-analytics' && $requestMethod === 'POST') {
    handleScoringAnalytics();
} else {
    http_response_code(404);
    echo json_encode([
        'error' => 'Endpoint not found',
        'code' => 'NOT_FOUND',
    ]);
}

function handleScoringAnalytics(): void
{
    $rawInput = file_get_contents('php://input');

    if (empty($rawInput)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Request body is required',
            'code' => 'VALIDATION_ERROR',
        ]);
        return;
    }

    $payload = json_decode($rawInput, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid JSON: ' . json_last_error_msg(),
            'code' => 'VALIDATION_ERROR',
        ]);
        return;
    }

    try {
        $controller = new \App\Controller\ScoringAnalyticsController();
        $response = $controller->handle($payload);

        if (isset($response['error'])) {
            $httpCode = match ($response['code'] ?? 'UNKNOWN') {
                'VALIDATION_ERROR' => 400,
                'NO_DATA' => 404,
                'DATABASE_ERROR' => 500,
                default => 400,
            };
            http_response_code($httpCode);
        } else {
            http_response_code(200);
        }

        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('Unhandled exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode([
            'error' => 'Internal server error',
            'code' => 'INTERNAL_ERROR',
        ]);
    }
}
