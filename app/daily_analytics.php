<?php
/**
 * Daily Analytics View - Average Score per Category, Company, and Day
 * Shows aggregated data grouped by category, company_id, and date
 */

require_once __DIR__ . '/db.php';

// Get filter values from GET parameters
$filterCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
$filterCompanyId = isset($_GET['company_id']) ? trim($_GET['company_id']) : '';
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

// Get sort parameters
$sortColumn = isset($_GET['sort']) ? trim($_GET['sort']) : 'date';
$sortDirection = isset($_GET['dir']) ? strtoupper(trim($_GET['dir'])) : 'DESC';

// Validate sort column (whitelist to prevent SQL injection)
$allowedSortColumns = ['date', 'category', 'avg_score', 'count'];
if (!in_array($sortColumn, $allowedSortColumns)) {
    $sortColumn = 'date';
}

// Validate sort direction
if (!in_array($sortDirection, ['ASC', 'DESC'])) {
    $sortDirection = 'DESC';
}

// Build WHERE clause for filters
$whereConditions = [];

if ($filterCategory !== '') {
    $whereConditions[] = "category = '" . addslashes($filterCategory) . "'";
}

if ($filterCompanyId !== '') {
    $whereConditions[] = "company_id = " . intval($filterCompanyId);
}

if ($filterDateFrom !== '') {
    $whereConditions[] = "created_at >= '" . addslashes($filterDateFrom) . " 00:00:00'";
}

if ($filterDateTo !== '') {
    $whereConditions[] = "created_at <= '" . addslashes($filterDateTo) . " 23:59:59'";
}

$whereClause = count($whereConditions) > 0 ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Main query: Get average score grouped by category, company_id, and day
$analyticsQuery = "
    SELECT 
        toDate(created_at) as date,
        category,
        avg(score) as avg_score,
        count() as count
    FROM business_scores
    {$whereClause}
    GROUP BY date, category
    ORDER BY {$sortColumn} {$sortDirection}
    LIMIT 1000
";
$analyticsData = select($analyticsQuery);

// Get unique categories for dropdown
$categories = select("SELECT DISTINCT category FROM business_scores ORDER BY category");

// Get unique company IDs for dropdown
$companies = select("SELECT DISTINCT company_id FROM business_scores ORDER BY company_id");

// Calculate summary statistics
$totalRecords = count($analyticsData);
$overallAvgQuery = "SELECT avg(score) as overall_avg FROM business_scores {$whereClause}";
$overallAvgResult = select($overallAvgQuery);
$overallAvg = $overallAvgResult[0]['overall_avg'] ?? 0;

/**
 * Generate sort URL with current filters preserved
 * 
 * @param string $column Column to sort by
 * @return string URL with sort parameters
 */
function getSortUrl($column) {
    global $sortColumn, $sortDirection, $filterCategory, $filterCompanyId, $filterDateFrom, $filterDateTo;
    
    // Toggle direction if clicking the same column, otherwise default to ASC
    $newDirection = ($sortColumn === $column && $sortDirection === 'ASC') ? 'DESC' : 'ASC';
    
    // Build query parameters
    $params = [
        'sort' => $column,
        'dir' => $newDirection
    ];
    
    // Preserve filters
    if ($filterCategory !== '') $params['category'] = $filterCategory;
    if ($filterCompanyId !== '') $params['company_id'] = $filterCompanyId;
    if ($filterDateFrom !== '') $params['date_from'] = $filterDateFrom;
    if ($filterDateTo !== '') $params['date_to'] = $filterDateTo;
    
    return 'daily_analytics.php?' . http_build_query($params);
}

/**
 * Get sort indicator for column header
 * 
 * @param string $column Column name
 * @return string Sort indicator (↑ or ↓) or empty string
 */
function getSortIndicator($column) {
    global $sortColumn, $sortDirection;
    
    if ($sortColumn === $column) {
        return $sortDirection === 'ASC' ? ' ↑' : ' ↓';
    }
    
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Analytics - Average Score by Category, Company & Day</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #333;
            margin-bottom: 30px;
            font-size: 28px;
        }
        
        h2 {
            color: #555;
            margin: 30px 0 15px 0;
            font-size: 20px;
            border-bottom: 2px solid #007bff;
            padding-bottom: 8px;
        }
        
        .nav-links {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        
        .nav-links a {
            color: #007bff;
            text-decoration: none;
            margin-right: 20px;
            font-weight: 600;
        }
        
        .nav-links a:hover {
            text-decoration: underline;
        }
        
        .filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 30px;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #555;
            font-size: 14px;
        }
        
        input[type="text"],
        input[type="date"],
        select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        button {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s;
        }
        
        button:hover {
            background: #0056b3;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
            font-size: 14px;
        }
        
        .sort-link {
            color: #007bff;
            text-decoration: none;
            display: inline-block;
            transition: color 0.2s;
            cursor: pointer;
        }
        
        .sort-link:hover {
            color: #0056b3;
            text-decoration: underline;
        }
        
        td {
            font-size: 14px;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            border-left: 4px solid #007bff;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #333;
        }
        
        .info-text {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .score-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 13px;
        }
        
        .score-high {
            background: #d4edda;
            color: #155724;
        }
        
        .score-medium {
            background: #fff3cd;
            color: #856404;
        }
        
        .score-low {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📅 Daily Analytics - Average Score by Category, Company & Day</h1>
        
        <!-- Navigation Links -->
        <div class="nav-links">
            <a href="index.php">← Back to Main Dashboard</a>
        </div>
        
        <!-- Filters Section -->
        <div class="filters">
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['category']) ?>" <?= $filterCategory === $cat['category'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cat['category']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="company_id">Company ID</label>
                        <select id="company_id" name="company_id">
                            <option value="">All Companies</option>
                            <?php foreach ($companies as $comp): ?>
                                <option value="<?= htmlspecialchars($comp['company_id']) ?>" <?= $filterCompanyId == $comp['company_id'] ? 'selected' : '' ?>>
                                    Company <?= htmlspecialchars($comp['company_id']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_from">Date From</label>
                        <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="date_to">Date To</label>
                        <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>">
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="submit">Apply Filters</button>
                    <button type="button" onclick="window.location.href='daily_analytics.php'" style="background: #6c757d;">Clear Filters</button>
                </div>
            </form>
        </div>
        
        <!-- Summary Statistics -->
        <h2>📊 Summary</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Records (Grouped)</div>
                <div class="stat-value"><?= number_format($totalRecords) ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Overall Average Score</div>
                <div class="stat-value"><?= number_format($overallAvg, 2) ?></div>
            </div>
        </div>
        
        <!-- Results Table -->
        <h2>📋 Daily Average Scores</h2>
        <p class="info-text">Showing average scores grouped by date, category, and company (up to 1,000 records)</p>
        
        <table>
            <thead>
                <tr>
                    <th><a href="<?= getSortUrl('date') ?>" class="sort-link">Date<?= getSortIndicator('date') ?></a></th>
                    <th><a href="<?= getSortUrl('category') ?>" class="sort-link">Category<?= getSortIndicator('category') ?></a></th>
                    <th><a href="<?= getSortUrl('avg_score') ?>" class="sort-link">Average Score<?= getSortIndicator('avg_score') ?></a></th>
                    <th><a href="<?= getSortUrl('count') ?>" class="sort-link">Record Count<?= getSortIndicator('count') ?></a></th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($analyticsData) > 0): ?>
                    <?php foreach ($analyticsData as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['date']) ?></td>
                            <td><?= htmlspecialchars($row['category']) ?></td>
                            <td>
                                <?php
                                $score = $row['avg_score'];
                                $class = $score >= 70 ? 'score-high' : ($score >= 40 ? 'score-medium' : 'score-low');
                                ?>
                                <span class="score-badge <?= $class ?>"><?= number_format($score, 2) ?></span>
                            </td>
                            <td><?= number_format($row['count']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #999;">
                            No data found. Try adjusting your filters.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
