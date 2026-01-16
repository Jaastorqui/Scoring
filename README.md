# ClickHouse Admin Panel PoC

A Proof-of-Concept web application for storing and analyzing business scores using ClickHouse, PHP, and Nginx running in Docker.

## Features

- **ClickHouse Database**: High-performance columnar database for analytics
- **PHP Backend**: Simple PHP application using curl to interact with ClickHouse HTTP API
- **Admin Panel**: Web interface with filters and real-time analytics
- **Docker Setup**: Complete containerized environment with docker-compose

## Architecture

### Services

- **clickhouse**: ClickHouse server (ports 8123 HTTP, 9000 native)
- **web**: PHP 8.1-FPM for processing PHP scripts
- **nginx**: Nginx web server serving the application on port 80

### Database Schema

**Database**: `scoring`

**Table**: `business_scores`

| Column | Type | Description |
|--------|------|-------------|
| business_id | UInt64 | Unique business identifier |
| business_name | String | Business name |
| company_id | UInt64 | Company identifier (businesses registered in this company) |
| category | LowCardinality(String) | Business category (electricians, plumbers, painters) - optimized for low cardinality |
| company_size | Enum8 | Company size (small, medium, large, enterprise) |
| score | Int32 | Business score (integer, can be negative) |
| created_at | DateTime | Record creation timestamp |

**Engine**: MergeTree  
**Partitioning**: Monthly by `created_at` (`PARTITION BY toYYYYMM(created_at)`)  
**Ordering**: `(company_id, category, company_size, created_at, business_id)` - optimized for query patterns

## Quick Start

### Prerequisites

- Docker
- Docker Compose

### Installation & Setup

1. **Start the services**:
   ```bash
   docker-compose up -d
   ```

   This will:
   - Start ClickHouse server
   - Initialize the database and table from `sql/init.sql`
   - Start PHP-FPM and Nginx

2. **Wait for services to be ready** (about 10-15 seconds):
   ```bash
   docker-compose ps
   ```

3. **Generate sample data** (10,000 records):
   ```bash
   docker-compose exec web php /app/generate.php
   ```

4. **Open the admin panel**:
   ```
   http://localhost
   ```

## Usage

### Admin Panel Features

The admin panel (`http://localhost`) provides:

#### Filters
- **Business Name**: Search by name (LIKE query)
- **Category**: Filter by business category
- **Company Size**: Filter by company size
- **Date Range**: Filter by creation date (from/to)

#### Analytics
- **Total Matched Records**: Count of filtered results
- **Average Score**: Average score across filtered results
- **Score by Company Size**: Average score and count grouped by company size
- **Top 10 Lowest Scoring Businesses**: Businesses with lowest scores

#### Results Table
- Shows up to 100 filtered records
- Displays all business details
- Color-coded score badges (green/yellow/red)

### Regenerating Data

To clear and regenerate data:

```bash
# Stop services
docker-compose down -v

# Start services (will reinitialize database)
docker-compose up -d

# Wait for services to be ready
sleep 15

# Generate new data
docker-compose exec web php /app/generate.php
```

### Accessing ClickHouse Directly

You can query ClickHouse directly using the client:

```bash
docker-compose exec clickhouse clickhouse-client

# Example queries:
SELECT count() FROM scoring.business_scores;
SELECT category, avg(score) FROM scoring.business_scores GROUP BY category;
```

Or via HTTP API:

```bash
curl 'http://localhost:8123/?query=SELECT+count()+FROM+scoring.business_scores'
```

## Project Structure

```
/
├── docker/
│   └── nginx/
│       └── default.conf          # Nginx configuration
├── sql/
│   └── init.sql                  # Database initialization script
├── app/
│   ├── db.php                    # ClickHouse connection layer (curl-based)
│   ├── generate.php              # Sample data generator
│   ├── index.php                 # Admin panel UI
│   └── analytics.php             # Analytics functions module
├── docker-compose.yml            # Docker services configuration
└── README.md                     # This file
```

## Technical Details

### ClickHouse Connection

The application uses ClickHouse HTTP API (port 8123) via PHP curl:

- **Host**: `clickhouse` (Docker service name)
- **Port**: `8123`
- **Database**: `scoring`
- **Format**: JSON for SELECT queries

All database operations are in `app/db.php`:
- `executeQuery()`: Execute raw SQL via HTTP API
- `select()`: Execute SELECT and return JSON results
- `insert()`: Execute INSERT statements
- `execute()`: Execute DDL statements

### Data Generation

The `generate.php` script:
- Inserts 10,000 records in batches of 1,000
- Generates random business data
- Creates dates within the last 60 days
- Uses batch INSERT for performance

### Analytics Queries

All analytics use the same filter conditions:
- Filtered results (LIMIT 100)
- Total count
- Average score
- Grouped by company size (avg + count)
- Top 10 lowest scores

## Development

### Viewing Logs

```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f clickhouse
docker-compose logs -f nginx
docker-compose logs -f web
```

### Stopping Services

```bash
docker-compose down
```

### Stopping and Removing Data

```bash
docker-compose down -v
```
