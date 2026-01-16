<?php
/**
 * ClickHouse Database Connection Layer
 * Uses HTTP API via curl for all database operations
 */

// ClickHouse connection settings
define('CLICKHOUSE_HOST', 'clickhouse');
define('CLICKHOUSE_PORT', '8123');
define('CLICKHOUSE_DB', 'scoring');
define('CLICKHOUSE_USER', 'app');
define('CLICKHOUSE_PASSWORD', 'app');


/**
 * Execute a SQL query via ClickHouse HTTP API
 * 
 * @param string $query SQL query to execute
 * @param string $format Output format (default: JSON)
 * @return array|false Returns parsed response or false on error
 */
function executeQuery($query, $format = 'JSON') {
    $url = 'http://' . CLICKHOUSE_HOST . ':' . CLICKHOUSE_PORT;
    
    // Add database parameter
    $url .= '?database=' . urlencode(CLICKHOUSE_DB);
    
    // Initialize curl
    $ch = curl_init($url);
    
    // Set curl options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: text/plain'
    ]);

    // Add HTTP Basic Auth (otherwise ClickHouse sees user "default")
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, CLICKHOUSE_USER . ':' . CLICKHOUSE_PASSWORD);

    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    // Check for errors
    if ($error) {
        error_log("ClickHouse curl error: " . $error);
        return false;
    }
    
    if ($httpCode !== 200) {
        error_log("ClickHouse HTTP error {$httpCode}: " . $response);
        return false;
    }
    
    // Parse JSON response if format is JSON
    if ($format === 'JSON' && $response) {
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            return false;
        }
        return $decoded;
    }
    
    return $response;
}

/**
 * Execute a SELECT query and return results
 * 
 * @param string $query SELECT query
 * @return array|false Array of rows or false on error
 */
function select($query) {
    $result = executeQuery($query . ' FORMAT JSON', 'JSON');
    
    if ($result === false) {
        return false;
    }
    
    // ClickHouse JSON format returns data in 'data' key
    return isset($result['data']) ? $result['data'] : [];
}

/**
 * Execute an INSERT query
 * 
 * @param string $query INSERT query
 * @return bool Success status
 */
function insert($query) {
    $result = executeQuery($query);
    return $result !== false;
}

/**
 * Execute a raw query (for CREATE, DROP, etc.)
 * 
 * @param string $query SQL query
 * @return bool Success status
 */
function execute($query) {
    $result = executeQuery($query);
    return $result !== false;
}
