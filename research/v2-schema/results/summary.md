# Measured results (see README.md for analysis)

Corpus: 1,192,454 events (692,824 pageview / 470,718 click / 28,903 transaction
carrying 115,596 line items / 9 action), 325,833 session rows, 11 dimension
tables. MySQL 8.4, 2 vCPU / 2 GB burstable, gp3 3,000 IOPS, 512 MB buffer pool.
Validation: pageviews, clicks, revenue sum and line-item count identical across
all three representations.

## Disk (MB, freshly built)
| repr | data | index | total |
|---|---|---|---|
| star  | 937   | 411 | 1,348 |
| event | 976.7 | 341.6 | 1,318 |
| wide  | 1,005.0 | 326.5 | 1,332 |

## Queries (ms, warm = median of 4; cold = first run after build)
| query | star cold/warm | event cold/warm | wide cold/warm |
|---|---|---|---|
| pageviews_per_day        | 134.3 / 39.7 | 54.1 / 18.4 | 62.7 / 18.1 |
| top_pages                | 176.4 / 73.3 | 1905.4 / 64.1 | 2188.5 / 65.5 |
| top_referers             | 178.5 / 80.4 | 66.6 / 66.0 | 66.5 / 66.6 |
| uniques_per_month        | 271.3 / 259.5 | 454.2 / 161.9 | 572.2 / 168.5 |
| sessions_bounce          | 733.4 / 18.7 | 387.0 / 118.2 | 429.1 / 119.8 |
| avg_session_secs         | 18.9 / 19.0 | 120.7 / 120.1 | 120.4 / 119.2 |
| browser_breakdown        | 417.7 / 418.9 | 141.9 / 144.3 | 142.8 / 143.2 |
| revenue_by_campaign      | 37.3 / 36.0 | 36202.5 / 35277.5 (unindexed shape) | 34065.9 / 34013.7 |
| revenue_by_campaign (site-filtered, index applies) | – | 1743 cold / ~183 warm | – |
| clicks_for_page          | 287.6 / 39.0 | 181.9 / 179.6 | 174.0 / 171.1 |
| pageviews_per_month_year | 67.4 / 65.1 | 454.3 / 140.0 | 452.0 / 141.5 |

## Insert (3,000 synthetic pageviews, single-row statements, autocommit)
| model | statements/event | events/s |
|---|---|---|
| star (insert + session upsert + 5 dim checks) | 7 | 79 |
| event (one insert) | 1 | 202 |

## Enrichment (write one attribute of the most-referenced referer)
| model | rows written | time |
|---|---|---|
| star (dimension row) | 1 | 0.116 s |
| event (every event row carrying it) | 565,465 | 68.3 s |
