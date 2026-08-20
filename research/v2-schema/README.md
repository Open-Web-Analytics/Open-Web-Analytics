# v2.0 schema research: one fact table, measured

Should OWA 2.0 replace the 1.x star schema with a single fact table holding
every event type, the way Google Analytics 4 stores its data? This directory
holds the experiment that grounds that decision: three representations of the
same 1.19 million real events, built and measured on MySQL, with the code to
reproduce every number.

**Short answer:** on MySQL the single table is a wash on disk, faster to write,
faster for most report queries, and dramatically simpler — the 1.x schema
already carries most of a wide table's denormalization, it just pays for it six
times over. The costs concentrate in four specific places: query-time
sessionization, post-hoc enrichment, index discipline, and event-type
interleaving. All four are quantified below, and all four shrink or vanish on a
columnar backing store, which is the direction the single-table model points.

## What GA4 actually does

The GA4 BigQuery export is the closest public artifact to Google's model:

- **One row per event** in a single date-sharded `events_` table. No hit
  tables, no session table, no dimension tables.
- Common context lives in **nested records** per row: `device`, `geo`,
  `traffic_source`, page fields. Event-specific data lives in
  **`event_params`**, an array of key/value records where each value carries
  one of four typed columns (string/int/float/double).
- **Sessions are not stored.** `ga_session_id` is just an event parameter;
  sessions are counted as `COUNT(DISTINCT user_pseudo_id, session_id)`, and
  session metrics (engagement, bounce) are derived by grouping events at query
  time.
- **Commerce line items are nested inside the purchase event** as a repeated
  `items` record — the one-to-many problem solved inside the row.

Two properties make this work at Google scale, and neither is free on MySQL:
BigQuery is **columnar** (a query reading three fields of a 100-field table
touches only those columns) and its repeated/nested fields are first-class.
InnoDB is a row store: every read pays full row width, and the closest thing to
a repeated field is a JSON array. The experiment below is therefore not "is
GA's design good" but "what does GA's design cost when the store is MySQL".

Sources: [GA4 BigQuery export schema tutorial](https://optimizesmart.com/blog/ga4-bigquery-export-schema-tutorial/),
[events table schema](https://www.owox.com/blog/articles/ga4-bigquery-export-event-table-schema-and-dates),
[sessions from events](https://www.pipedout.com/resources/ga4-sessions-explainer),
[session metrics in BigQuery](https://www.bq4ga.com/sample-queries-for-the-ga4-bigquery-export/calculating-session-metrics/),
[nested/repeated fields and UNNEST](https://raulrevuelta.com/en/nested-structure-google-analytics-4-bigquery/).

## The part nobody says out loud: 1.x is already half a wide table

The 1.x fact tables are not lean star-schema facts:

| table | columns | of which |
|---|---|---|
| `owa_request` | 58 | 9 derived date parts, 10 custom-variable slots, ~15 session-context copies, dimension FKs |
| `owa_click` | 68 | all of the above plus 11 DOM columns |
| `owa_session` | 121 | 45 goal columns (15 goals × 3), 7 commerce accumulators, prior-session block |

Fifty-eight registered dimensions are *already denormalized* onto the fact
tables. The star pays for denormalization **and** dimension joins **and** a
121-column session accumulator maintained by an UPDATE per tracked event. The
single-table question is not "denormalize or not" — 1.x answered that years
ago — it is "consolidate the denormalization or keep six copies of it".

Two smaller exhibits of the same disease:

- Nine commerce metrics exist **twice** (`transactionRevenue` and
  `transactionRevenueFromSessionFact`, etc.), because which implementation is
  usable depends on which dimensions the query combines. One fact table means
  one definition per metric.
- `owa_request` carries both `os` and `os_id`, both `site` and `site_id` —
  legacy denormalized copies beside the FK they duplicate.

## Method

- **Corpus**: 1,192,454 real events (692,824 pageviews, 470,718 clicks,
  28,903 transactions carrying 115,596 line items, 9 actions) plus 325,833
  session rows and all dimension tables, copied fresh from a long-running
  installation. Domstreams excluded from every representation: they are
  recordings, not facts, and no fact-model choice changes what to do with them.
- **A — star**: fresh copy, production DDL, indexes and yearly partitioning
  intact. Fresh so the baseline is not handicapped by years of fragmentation.
- **B — event**: GA4-parity single table. Typed columns for what GA models as
  structs (page, traffic source, device, geo), `params` JSON for
  event-specific fields, line items as a nested JSON array, parsed browser
  instead of the raw user-agent, **no session table**. Same yearly
  partitioning; PK plus four secondary indexes.
- **C — wide**: the naive single table. Every parameter a real column (NULL
  where inapplicable), raw user-agent string inlined per event. Isolates what
  full-string denormalization costs a row store.
- **Environment**: MySQL 8.4, small burstable instance (2 vCPU / 2 GB), gp3
  storage at 3,000 IOPS, 512 MB buffer pool — each representation is ~2.6×
  the pool, so cold-vs-warm deltas are working-set signal.
- **Validation**: pageview count, click count, revenue sum and line-item count
  match exactly across all three representations before anything was timed.
- Timings are median of 4 warm runs; cold is the first run after build.

Reproduce with `run.php` in this directory (connection via environment
variables; see the file header).

## Results

### Disk — a wash

| representation | data MB | index MB | total |
|---|---|---|---|
| A star (6 facts + 11 dims) | 937 | 411 | **1,348** |
| B event (GA4-parity) | 977 | 342 | **1,318** |
| C wide (raw UA inline) | 1,005 | 327 | **1,332** |

Within 2% of each other. The inline strings B and C pay are almost exactly
offset by what the star pays in six copies of session context, the 318 MB
session accumulator, and six tables' worth of secondary indexes. The intuition
"denormalizing will blow up disk" is **false here**, because 1.x already spent
that disk. (On a columnar store B compresses radically — low-cardinality
strings across a million rows are the best case for column encoding — so this
wash on MySQL becomes a large win elsewhere.)

### Report queries — warm medians, ms

| query | A star | B event | C wide | notes |
|---|---|---|---|---|
| pageviews per day (month) | 39.7 | **18.4** | 18.1 | |
| top pages (month) | 73.3 | **64.1** | 65.5 | star cold 176 vs B cold 1,905 — see below |
| top referers (month) | 80.4 | **66.0** | 66.6 | |
| uniques per month (year) | 259.5 | **161.9** | 168.5 | |
| browser breakdown (year) | 418.9 | **144.3** | 143.2 | star pays a 40k-row dim join |
| pageviews per month (year) | **65.1** | 140.0 | 141.5 | event-type interleaving, see below |
| sessions + bounce (month) | **18.7** | 118.2 | 119.8 | star reads precomputed `owa_session` |
| avg session duration (month) | **19.0** | 120.1 | 119.2 | same |
| clicks for a page (month) | **39.0** | 179.6 | 171.1 | int-FK grouping beats string grouping |
| revenue by campaign (all time) | **36.0** | 183† | ~183† | see the honesty note |

† As first written (no site filter, so no usable index prefix) this query took
**35,277 ms** on B — a full scan of the whole event stream for 29k matching
rows. With the filter the index applies and it drops to 183 ms. Both numbers
matter: the artifact was the author's, but the exposure is structural. In a
star, a rare event type is a physically small table and *any* query against it
is cheap. In a single table, a rare event type is scattered through 1.3 GB of
everything else, and every query's performance is hostage to hitting an index.
The star forgives bad queries; the single table does not.

Reading the whole suite: **the single table wins the high-volume scan-and-group
queries** (no joins, better locality for the common case) — and loses in three
specific, explainable places:

1. **Sessionization (~6×, 19 ms → 120 ms).** What `owa_session` precomputes,
   B derives per query. 120 ms for a month on this hardware is entirely
   acceptable — but it scales with events per period, and every session-scoped
   metric pays it. A derived-but-materialized sessions rollup (rebuilt
   incrementally, GA4's own "intraday" approach) recovers the 19 ms without
   reintroducing write-path session state.
2. **Event-type interleaving (~2× on year scans).** A year of pageviews in B
   shares partitions with a year of clicks; the same scan reads more pages.
   Subpartitioning or clustering by event type would close it; the star gets
   this separation free by construction.
3. **Cold-cache penalties.** B's top-pages query cold is 1,905 ms vs the
   star's 176 ms: fat rows mean fewer rows per page, so the same logical query
   faults in more of the table. On a box whose buffer pool exceeds the hot
   window this disappears; on small hardware it is the row-store tax on
   denormalization, and it is real.

### Write path — 2.6× throughput, 7× fewer statements

| model | per tracked pageview | events/s (this hardware) |
|---|---|---|
| A star | 1 insert + 1 session upsert + 5 dimension existence checks = 7 statements | 79 |
| B event | 1 insert | **202** |

The single table's write path is one insert with zero lookups — ids are
content-derived, and there is no session row to upsert and no dimension rows
to check-and-create. This also deletes the entire class of dimension-FK bugs
1.x has spent this release cycle fixing (double-hashing, id-width migrations,
per-process caches), and makes the no-DB logging node trivial instead of
clever.

### Post-hoc enrichment — the star's strongest card, by 589×

The most-referenced referer in the corpus appears on **565,465 event rows**.
Writing one new attribute of that referer (what the `update-referral` crawler
does today):

| model | rows written | time |
|---|---|---|
| A star (update the dimension row) | 1 | 0.116 s |
| B event (update every event row) | 565,465 | 68.3 s |

This is not a fixable constant; it is the model. Anything OWA learns *after*
collection — crawled referer titles, improved user-agent parsing, geo
re-lookup — is one row in a dimension table and a full rewrite of history in a
denormalized table. GA4's answer is that it simply does not do post-hoc
enrichment: what was known at collection is what you have. v2 must either
accept that trade or keep tiny lookaside tables for the attributes it enriches
(see recommendation).

## Which 1.x metrics and dimensions are problematic

From the live registries: 78 metrics, 42 joined dimensions, 58 denormalized
dimensions. Classified against the single-table design:

**Free or cheaper (the large majority).** All 58 denormalized dimensions
(already inline on 1.x facts); content/campaign/geo/system/network dimension
families (become columns); count-style metrics — pageViews, actions, domClicks,
transactions, all revenue sums, uniqueVisitors, feedRequests. The nine
dual-implementation commerce metrics collapse to one definition each.

**Pay query-time sessionization (measured ~6× vs precomputed).** visits,
bounces, bounceRate, visitDuration, pagesPerVisit, uniquePageViews,
newVisitors/repeatVisitors when session-scoped; every `*InVisit` denormalized
dimension (pagesViewsInVisit, revenueInVisit, transactionsInVisit, …);
entry/exit page families (first/last event per session — window functions);
priorPage* (LAG over the session's events); sessionId as a dimension.
Materialize a sessions rollup and this class returns to 1.x speed.

**All 48 goal metrics — changed, mostly for the better.** 1.x evaluates goals
at collection into 45 dedicated `owa_session` columns: definitions are frozen
into history and the count is capped at 15. As query-time predicates over
events (GA4's "key events"), goals become retroactive and unlimited — a
capability 1.x cannot offer at any price — but every goal metric joins the
sessionization class, and `goalAbandonRateAll`-style start/completion pairing
needs funnel-shaped queries.

**Dependent on post-hoc enrichment (the 589× class).** referralPageTitle,
referralLinkText, referralWebSite, isSearchEngine (filled by crawling after the
fact); browserType/browserVersion when browscap improves; geo re-lookup. These
either lose their enrichment property or keep lookaside tables.

**One-to-many.** productSku/Name/Category dimensions and
lineItemQuantity/Revenue/uniqueLineItems metrics live in the nested `items`
array. MySQL can unnest with `JSON_TABLE` but cannot index into arrays, so
item-dimension queries scan all transaction events. Fine at this corpus's
scale (36 ms); the clean escape is GA4's own: emit item-level events
(`view_item`, row per item) instead of nesting, at the cost of more rows.

**Cross-visit visitor state.** isRepeatVisitor, daysSinceFirstVisit/LastVisit,
priorVisitCount are stamped onto events at collection from visitor state — the
same approach works unchanged in the event model (GA4 stamps user properties
identically), but they can never be *recomputed*, and `owa_visitor` should
survive as an entity regardless (it is an operational record, not analytics).

**Unaffected.** Site (admin object, stays a table), feed metrics (another
event type), latestAttributions (already a blob; becomes params).

## What it removes from the codebase

Measured against the current tree, not estimated. The schema is the smaller half
of the change: most of the machinery that exists to reconcile facts with
dimensions across six tables has no counterpart in an event model.

### The dimension-write half of ingestion

Ten of the twenty-three event handlers exist solely to check-then-create a
dimension row -- Document, UserAgent, Os, Host, Referer, SearchTerm, Ad, Source,
Campaign, Location -- roughly **1,000 of the handler layer's 2,663 lines**, each
performing the same `getByPk` then `create()` dance. Writing the value inline
with the event removes the lookup, the creation, and the race.

With it goes the **dimension foreign-key derivation machinery**: 15
`alternative_key` registrations and ~31 references across `Module.php` and
`TrackingEventHelpers`. That is where this year's most expensive defects lived
-- the double-hashed fact-row FKs, the 32-to-63-bit id migration and its five
follow-up fixes, the per-process derivation caches. **A foreign key cannot be
derived wrongly when there is no foreign key.**

### The reporting entity-resolution layer

`ResultSetManager` is 1,951 lines, of which about **290 answer only "which fact
table can serve this request?"** -- `chooseBaseEntity` (123), `isDimensionRelated`
(64), `lookupDimension` (36), `getDimensionForeignKey` (30), `getMetricEntities`
(30), `reduceTables`. One table does not pose the question.

The compatibility *rules* still need enforcing -- see the additivity note above,
which is the one thing that must move from schema-enforced to registry-enforced
-- but as a declared table rather than a search over an entity graph with
summary-level sorting and set intersection.

### Duplicate metric implementations

**48 metric classes, 2,414 lines**, including seven literal twins:
`TransactionRevenue` and `TransactionRevenueFromSessionFact`, and the same for
line-item quantity and revenue, tax, shipping, transactions and unique line
items. They exist because the answer lives in two tables and which one is usable
depends on the dimensions requested. One table, one implementation each.

### Session maintenance leaves the write path

`SessionHandlers` (295 lines) and `SessionCommerceSummaryHandlers` (134) keep a
121-column row current on **every event**. A scheduler job over closed sessions
carries the same information off the hot path, and can be rebuilt when wrong.
`ConversionHandlers` (388 lines) evaluates goals at collection into 45 frozen
columns; as query-time predicates those become retroactive as well as cheaper.

### Schema evolution stops being schema evolution

**14 Update classes (1,993 lines)** and **411 compat-alias entries**, all of which
exist because entities are tables and tables need migrating. Adding a parameter
to an event becomes a JSON key rather than a column, an update class, an alias
entry and a phpstan baseline bump.

### The ongoing tax, stated concretely

Adding one event type today, taking commerce transactions as the example:

| | |
|---|---|
| entity class | 158 lines |
| ingestion handler | 184 lines |
| session accumulator | 134 lines |
| metric classes | 22 files |
| registrations in `Module.php` | ~120 lines |

In an event model that is a new `event_type` value plus whatever parameters it
carries: data, not DDL.

### Total, honestly

Roughly **3,500-4,000 lines** across the handler, metric, entity and resolver
layers exist *because* the star schema exists. Not all of it vanishes --
ingestion, validation and a metric registry are still required -- but everything
whose only job is reconciling facts with dimensions across six tables does.

The pattern worth carrying into the decision: **nearly every hard bug of the
past year lived in that machinery.** Double-hashed FKs, the id-width migration
and its follow-ups, dimension rows that could not be derived, dangling
references, two metric definitions disagreeing. None of those failure modes has
anywhere to live in a single event table.

## The data-access layer, PDO, and where the seam belongs

Measured against the current tree:

```
Core/Db.php        3,171 lines, 118 methods -- a structural query builder
                   (select / where / join / groupBy / orderBy / having / limit)
Core/Db/Mysql.php    567 lines -- driver plus 65 dialect constants
Core/Entity.php      966 lines -- active record
raw mysqli_ calls outside the driver:  0
```

**Zero driver leakage** is the number that matters. The seam is already in the
right place: a structural builder above a driver, with dialect fragments as
constants. Homegrown and dated, but architecturally sound.

### The single table barely helps PDO adoption -- it was already easy

Swapping mysqli for PDO means rewriting **one 567-line file**, because nothing
above it touches mysqli. The schema change does not move that.

What the schema change alters is the **query vocabulary**, which is what
portability actually turns on. Today the builder can emit arbitrary n-way joins
across six fact and eleven dimension tables. In an event model it is: filter by
site, date and event type, filter on a column or JSON parameter, group,
aggregate, order, limit. A dozen constructs rather than an open-ended join
graph -- the difference between a query layer that can be implemented three
times and one that cannot.

### PDO yes, but not as the abstraction

PDO is a **connection and statement** abstraction. It is not a dialect
abstraction, and it is not a transport abstraction. It covers MySQL, Postgres,
SQLite and SQL Server -- SQL over a socket with prepared statements. It does
**not** cover BigQuery, which is a REST API with no PDO driver; ClickHouse and
DuckDB have third-party drivers of varying maturity.

Adopting PDO *as the portability layer* is therefore the trap: PDO idioms leak
upward -- placeholder styles, fetch modes, driver quirks -- and the first
non-SQL backend forces a second re-abstraction. PDO belongs as one backend
family **under** the existing seam:

```
reports / ResultSetManager
        |  structural query description   <- the portability seam
   +----+-----------------+
 SQL backends          columnar backends
 (via PDO)             (BigQuery REST, ClickHouse HTTP)
```

The one change that makes this real: `generateSelectQuerySql()` assembles a SQL
**string** inside the builder today. A columnar backend needs the builder to
hand over a query **object** that each backend compiles. That is a refactor of
the emit stage, not of the `select()` / `where()` / `groupBy()` API.

Two benefits worth having regardless of any of the above: `prepare()` is
currently `mysqli_real_escape_string` -- escaping, not parameter binding -- so
PDO brings real bound parameters and removes a class of review burden; and it
retires the hand-rolled connection handling, including the failure path fixed
in #1014.

## A columnar store does not remove the need for a SQL one

If tracking and reporting move to a column store, the administrative side still
wants a transactional one. The two halves have opposite requirements:

| | events | administration |
|---|---|---|
| shape | append-only, high volume | small, mutable |
| access | scan and aggregate | read and update single rows |
| examples | `owa_event` | sites, users, goals, configuration, event queue |
| suits | columnar | row store |

Column stores are poor at row-level update and delete, which is exactly what
user management, goal editing, configuration and **the event queue** consist
of -- a queue in particular claims and deletes individual rows, which is close
to a worst case.

The saving grace is scale: the administrative tables are tiny. On the
installation measured here, `owa_site` holds one row and `owa_configuration`
holds one. This is SQLite-scale data, not a second database cluster.

Two consequences for the design:

**Cross-store joins must not be required.** Reports that show a site's name or
domain cannot join a columnar event table to a SQL site table. Both available
answers are already in the plan: the site id is denormalised onto every event,
and the administrative side is small enough to fetch and join in application
code at single-digit row counts.

**The split must be logical before it is physical.** The common deployment --
one self-hosted MySQL -- must remain the default, with the columnar option for
large installations. So the boundary belongs in code as two repositories, an
administrative one and an event one, which may point at the same connection.
Splitting them later then becomes configuration rather than a refactor, and the
default install never pays for an option it does not use.

Where the sessions rollup lives follows from its size rather than its origin:
50.2 MB for 312,518 sessions is comfortably row-store scale, and it is rebuilt
by a scheduled job rather than queried at high volume -- so it sits naturally
with the administrative store, on the same side of the boundary as the job that
maintains it.

## Multiple backing stores is a requirement, not an option

Everything above points the same way: v2 should assume more than one backing
store from the start. Not because every installation needs one -- the common
deployment is a single self-hosted MySQL and must stay that way -- but because
the alternative is discovering the seam is wrong after committing to it.

Concretely that means three things, in this order of importance:

1. The **query description**, not the SQL string, is what crosses the boundary
   between reporting and storage.
2. The **administrative and event stores are separate repositories in code**,
   whether or not they point at the same connection.
3. PDO is adopted for the SQL family as one backend among several, never as the
   abstraction itself.

None of the three costs anything on a single-MySQL install, and all three are
expensive to retrofit.

## Store the URI, not the full URL

GA keeps a site's own pages as paths and treats the host as a separate concern:
the domain is something configured once, on the property, not repeated on every
hit. OWA should do the same, and the reason is not tidiness -- it is a live
defect.

Document ids are derived from the **full URL**, so every scheme, host and
hostname variant mints a separate document for the same page. Measured on a real
installation:

```
uri = /                    ->  7 document rows
      hosts: 172.16.0.63, demo.openwebanalytics.com,
             demo.openwebanalytics.com.  (trailing dot), localhost
uri = /ecommerce.php       ->  3 rows
uri = /funnel-step-1.php   ->  2 rows   (same host: scheme or trailing-slash)
```

The homepage's traffic is divided seven ways, silently, in every report that
touches it. `makeUrlCanonical()` exists to mitigate this and evidently does not
cover scheme, bare-IP access, or a trailing-dot FQDN.

Keying a page on its URI collapses those variants by construction, and removes
the domain from every fact row -- which on a columnar store is also the best
possible case for compression, being one low-cardinality value repeated
throughout.

Three constraints come with it, and the first is easy to get wrong:

- **The id must be scoped by site.** `hash( url )` is globally unique today only
  because the URL contains the domain. `hash( uri )` alone would merge `/about`
  across every site in the installation, so it must be `hash( site_id + uri )`.
- **Referrers keep their full URLs.** They are external, the domain is not
  yours to normalise away, and it is the most useful part of the value.
- **Hostname stays a dimension in its own right.** A site spanning `shop.` and
  `www.` must remain separable, which is why GA keeps Hostname distinct from
  Page path. OWA already registers `hostName`; it simply stops being embedded in
  the page's identity.

The domain then surfaces exactly once, where it belongs: on the site record, set
when the site is configured.

## The event queue, revisited

Worth reopening for v2, and the groundwork is better than expected.

### What is already right

The interface is **already message-queue shaped**: `sendMessage`,
`receiveMessage`, `deleteMessage`, `markAsBroken`, `hasExhaustedRetries`. That
is the SQS vocabulary, so a managed-queue backend is not a new abstraction --
it is a new implementation of an existing one. Three backends exist today: file
(378 lines), database (274), HTTP forward (85).

### What is wrong

**The database queue has no atomic claim.** `is_assigned` is declared on the
QueueItem entity and is **never read and never written** anywhere in the tree.
Two concurrent drainers therefore fetch and process the same rows.

The scheduler's per-job lock currently conceals this: one job, one lock, one
drainer. But that makes the lock a workaround rather than a fix, and it caps
throughput at a single worker forever -- which is the opposite of what adding a
queue is for.

The second known defect is the poison pill: a handler returning EVENT_FAILED on
an item that will never succeed re-queues it indefinitely. That was diagnosed
and fixed once at the handler level, but nothing structural prevents the next
one.

### What the scheduler changes

Draining becomes a registered job on a cadence rather than a crontab line or a
manual run, with the per-job lock providing mutual exclusion the queue itself
lacks -- so *scheduling* the drain is already safer than running it from two
cron entries. That is worth having, but it is compensation for a missing claim,
not a substitute for one.

### Why SQS fits, and what it demands back

SQS supplies exactly what the database queue lacks, as primitives rather than
code: a **visibility timeout** is lease-based claim, a **dead-letter queue with
maxReceiveCount** is `markAsBroken` and `hasExhaustedRetries` without hand-rolling
them -- and it is precisely the structural answer to the poison pill. Multiple
workers stop needing a global lock.

What it requires in exchange lines up with decisions already taken elsewhere in
this document, which is the encouraging part:

| SQS property | consequence | already planned? |
|---|---|---|
| at-least-once delivery | handlers must be idempotent | yes -- dedup on `(session_id, event_id)` |
| no ordering guarantee (standard queues) | processing must be order-independent | yes -- engagement is additive deltas |
| batch receive, max 10 | drain loop works in batches | minor |
| approximate depth only | queue-depth reporting becomes approximate | affects `schedule-status` wording |
| 256 KB message limit | **domstream events may exceed it** | needs checking |

That last row is the one to verify before committing: DOM recordings are the
largest events OWA produces, and a queue that silently rejects them would be
worse than no queue.

### The file queue is not a competing backend

It serves a different purpose and should be reframed rather than replaced: a
durable local **spool** for a logging node with no database, the
`OWA_USE_STATIC_CONFIG_ONLY` case. Calling it a queue invites the assumption
that it competes with the others; it is a write-ahead buffer that something
else later drains.

### The shape v2 wants

One change carries most of the value: move the interface from *fetch and
delete* to **lease semantics** -- `receive()` returns items with a lease,
`ack()` deletes, and an unacked lease expires back onto the queue. Every backend
can then be implemented correctly:

- **database**: the lease is the claim the current implementation is missing,
  and `is_assigned` finally does the job it was declared for
- **SQS**: visibility timeout, natively
- **file spool**: a rename or lock file

Dead-lettering becomes first-class rather than a flag on a row, workers become
scheduler jobs, and running several stops requiring a global lock. The
scheduler that shipped in 1.11 is what makes worker jobs a configuration
question rather than a deployment one.

## Pros and cons

**For the single table:**
- One write per event, no lookups: 2.6× measured write throughput, and the
  dimension-FK bug class (this cycle's most expensive class) ceases to exist
- Most report scans measurably faster (up to 3× where the star pays a big join)
- Disk is a wash — the feared blowup already happened in 1.x
- One definition per metric; adding an event type is data, not DDL (four new
  entities, aliases, updates in 1.x)
- Retroactive, unlimited goals
- The schema GA taught a generation of analysts to expect, and the shape
  columnar engines want — the migration path to ClickHouse/DuckDB/BigQuery is
  "change the store", not "change the model again"

**Against it (on MySQL specifically):**
- Session metrics ~6× slower unless a sessions rollup is materialized
- Post-hoc enrichment 589× slower — or abandoned, or kept via lookaside tables
- Unforgiving of index misses: 183 ms vs 35 s is one WHERE clause apart
- Event-type interleaving taxes single-type scans ~2×; cold-cache penalties
  on fat rows are real at small buffer pools
- JSON params are second-class in MySQL: per-row extraction CPU, no array
  indexing, and typed-value discipline is on the application

**A row worth pinning:** every "against" above is a row-store cost. On a
columnar store the interleaving vanishes (column pruning), the fat-row penalty
vanishes, params become native nested columns, and compression turns the disk
wash into a win. The single-table model is not just simpler — it is the only
one of the two designs that gets *better* by changing the backing store.

## Recommendation sketch (to be debated, not decided here)

A single event table, GA4-shaped, with two deliberate impurities:

1. **A materialized sessions rollup** — derived from events on a schedule (the
   scheduler shipped in 1.11 exists for exactly this shape of job), never
   written by the tracking path. Recovers precomputed-session query speed
   without the 121-column accumulator or its write-path coupling.
2. **Lookaside tables for enriched attributes only** (referer crawl results,
   UA parse cache) — keyed by the same content hash, joined only by the
   reports that need them. A few MB each; keeps the 589× enrichment property
   for the two families that use it.

Everything else — dimension tables, per-type fact tables, session goal
columns, dual metric implementations — goes.

## Addendum: pushing session state onto the tracker

Raised after the first round of this research: could the tracker fire a
`session_update` event periodically (and before navigation) so the server
needs neither query-time sessionization nor a materialized rollup?

"Session state" is three separable things, and the answer differs per part:

1. **Identity and lifecycle — client-side already, keep it.** GA4 mints the
   session id and number in the cookie, times out sessions client-side, and
   fires `session_start` itself. The 1.x state manager already carries the
   identity half.

2. **Engagement time — the tracker is the only honest witness.** Server-side
   duration cannot see time spent on the final page; a bounce reads as zero
   seconds after ten minutes of reading, in 1.x and in every server-derived
   variant alike. A tracker accumulating visible-engagement time fixes what no
   server-side design can. Delivery matters: GA4 does not heartbeat — it
   piggybacks `engagement_time_msec` on whatever event goes out next and fires
   a standalone terminal beacon (sendBeacon on pagehide / visibilitychange:
   hidden, not beforeunload, which mobile skips) only when a session ends with
   unsent time. On this corpus (2.1 pageviews/session): a 30 s heartbeat would
   add ~20 events per session, a per-navigation update ~+100% event volume,
   piggyback-plus-terminal ~+1 event per session (~+27%).

3. **Counters (pageviews-in-session, bounce, pages/visit) — keep these
   server-derivable.** Four independent reasons: client death makes asserted
   state stale with unbounded loss (heartbeats bound it at the volume cost
   above); multi-tab sessions fragment client-side counts; the client is an
   untrusted witness for facts the server observed itself (the same principle
   behind server-assigned event time); and non-JS event sources (PHP tracker,
   log replay, queue-fed nodes) produce sessions with no session_update rows,
   so the derivation must exist as a fallback regardless.

Net effect: with identity and engagement client-side, the rollup's job shrinks
from "reconstruct sessions" to "count events per session" — measured at
~118 ms per month on deliberately small hardware. That is likely acceptable at
query time, demoting the materialized rollup from architectural requirement to
an optional optimization for large installations.

**Report engagement as a delta, not a running total.** GA4's
`engagement_time_msec` carries the time accrued *since the previous event*, and
the server SUMs the deltas; it is not a cumulative figure resolved by
last-writer-wins. The delta form is the better fit here for two reasons that
matter more than they might elsewhere: addition commutes, so out-of-order
arrival — which the async queue path makes ordinary rather than exceptional —
cannot corrupt the total; and deltas compose across concurrent tabs, where
per-tab running totals cannot be summed at all.

The trade accepted in exchange, worth stating rather than discovering later:
deltas are **not idempotent**, so a retried beacon double-counts, and a dropped
one is a permanent small undercount that no later event repairs. A cumulative
value has the opposite profile — self-healing on mid-session loss, idempotent
on retry, but it needs ordering and does not compose across tabs. GA chose
deltas; so should this, but with deduplication treated as a real requirement
rather than a theoretical one, since OWA's queue can redeliver where GA's
HTTP-only path does not.

Deduplication needs only a **unique event id**, which the event model already
carries — `(session_id, event_id)` is sufficient. A monotonic per-session
sequence number is therefore *not* required for correctness.

**A running total alongside the delta was considered and rejected.** It would
have served as a check digit (`SUM(delta)` vs `MAX(total)` per session) and as
a repair value, since a total self-heals a dropped event where deltas cannot.
Kept in the existing cookie state it would also have spanned subdomains as
session identity does, which web storage cannot.

It is not worth what it costs. Tabs amending one shared counter race on
read-modify-write, which needs `navigator.locks` to suppress same-origin and
stays unfixable across subdomains; the resulting false positives mean the
invariant has to be read in aggregate rather than per session; and a
monotonically growing millisecond counter in a cookie rides on every HTTP
request to the domain, assets included, where OWA users are sensitive to cookie
weight for performance and privacy-policy reasons alike. That is a substantial
amount of machinery to detect a failure not yet known to occur.

So: **deltas only.** A lost beacon becomes a small silent undercount with
nothing to detect or repair it, which is the ordinary tolerance for
client-measured analytics and the same trade every comparable product makes.
The total remains purely additive if evidence ever justifies it — better added
against a known failure pattern than guessed at in advance.

**Deduplication is required regardless**, and is not part of what was dropped:
deltas double-count on redelivery, and the event queue can redeliver.
`(session_id, event_id)` is sufficient and costs nothing, since events carry
ids already.

Remaining design guards: terminal updates append rather than upsert (the event
stream stays immutable, which queue and replay semantics want), and any state
that genuinely is last-writer-wins rather than additive resolves at read time.

Sources: [engagement_time_msec is a delta, summed server-side](https://optimizesmart.com/blog/understanding-engagement_time_msec-in-ga4-bigquery/),
[how engagement time accrues](https://accs-net.com/glossary/engagement-time/).

## Addendum 2: session metrics without a rollup

The first addendum concluded that dropping the precomputed session table costs
a ~6x penalty on session metrics, and that a materialised rollup would be
needed to recover it. That conclusion was wrong, and the reason is worth
recording because it changes the shape of the design.

**Most "per session" metrics are ratios of two independent aggregates, not
averages over per-session groups.** An average over groups is the total divided
by the group count:

```
AVG(pageviews per session) = SUM(per-session pageviews) / COUNT(sessions)
                           = COUNT(pageview events) / COUNT(DISTINCT session_id)
```

So pages-per-visit never required grouping. Nor does events-per-session, nor
average session duration when engagement is summed from per-event deltas
(`SUM(engagement) / COUNT(DISTINCT session)` — which is exactly GA4's
`userEngagementDuration / sessions`). GA4 confirms this by omission: there is
no `pagesPerSession` metric in its Data API at all, because Views and Sessions
are both already available and the ratio is the metric.

What genuinely requires per-session evaluation is a **threshold or predicate** —
a session must be examined as a unit before it can be counted:

- bounce rate: sessions with exactly one pageview
- engaged sessions: sessions past a time or depth threshold

**GA4 pushes that evaluation to the client.** `session_engaged` is an automatic
parameter set by the tracker, sticky once the session crosses the threshold
(>10 s, two or more pageviews, or a key event), so every later event in the
session carries it. The server then only counts:

```sql
COUNT(DISTINCT session_id) WHERE session_engaged = 1
```

With that, the whole session metric set is aggregates over the event table and
no `GROUP BY session_id` appears anywhere:

| metric | computation |
|---|---|
| visits | `COUNT(DISTINCT session_id)` |
| pageViews | `COUNT(*) WHERE event_type='pageview'` |
| pagesPerVisit | pageViews / visits |
| avgSessionDuration | `SUM(engagement_delta)` / visits |
| engagedSessions | `COUNT(DISTINCT session_id) WHERE engaged=1` |
| engagementRate, bounceRate | engagedSessions / visits |

A **sticky boolean is also race-free**, which is what makes it safe where the
rejected running total was not: setting it from two tabs is idempotent, OR
commutes, and there is no read-modify-write to lose. The client carries exactly
two pieces of session state — an additive delta and a monotonic flag — and both
are immune to the concurrency problems that sank the counter.

So the rollup is **not required**. It remains worth building for one reason
that is about semantics rather than cost: splitting visits by a dimension that
can drift within a session (source, campaign, entry page) counts a session once
per distinct value from the event table, so the parts exceed the total. A
rollup assigns one value per session and the split sums correctly.

Costs accepted, none fatal: bounce changes meaning from "one pageview" to "not
engaged", which is the same change GA made and existing reports would notice;
non-JS event sources carry no flag and need a defined fallback rather than a
silent zero; and the client is trusted for the flag, though a session with five
pageviews and `engaged=0` is trivially checkable server-side.

**One rule stands regardless of source**: a single report resolves every metric
from one source, never a mix, or two numbers in the same table can disagree.
1.x violates this today — nine commerce metrics exist in fact-table and
session-fact variants selected by the dimensions requested. If a rollup is
introduced, the invariant `SUM(rollup.pageviews) == COUNT(pageview events)`
belongs in the test suite rather than in anyone's assumptions.

Sources: [no pagesPerSession in the Data API](https://accs-net.com/glossary/pages-per-session/),
[session_engaged is client-set and sticky](https://accs-net.com/glossary/engaged-sessions/),
[engagement thresholds](https://support.google.com/analytics/answer/12798876?hl=en).

## Addendum 3: measured -- an incremental rollup, not marker events

Two rounds of this analysis argued the rollup away: first because session
metrics looked like ratios of aggregates, then because GA4's `session_start`
marker looked like it made visits an indexed count. Measurement and a closer
reading of GA settle it the other way.

### The measurements

Corpus of 1,192,454 events across 312,518 sessions, same hardware as the rest
of this document.

| question | raw event table | rollup |
|---|---|---|
| visits, one month | 106.2 ms | **2.9 ms** |
| visits, whole corpus | **39,184.7 ms** | **206.5 ms** |
| pages per visit, one month | 52.9 ms | 7.7 ms |
| engaged sessions, one month | 114.4 ms | 7.6 ms |

`COUNT(DISTINCT session_id)` over the corpus is **39 seconds**. That is not a
slow report, it is a broken one, and it is the number that disproves the
earlier "probably fine at query time" -- which had been generalised from a
118 ms measurement over a single month of a small site.

Rollup economics: **50.4 s** for a full rebuild, **377 ms** incrementally for
the busiest day's 3,095 events, **50.2 MB** against the event table's 1.3 GB.
Sessions close after the inactivity timeout and can never change again, so a
scheduled job only ever recomputes sessions still open -- bounded per tick
regardless of how much history exists.

### Why not marker events

GA4 does fire `session_start` as a separate row (a `page_view` carrying the
`_ss` flag causes it to be generated), so a marker is one extra event per
session, not a replacement. But **GA4 does not count `session_start` to count
sessions**; it estimates the number of distinct session ids instead. The marker
is known to go missing -- consent mode, app lifecycle, and similar -- and a
missing marker erases a session from the visit count while every other event
from that session remains in the table.

Approximate distinct counting is how GA escapes the 39-second problem, and it
is not available here: MySQL has no HyperLogLog.

So the three options are exact-and-unusable (39 s), fast-and-lossy (markers),
or exact-and-cheap (a rollup derived from every event, immune to any single
event going missing). The last one wins, and at 377 ms per tick the cost is not
a consideration.

Markers remain useful for what they transport rather than what they count --
first-touch attribution and entry page at the moment those are known -- but a
rollup row can carry the same values derived server-side, so they are optional
rather than load-bearing.

### Why a sticky flag survives this and a marker does not

The distinction is redundancy, not reliability. A marker is carried by **one**
event, so losing it erases the session from visit counts while every other
event from that session remains in the table and still counts toward
pageviews -- the numbers do not merely drift, they stop being internally
consistent. A sticky flag is carried by **every** event after the threshold, so
losing any one of them costs nothing; all of them would have to be lost, at
which point the session is absent regardless.

Same information, opposite failure shape, and the loss is not randomly
distributed either -- ad blockers, consent gates and mobile backgrounding bias
exactly which sessions lose their marker.

The rule this leaves for v2: **never make a record's existence depend on a
single packet.** Derive existence from any of the events that reference it, so
that any one arriving is sufficient. Values may degrade; records must not
vanish.

### What this restores

With a rollup, session-scoped metrics return to 1.x speed or better, and the
attribution-drift problem disappears with them: one row per session carries one
canonical value per session, so splitting visits by source is additive, exactly
as the wiki's additivity rule requires.

The rollup that follows from all this is small -- one row per session, counts,
bounds, engagement sum, and the session-scoped dimensions -- against
`owa_session`'s 121 columns. It is derived rather than write-path maintained,
so it is rebuildable, and the invariant
`SUM(rollup.pageviews) == COUNT(pageview events)` belongs in the test suite.

Sources: [session_start and page_view are separate rows](https://www.optizent.com/blog/introduction-to-google-analytics-4-data-in-bigquery/),
[GA4 estimates distinct session ids rather than counting session_start](https://www.ga4bigquery.com/how-to-sessionize-your-ga4-event-data-in-bigquery-part-1-default-session-definition/),
[session_start goes missing](https://www.analyticsmania.com/post/session-start-event-is-missing-in-google-analytics-4/).

## Rebuilding this experiment

```
export V2_DB_HOST=… V2_DB_USER=… V2_DB_PASS=…
export V2_SRC_DB=<an owa database>  V2_DST_DB=<scratch database>
php run.php build-star     # fresh copy of the 1.x tables
V2_SRC_DB=<scratch> php run.php build-event
V2_SRC_DB=<scratch> php run.php build-wide
php run.php validate       # must print MATCH on every row
php run.php sizes
php run.php bench          # writes results/bench-<stamp>.json
php run.php bench-insert
php run.php bench-enrich
php run.php drop           # removes the scratch database
```
