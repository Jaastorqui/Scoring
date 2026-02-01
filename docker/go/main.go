package main

import (
	"context"
	"database/sql"
	"fmt"
	"log"
	"math/rand"
	"time"

	"github.com/ClickHouse/clickhouse-go/v2"
)

const (
	BATCH_SIZE      = 100000
	TOTAL_ROWS      = 10000000
	CHURN_COMPANIES = 4000 // 40% churn (1-4000)
)

var eventTypes = []string{
	"LOGIN", "BID_SUBMITTED", "JOB_ACCEPTED", "SELF_PURCHASE_CANCEL",
	"INVOICE_DOWNLOAD", "TENDER_DELETE", "INACTIVE_30D",
}

func calculateScore(companyID int, eventType string) int {
	if companyID <= CHURN_COMPANIES { // CHURN companies: NEGATIVE only
		switch eventType {
		case "SELF_PURCHASE_CANCEL", "TENDER_DELETE", "INACTIVE_30D":
			return -rand.Intn(16) - 10 // -10 to -25 (heavy churn)
		case "LOGIN", "BID_SUBMITTED":
			return -rand.Intn(11) - 2 // -2 to -12 (low activity)
		default:
			return -rand.Intn(6) - 1 // -1 to -6
		}
	} else { // HEALTHY companies: POSITIVE only
		switch eventType {
		case "JOB_ACCEPTED":
			return rand.Intn(16) + 15 // +15 to +30 (revenue!)
		case "INVOICE_DOWNLOAD":
			return rand.Intn(11) + 10 // +10 to +20
		case "LOGIN", "BID_SUBMITTED":
			return rand.Intn(11) + 3 // +3 to +13
		default:
			return rand.Intn(6) + 1 // +1 to +6
		}
	}
}

func main() {
	// Connect to ClickHouse
	conn, err := sql.Open("clickhouse", "clickhouse://default:@localhost:8123?dial_timeout=10s")
	if err != nil {
		log.Fatal("Connection failed: ", err)
	}
	defer conn.Close()

	rand.Seed(time.Now().UnixNano())

	fmt.Printf("🚀 Generating %d rows (40%% churn) in batches of %d...\n", TOTAL_ROWS, BATCH_SIZE)

	totalBatches := TOTAL_ROWS / BATCH_SIZE
	start := time.Now()

	for batch := 0; batch < totalBatches; batch++ {
		tx, err := conn.BeginTx(context.Background(), nil)
		if err != nil {
			log.Fatal("Transaction failed: ", err)
		}

		stmt, err := tx.Prepare(`
            INSERT INTO churn_events (
                company_id, event_time, event_type, score_points, total_score
            ) VALUES (?, ?, ?, ?, ?)
        `)
		if err != nil {
			tx.Rollback()
			log.Fatal("Prepare failed: ", err)
		}

		batchStart := time.Now()
		rowsInserted := 0

		for i := 0; i < BATCH_SIZE && (batch*BATCH_SIZE+i) < TOTAL_ROWS; i++ {
			companyID := rand.Intn(10000) + 1 // 1-10000 companies
			eventType := eventTypes[rand.Intn(len(eventTypes))]
			scorePoints := calculateScore(companyID, eventType)

			// Simulate total_score accumulation
			totalScore := scorePoints * (rand.Intn(50) + 1) // Simple accumulation

			_, err := stmt.Exec(companyID, time.Now().Add(-time.Duration(rand.Intn(365)*24)*time.Hour),
				eventType, scorePoints, totalScore)
			if err != nil {
				log.Printf("Insert failed: %v", err)
				continue
			}
			rowsInserted++
		}

		if err := stmt.Close(); err != nil {
			tx.Rollback()
			log.Fatal("Stmt close failed: ", err)
		}

		if err := tx.Commit(); err != nil {
			log.Printf("Commit failed: %v", err)
		}

		elapsed := time.Since(batchStart)
		fmt.Printf("Batch %d/%d: %d rows in %.2fs (%.0f rows/sec)\n",
			batch+1, totalBatches, rowsInserted, elapsed.Seconds(),
			float64(rowsInserted)/elapsed.Seconds())
	}

	totalTime := time.Since(start)
	fmt.Printf("\n✅ COMPLETE! %d rows in %.2f minutes (%.0f rows/sec)\n",
		TOTAL_ROWS, totalTime.Minutes(), float64(TOTAL_ROWS)/totalTime.Seconds())
}
