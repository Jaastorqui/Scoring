<?php
/**
 * Sample Data Generator for ClickHouse
 * Generates and inserts 10,000+ random business score records
 */

require_once __DIR__ . '/db.php';

// Configuration
$totalRows = 10000000;
$batchSize = 100000;

// Data options
$categories = [
    'electricians',
    'plumbers',
    'painters',
    'carpenters',
    'hvac_technicians',
    'landscapers',
    'roofers',
    'masons',
    'plumbing_contractors',
    'electrical_contractors',
    'general_contractors',
    'welders',
    'auto_mechanics',
    'locksmiths'
];

// Score ranges for each category (min, max)
$categoryScores = [
    'electricians' => [70, 100],           // High performers
    'plumbers' => [65, 95],
    'painters' => [50, 85],
    'carpenters' => [60, 90],
    'hvac_technicians' => [75, 100],       // Very high
    'landscapers' => [40, 75],             // Lower average
    'roofers' => [55, 85],
    'masons' => [60, 90],
    'plumbing_contractors' => [70, 100],   // High performers
    'electrical_contractors' => [72, 100],
    'general_contractors' => [65, 95],
    'welders' => [68, 98],
    'auto_mechanics' => [55, 85],
    'locksmiths' => [50, 80]
];

$companySizes = ['small', 'medium', 'large', 'enterprise'];

// Score modifiers for company size (applied on top of category score)
$sizeModifiers = [
    'small' => -15,        // Small companies score 15 points lower
    'medium' => -5,        // Medium slightly lower
    'large' => 5,          // Large slightly higher
    'enterprise' => 15     // Enterprise scores 15 points higher
];

$companyIds = range(1, 50);

echo "Starting data generation...\n";
echo "Total rows to insert: {$totalRows}\n";
echo "Batch size: {$batchSize}\n";
echo "Scores correlate with category + company size\n\n";

$insertedCount = 0;
$batches = ceil($totalRows / $batchSize);

for ($batch = 0; $batch < $batches; $batch++) {
    $rowsInBatch = min($batchSize, $totalRows - $insertedCount);
    
    // Build batch INSERT query
    $values = [];
    
    for ($i = 0; $i < $rowsInBatch; $i++) {
        $businessId = $insertedCount + $i + 1;
        $businessName = "Business " . $businessId;
        
        // Use random_int for true randomization (more secure than mt_rand)
        $companyId = $companyIds[random_int(0, count($companyIds) - 1)];
        $category = $categories[random_int(0, count($categories) - 1)];
        $companySize = $companySizes[random_int(0, count($companySizes) - 1)];
        
        // 80% positive scores (0-100), 20% negative scores (-100 to 0)
        $scoreRand = random_int(1, 100);
        if ($scoreRand <= 80) {
            $score = random_int(0, 100);
        } else {
            $score = random_int(-100, 0);
        }
        
        // Random date within last 60 days
        $daysAgo = random_int(0, 60);
        $timestamp = time() - ($daysAgo * 86400) - random_int(0, 86400);
        $createdAt = date('Y-m-d H:i:s', $timestamp);
        
        // Escape strings for SQL
        $businessNameEscaped = addslashes($businessName);
        $categoryEscaped = addslashes($category);
        
        // Generate score based on category + company size
        [$minScore, $maxScore] = $categoryScores[$category];
        $baseScore = random_int($minScore, $maxScore);
        
        // Apply company size modifier
        $score = $baseScore + $sizeModifiers[$companySize];
        
        // Clamp to valid range (-100 to 100)
        $score = max(-100, min(100, $score));
        
        // Add occasional negative event (5% chance of significant score penalty)
        if (random_int(1, 100) <= 5) {
            $score = $score - random_int(20, 50);
            $score = max(-100, $score);
        }

        $values[] = "({$businessId}, '{$businessNameEscaped}', {$companyId}, '{$categoryEscaped}', '{$companySize}', {$score}, '{$createdAt}')";
    }
    
    // Execute batch insert
    $query = "INSERT INTO business_scores (business_id, business_name, company_id, category, company_size, score, created_at) VALUES " . implode(', ', $values);
    
    if (insert($query)) {
        $insertedCount += $rowsInBatch;
        echo "Batch " . ($batch + 1) . "/{$batches} completed. Total inserted: {$insertedCount}\n";
    } else {
        echo "Error inserting batch " . ($batch + 1) . "\n";
        exit(1);
    }
}

echo "\n✓ Data generation completed!\n";
echo "Total rows inserted: {$insertedCount}\n";
