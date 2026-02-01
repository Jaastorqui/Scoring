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
