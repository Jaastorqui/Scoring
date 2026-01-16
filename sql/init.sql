-- Create database
CREATE DATABASE IF NOT EXISTS scoring;

-- Use the database
USE scoring;

-- Create business_scores table with columnar optimizations
CREATE TABLE IF NOT EXISTS business_scores (
    business_id UInt64,
    business_name String,
    company_id UInt64,
    category LowCardinality(String),  -- Low cardinality optimization for 14 values
    company_size Enum8('small' = 1, 'medium' = 2, 'large' = 3, 'enterprise' = 4),
    score Int32,
    created_at DateTime
) ENGINE = MergeTree()
PARTITION BY toYYYYMM(created_at)  -- Monthly partitions for better date filtering
ORDER BY (created_at, business_id, company_id, category, company_size)
SETTINGS index_granularity = 8192;
