CREATE TABLE scoring_events
(
event_time       DateTime,
event_date       Date DEFAULT toDate(event_time),
company_id       UInt64,
user_id          UInt64,
event_type       LowCardinality(String),
source           LowCardinality(String),
score_context    LowCardinality(String),
score_rule_code  LowCardinality(String),
score_points     Int32,
score_decay_pts  Int32,
score_expire_at  DateTime
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(event_date)
ORDER BY (event_date, company_id, score_context, event_type);


CREATE TABLE company_scores_daily
(
company_id     UInt64,
user_id        UInt64,
score_context  LowCardinality(String),
day            Date,
total_points   Int64,
decay_points   Int64,
events_count   UInt64
)
ENGINE = SummingMergeTree
PARTITION BY toYYYYMM(day)
ORDER BY (day, company_id, user_id, score_context);

CREATE MATERIALIZED VIEW mv_company_scores_daily
TO company_scores_daily
AS
SELECT
company_id,
user_id,
score_context,
event_date AS day,
sum(score_points + score_decay_points) AS total_points,
sum(score_decay_points) AS decay_points,
count() AS events_count
FROM scoring_events
WHERE score_expire_at > now()
GROUP BY company_id, user_id, score_context, day;

CREATE TABLE company_scores_monthly
(
company_id     UInt64,
user_id        UInt64,
score_context  LowCardinality(String),
month          Date,
total_points   Int64,
decay_points   Int64,
events_count   UInt64
)
ENGINE = SummingMergeTree
PARTITION BY toYYYYMM(month)
ORDER BY (month, company_id, user_id, score_context);

CREATE MATERIALIZED VIEW mv_company_scores_monthly
TO company_scores_monthly
AS
SELECT
company_id,
user_id,
score_context,
toStartOfMonth(event_date) AS month,
sum(score_points + score_decay_points) AS total_points,
sum(score_decay_points) AS decay_points,
count() AS events_count
FROM scoring_events
WHERE score_expire_at > now()
GROUP BY company_id, user_id, score_context, month;


CREATE TABLE company_scores_latest
(
company_id     UInt64,
score_context  LowCardinality(String),
last_updated   DateTime,
total_points   Int64,
active_rules   UInt64
)
ENGINE = ReplacingMergeTree(last_updated)
ORDER BY (company_id, score_context);

CREATE MATERIALIZED VIEW mv_company_scores_latest
TO company_scores_latest
AS
SELECT
company_id,
score_context,
max(event_time) AS last_updated,
sum(score_points + score_decay_points) AS total_points,
count() AS active_rules
FROM scoring_events
WHERE score_expire_at > now()
GROUP BY company_id, score_context;

CREATE TABLE company_churn_risk
(
company_id     UInt64,
risk_score     Int64,        -- Negative = higher risk
risk_days      UInt16,       -- Days with negative trend
risk_events    UInt64,       -- Negative scoring events
last_assessed  Date
)
ENGINE = ReplacingMergeTree(last_assessed)
ORDER BY (company_id);

CREATE MATERIALIZED VIEW mv_company_churn_risk
TO company_churn_risk
AS
SELECT
company_id,
sum(total_points) AS risk_score,                    -- Negative total = high risk
countIf(total_points < 0) AS risk_days,             -- Days with losses
sum(events_count) FILTER (WHERE total_points < 0) AS risk_events,
max(day) AS last_assessed
FROM company_scores_daily
WHERE day >= today() - 30                           -- Last 30 days
GROUP BY company_id;





