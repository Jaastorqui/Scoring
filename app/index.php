<?php
/**
 * Admin Panel - Business Scores Analytics
 * Main page with filters, results table, and analytics
 */

require_once __DIR__ . '/db.php';

// Get filter values from GET parameters
$filterName = isset($_GET['name']) ? trim($_GET['name']) : '';
$filterCategory = isset($_GET['category']) ? trim($_GET['category']) : '';
$filterCompanySize = isset($_GET['company_size']) ? trim($_GET['company_size']) : '';
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$filterScoreRange = isset($_GET['score_range']) ? trim($_GET['score_range']) : '';

// Define score ranges
$scoreRanges = [
    '' => 'All Scores',
    '-100--80' => '-100 to -80',
    '-80--60' => '-80 to -60',
    '-60--40' => '-60 to -40',
    '-40--20' => '-40 to -20',
    '-20-0' => '-20 to 0',
    '0-20' => '0 to 20',
    '20-40' => '20 to 40',
    '40-60' => '40 to 60',
    '60-80' => '60 to 80',
    '80-100' => '80 to 100'
];

// Get sort parameters
$sortColumn = isset($_GET['sort']) ? trim($_GET['sort']) : 'created_at';
$sortDirection = isset($_GET['dir']) ? strtoupper(trim($_GET['dir'])) : 'DESC';

// Validate sort column (whitelist to prevent SQL injection)
$allowedSortColumns = ['business_id', 'business_name', 'company_id', 'category', 'company_size', 'score', 'created_at'];
if (!in_array($sortColumn, $allowedSortColumns)) {
    $sortColumn = 'created_at';
}

// Validate sort direction
if (!in_array($sortDirection, ['ASC', 'DESC'])) {
    $sortDirection = 'DESC';
}

// Build PREWHERE and WHERE clauses for optimal columnar filtering
// PREWHERE is used for high-selectivity filters (category, company_size) to reduce I/O
// WHERE is used for other filters
$prewhereConditions = [];
$whereConditions = [];

// High-selectivity filters go in PREWHERE (better for columnar storage)
if ($filterCategory !== '') {
    $prewhereConditions[] = "category = '" . addslashes($filterCategory) . "'";
}

if ($filterCompanySize !== '') {
    $prewhereConditions[] = "company_size = '" . addslashes($filterCompanySize) . "'";
}

// Other filters go in WHERE
if ($filterName !== '') {
    $whereConditions[] = "business_name LIKE '%" . addslashes($filterName) . "%'";
}

if ($filterDateFrom !== '') {
    $whereConditions[] = "created_at >= '" . addslashes($filterDateFrom) . " 00:00:00'";
}

if ($filterDateTo !== '') {
    $whereConditions[] = "created_at <= '" . addslashes($filterDateTo) . " 23:59:59'";
}

// Score range filter
if ($filterScoreRange !== '') {
    // Parse the range format: "min-max" (e.g., "0-20", "-100--80")
    $parts = explode('-', $filterScoreRange);
    
    // Handle negative numbers in range (e.g., "-100--80" becomes ["", "100", "", "80"])
    if ($parts[0] === '' && count($parts) >= 3) {
        // First number is negative
        $minScore = -intval($parts[1]);
        if ($parts[2] === '' && count($parts) >= 4) {
            // Second number is also negative
            $maxScore = -intval($parts[3]);
        } else {
            // Second number is positive
            $maxScore = intval($parts[2]);
        }
    } else if (count($parts) >= 2) {
        // Standard case or first positive, second negative
        $minScore = intval($parts[0]);
        if ($parts[1] === '' && count($parts) >= 3) {
            // Second number is negative
            $maxScore = -intval($parts[2]);
        } else {
            // Second number is positive
            $maxScore = intval($parts[1]);
        }
    }
    
    if (isset($minScore) && isset($maxScore)) {
        $whereConditions[] = "score >= " . $minScore . " AND score <= " . $maxScore;
    }
}

// Build final clauses
$prewhereClause = count($prewhereConditions) > 0 ? 'PREWHERE ' . implode(' AND ', $prewhereConditions) : '';
$whereClause = count($whereConditions) > 0 ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
$fullWhereClause = trim($prewhereClause . ' ' . $whereClause);

// Query 1: Get filtered results (limit 100) with dynamic sorting
$resultsQuery = "SELECT business_id, business_name, company_id, category, company_size, score, created_at FROM business_scores {$fullWhereClause} ORDER BY {$sortColumn} {$sortDirection} LIMIT 100";
$results = select($resultsQuery);

// Query 2: Total matched rows
$countQuery = "SELECT count() as total FROM business_scores {$fullWhereClause}";
$countResult = select($countQuery);
$totalRows = $countResult[0]['total'] ?? 0;

// Query 3: Average score
$avgQuery = "SELECT avg(score) as avg_score FROM business_scores {$fullWhereClause}";
$avgResult = select($avgQuery);
$avgScore = $avgResult[0]['avg_score'] ?? 0;

// Query 4: Average score by company size
$avgByCompanySizeQuery = "SELECT company_size, avg(score) as avg_score FROM business_scores {$fullWhereClause} GROUP BY company_size ORDER BY company_size";
$avgByCompanySize = select($avgByCompanySizeQuery);

// Query 5: Count by company size
$countByCompanySizeQuery = "SELECT company_size, count() as count FROM business_scores {$fullWhereClause} GROUP BY company_size ORDER BY company_size";
$countByCompanySize = select($countByCompanySizeQuery);

// Query 6: Top 10 lowest scoring businesses
$lowestScoresQuery = "SELECT business_id, business_name, company_id, category, company_size, score FROM business_scores {$fullWhereClause} ORDER BY score ASC LIMIT 10";
$lowestScores = select($lowestScoresQuery);

// Get unique values for dropdowns
$categories = select("SELECT DISTINCT category FROM business_scores ORDER BY category");
$companySizes = ['small', 'medium', 'large', 'enterprise'];

/**
 * Generate sort URL with current filters preserved
 * 
 * @param string $column Column to sort by
 * @return string URL with sort parameters
 */
function getSortUrl($column) {
    global $sortColumn, $sortDirection, $filterName, $filterCategory, $filterCompanySize, $filterDateFrom, $filterDateTo, $filterScoreRange;
    
    // Toggle direction if clicking the same column, otherwise default to ASC
    $newDirection = ($sortColumn === $column && $sortDirection === 'ASC') ? 'DESC' : 'ASC';
    
    // Build query parameters
    $params = [
        'sort' => $column,
        'dir' => $newDirection
    ];
    
    // Preserve filters
    if ($filterName !== '') $params['name'] = $filterName;
    if ($filterCategory !== '') $params['category'] = $filterCategory;
    if ($filterCompanySize !== '') $params['company_size'] = $filterCompanySize;
    if ($filterDateFrom !== '') $params['date_from'] = $filterDateFrom;
    if ($filterDateTo !== '') $params['date_to'] = $filterDateTo;
    if ($filterScoreRange !== '') $params['score_range'] = $filterScoreRange;
    
    return 'index.php?' . http_build_query($params);
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
    <title>Business Scores Admin Panel</title>
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
        
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s;
        }
        
        button:hover {
            background: #0056b3;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
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
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Business Scores Admin Panel</h1>
        
        <!-- Navigation Links -->
        <div class="nav-links">
            <a href="daily_analytics.php">📅 View Daily Analytics (by Category, Company & Day)</a>
        </div>
        
        <!-- Filters Section -->
        <div class="filters">
            <form method="GET" action="">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="name">Business Name</label>
                        <input type="text" id="name" name="name" value="<?= htmlspecialchars($filterName) ?>" placeholder="Search by name...">
                    </div>
                    
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
                        <label for="company_size">Company Size</label>
                        <select id="company_size" name="company_size">
                            <option value="">All Sizes</option>
                            <?php foreach ($companySizes as $size): ?>
                                <option value="<?= htmlspecialchars($size) ?>" <?= $filterCompanySize === $size ? 'selected' : '' ?>>
                                    <?= ucfirst($size) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="score_range">Score Range</label>
                        <select id="score_range" name="score_range">
                            <?php foreach ($scoreRanges as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>" <?= $filterScoreRange === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="filter-row">
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
                    <button type="button" onclick="window.location.href='index.php'" style="background: #6c757d;">Clear Filters</button>
                </div>
            </form>
        </div>
        
        <!-- Analytics Section -->
        <h2>📈 Analytics</h2>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Matched Records</div>
                <div class="stat-value"><?= number_format($totalRows) ?></div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Average Score</div>
                <div class="stat-value"><?= number_format($avgScore, 2) ?></div>
            </div>
        </div>
        
        <!-- Chart Section -->
        <h2>📊 Score by Company Size - Visual Chart</h2>
        <div style="max-width: 800px; margin: 20px auto; padding: 20px; background: white; border-radius: 8px;">
            <canvas id="scoreChart"></canvas>
        </div>
        
        <h2>📊 Score by Company Size - Data Table</h2>
        <table>
            <thead>
                <tr>
                    <th>Company Size</th>
                    <th>Average Score</th>
                    <th>Count</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Merge avg and count data
                $sizeData = [];
                foreach ($avgByCompanySize as $row) {
                    $sizeData[$row['company_size']]['avg'] = $row['avg_score'];
                }
                foreach ($countByCompanySize as $row) {
                    $sizeData[$row['company_size']]['count'] = $row['count'];
                }
                
                foreach ($sizeData as $size => $data): 
                ?>
                    <tr>
                        <td><?= ucfirst(htmlspecialchars($size)) ?></td>
                        <td><?= number_format($data['avg'] ?? 0, 2) ?></td>
                        <td><?= number_format($data['count'] ?? 0) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <h2>⚠️ Top 10 Lowest Scoring Businesses</h2>
        <table>
            <thead>
                <tr>
                    <th>Business ID</th>
                    <th>Business Name</th>
                    <th>Company ID</th>
                    <th>Category</th>
                    <th>Company Size</th>
                    <th>Score</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lowestScores as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['business_id']) ?></td>
                        <td><?= htmlspecialchars($row['business_name']) ?></td>
                        <td><?= htmlspecialchars($row['company_id']) ?></td>
                        <td><?= htmlspecialchars($row['category']) ?></td>
                        <td><?= ucfirst(htmlspecialchars($row['company_size'])) ?></td>
                        <td>
                            <?php
                            $score = $row['score'];
                            $class = $score >= 70 ? 'score-high' : ($score >= 40 ? 'score-medium' : 'score-low');
                            ?>
                            <span class="score-badge <?= $class ?>"><?= number_format($score) ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Results Section -->
        <h2>📋 Search Results</h2>
        <p class="info-text">Showing up to 100 results (Total matched: <?= number_format($totalRows) ?>)</p>
        
        <table>
            <thead>
                <tr>
                    <th><a href="<?= getSortUrl('business_id') ?>" class="sort-link">Business ID<?= getSortIndicator('business_id') ?></a></th>
                    <th><a href="<?= getSortUrl('business_name') ?>" class="sort-link">Business Name<?= getSortIndicator('business_name') ?></a></th>
                    <th><a href="<?= getSortUrl('company_id') ?>" class="sort-link">Company ID<?= getSortIndicator('company_id') ?></a></th>
                    <th><a href="<?= getSortUrl('category') ?>" class="sort-link">Category<?= getSortIndicator('category') ?></a></th>
                    <th><a href="<?= getSortUrl('company_size') ?>" class="sort-link">Company Size<?= getSortIndicator('company_size') ?></a></th>
                    <th><a href="<?= getSortUrl('score') ?>" class="sort-link">Score<?= getSortIndicator('score') ?></a></th>
                    <th><a href="<?= getSortUrl('created_at') ?>" class="sort-link">Created At<?= getSortIndicator('created_at') ?></a></th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($results) > 0): ?>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['business_id']) ?></td>
                            <td><?= htmlspecialchars($row['business_name']) ?></td>
                            <td><?= htmlspecialchars($row['company_id']) ?></td>
                            <td><?= htmlspecialchars($row['category']) ?></td>
                            <td><?= ucfirst(htmlspecialchars($row['company_size'])) ?></td>
                            <td>
                                <?php
                                $score = $row['score'];
                                $class = $score >= 70 ? 'score-high' : ($score >= 40 ? 'score-medium' : 'score-low');
                                ?>
                                <span class="score-badge <?= $class ?>"><?= number_format($score) ?></span>
                            </td>
                            <td><?= htmlspecialchars($row['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #999;">No results found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <script>
        // Prepare data from PHP for Chart.js
        const chartData = {
            labels: [],
            avgScores: [],
            counts: []
        };
        
        <?php foreach ($sizeData as $size => $data): ?>
            chartData.labels.push('<?= ucfirst(htmlspecialchars($size)) ?>');
            chartData.avgScores.push(<?= $data['avg'] ?? 0 ?>);
            chartData.counts.push(<?= $data['count'] ?? 0 ?>);
        <?php endforeach; ?>
        
        // Create the chart
        const ctx = document.getElementById('scoreChart').getContext('2d');
        const scoreChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Average Score',
                        data: chartData.avgScores,
                        backgroundColor: 'rgba(54, 162, 235, 0.6)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 2,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Count',
                        data: chartData.counts,
                        backgroundColor: 'rgba(255, 99, 132, 0.6)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 2,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Business Scores Analytics by Company Size',
                        font: {
                            size: 16,
                            weight: 'bold'
                        }
                    },
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    if (context.dataset.label === 'Average Score') {
                                        label += context.parsed.y.toFixed(2);
                                    } else {
                                        label += context.parsed.y.toLocaleString();
                                    }
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Average Score'
                        },
                        grid: {
                            drawOnChartArea: true
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Count'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
