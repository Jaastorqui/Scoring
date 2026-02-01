
## **🚨 Churn Risk (Top Priority)**

-- 1. Top 20 WORST companies (highest churn risk)
SELECT * FROM company_churn_risk 
WHERE risk_score < 0
ORDER BY risk_score ASC, risk_days DESC 
LIMIT 20;

-- 2. All companies in risk (negative score)
SELECT company_id, risk_score, risk_days, risk_events
FROM company_churn_risk 
WHERE risk_score < -50  -- Critical threshold
ORDER BY risk_score ASC;

-- 3. Risk trends (companies getting worse)
SELECT company_id, 
       risk_score,
       lag(risk_score) OVER (PARTITION BY company_id ORDER BY last_assessed) as prev_score,
       risk_score - lag(risk_score) OVER (PARTITION BY company_id ORDER BY last_assessed) as delta
FROM company_churn_risk 
WHERE risk_score < 0 
ORDER BY delta ASC 
LIMIT 50;


## **📊 Company Performance**

-- 4. Company ranking by total score (last 30 days)
SELECT company_id, total_points, events_count, avg(total_points) OVER () as avg_score
FROM company_scores_daily 
WHERE day >= today() - 30
GROUP BY company_id, events_count
ORDER BY total_points DESC 
LIMIT 100;

-- 5. Score distribution (healthy vs risky)
SELECT 
    count() as companies,
    countIf(total_points < 0) as risky,
    round(countIf(total_points < 0)*100/count(), 2) as risk_pct,
    avg(total_points) as avg_score,
    min(total_points) as worst,
    max(total_points) as best
FROM company_scores_monthly;


## **🔍 Churn Patterns**
-- 6. Top churn reasons (negative events)
SELECT 
    event_type,
    count() as events,
    avg(score_points) as avg_score,
    sum(score_points) as total_impact
FROM scoring_events 
WHERE score_points < 0 AND event_time > now() - INTERVAL 30 DAY
GROUP BY event_type
ORDER BY total_impact ASC 
LIMIT 10;

-- 7. Churn velocity (score drop over time)
SELECT 
    toStartOfWeek(event_date) as week,
    sum(score_points) as weekly_score,
    count() as events
FROM scoring_events 
WHERE event_date >= today() - 90
GROUP BY week
ORDER BY week;


## **⚡ Instant Dashboards**
-- 8. Risk heatmap (score vs days)
SELECT 
    risk_score,
    risk_days,
    count() as companies
FROM company_churn_risk 
WHERE risk_score < 0
GROUP BY risk_score, risk_days
ORDER BY risk_score ASC, risk_days DESC;

-- 9. Active vs churned (event activity)
SELECT 
    company_id,
    events_count,
    total_points,
    CASE 
        WHEN risk_score < -100 THEN 'CRITICAL'
        WHEN risk_score < 0 THEN 'RISK'
        ELSE 'HEALTHY'
    END as status
FROM company_churn_risk 
LEFT JOIN (
    SELECT company_id, sum(events_count) as events_count, sum(total_points) as total_points
    FROM company_scores_daily WHERE day >= today() - 7
    GROUP BY company_id
) recent ON company_churn_risk.company_id = recent.company_id
ORDER BY risk_score ASC 
LIMIT 50;


## **📈 Trends & Alerts**
-- 10. Weekly churn trend
SELECT 
    toStartOfWeek(last_assessed) as week,
    countIf(risk_score < 0) as risky_companies,
    avg(risk_score) as avg_risk_score
FROM company_churn_risk 
GROUP BY week
ORDER BY week DESC 
LIMIT 12;

-- 11. Score decay impact
SELECT 
    company_id,
    sum(decay_points) as total_decay,
    sum(total_points) - sum(decay_points) as net_score
FROM company_scores_daily 
WHERE day >= today() - 30
GROUP BY company_id
HAVING total_decay > 100  -- High decay companies
ORDER BY total_decay DESC 
LIMIT 20;


## **🎯 Actionable Insights**
-- 12. Recovery candidates (improving from risk)
SELECT company_id, risk_score, risk_days
FROM company_churn_risk 
WHERE risk_score > -50 AND risk_days > 5  -- Improving but still risky
ORDER BY risk_score DESC 
LIMIT 30;

-- 13. Silent killers (low activity + negative)
SELECT company_id, events_count, risk_score
FROM company_churn_risk 
LEFT JOIN (
    SELECT company_id, sum(events_count) as events_count 
    FROM company_scores_daily WHERE day >= today() - 7 GROUP BY company_id
) recent ON company_churn_risk.company_id = recent.company_id
WHERE events_count < 10 AND risk_score < 0
ORDER BY risk_score ASC;

-- 14. Top performers benchmark
SELECT company_id, total_points, events_count
FROM company_scores_daily 
WHERE day >= today() - 30
QUALIFY total_points > percentile_cont(0.9)(total_points) OVER ()
ORDER BY total_points DESC 
LIMIT 20;

-- 15. Event ROI (points per event type)
SELECT 
    event_type,
    count() as events,
    avg(score_points) as points_per_event,
    sum(score_points) as total_value
FROM scoring_events 
WHERE event_time > now() - INTERVAL 30 DAY
GROUP BY event_type
ORDER BY total_value DESC;

