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

## Heatmaps

A click is simply `event_type = 'click'` in `owa_event` -- no click table, no
heatmap table, no aggregate. Its parameters carry the coordinates, the viewport,
and the identity of what was clicked, and the heatmap is an ordinary query:

```sql
SELECT element_path, element_text, COUNT(*) n
FROM owa_event
WHERE event_type = 'click' AND site_id = ? AND page_key = ?
  AND yyyymmdd BETWEEN ? AND ?
  AND <any dimension filter>
GROUP BY element_path
ORDER BY n DESC
```

The same shape as top pages, so "clicks from mobile visitors on campaign X"
comes from the standard machinery rather than a bespoke endpoint.

### What the current data can and cannot support

Measured over 470,718 clicks:

```
2,297 distinct viewport widths
dom_element_id     real value:    437  (0.09%)  -- 439,839 are "(not set)"
dom_element_class  real value: 39,731  (8%)
dom_element_text   real value: 266,694 (57%)    -- 266,491 clicks are on <A>
dom_element_parent_id           0  (0%)         -- declared, never written
page_width/height  populated on ~100%
```

**Coordinates are aggregated across 2,297 layouts.** A click at x=400 on a
1920px viewport and one at x=400 on a 1280px viewport are different elements
entirely, and the overlay plots them together on whatever width the analyst
happens to be using. The more responsive the site, the less the picture means,
and it cannot be corrected at render time because the information is already
lost.

**Element identity is mostly absent.** The columns exist -- id, class, text,
parent -- but in practice id is a real value on 0.09% of clicks and class on 8%.
`dom_element_text` is the exception at 57%, and since 266,491 of 470,718 clicks
land on an `<A>`, link text is a genuinely useful identifier. There is no path or
parent, so no stable selector can be constructed from what is stored.

The schema anticipated more than the implementation delivered, which is the same
pattern as the domstream columns and the CORS outline.

### What v2 should do

1. **Aggregate by element, not coordinate.** Responsive-safe, survives layout
   changes, and makes the clicked element **a dimension like any other** -- so it
   segments through the normal machinery instead of a special overlay path.
2. **Which requires a tracker change**, and that is the honest cost: capture a
   stable element path (an nth-of-type ancestor chain, or a hashed selector).
   Element-based heatmaps are not possible from what is stored today.
3. **If coordinates are kept, bucket by viewport** -- per breakpoint, or
   normalised as a percentage of `page_width`. Overlaying 2,297 widths on one
   canvas is not a rendering problem to solve later.
4. **Keep `dom_element_text` as the label.** An aggregate keyed on a selector
   hash needs something human-readable to display, and "Visitors Reporting" is
   what makes the report legible.
5. **The rendering path stops being special**: a query over click events rather
   than a JSONP endpoint feeding 614 lines of `Heatmap.js`.

## The UI layer

### Measured

```
controllers   149 files, 13,737 lines   (69 report, 23 admin, 23 CLI, 7 REST, 7 install)
views          68 files,  3,038 lines
templates      91 files,  4,438 lines
core plumbing  Controller 901 + Template 1,107 + View 607 + ReportController 204
web actions   150 registered
REST routes     9 registered
```

### Reports are configuration expressed as classes

Two patterns across 69 report controllers, both declarative. Either a named
report through the API:

```php
$rs = CoreAPI::executeApiCommand([ 'do' => 'reports',
        'report_name' => 'latest_visits', 'siteId' => ..., 'period' => ... ]);
$this->set('latest_visits', $rs);
$this->setSubview('base.reportVisitors');
```

or a declared specification rendered by a shared subview:

```php
$this->set('metrics',     'actions,actionsValue');
$this->set('dimensions',  'actionLabel');
$this->set('sort',        'actions-');
$this->set('constraints', 'actionName==' . urlencode($actionName) . ',...');
$this->setSubview('base.reportSimpleDimensional');
```

Neither contains logic. **69 PHP classes express what is fundamentally a config
record** -- `{name, metrics, dimensions, constraints, sort, chart, title,
template}`. As data rather than classes they collapse to a registry and a few
renderers, and reports become **user-definable**: today a custom report means
writing and deploying a PHP class.

### Reports as widget definitions, and the retrofit into 1.x

Counting subviews shows how much of the reporting UI is one shape wearing
different parameters:

```
49 of 69 controllers share three subviews
  22  base.reportDimension
  17  base.reportSimpleDimensional
  10  base.reportDimensionDetail

20 controllers have a subview of their own (one each)
```

The 49 differ only in the metrics, dimensions, sort and constraints they
declare. The 20 look bespoke, but the templates say otherwise: the dashboard is
eight `owa_reportSectionHeader` / `owa_reportSectionContent` pairs composed of
widgets, and the reporting bundle already ships a widget vocabulary --
`owa.kpibox.js`, `owa.areachart.js`, `owa.piechart.js`, `owa.sparkline.js`,
`owa.resultSetExplorer.js`.

**What is missing is any layout descriptor.** There is not a single column,
span, grid or width class in the dashboard or traffic templates -- arrangement is
implicit in hand-written HTML and CSS, written afresh each time. That, rather
than any structural difference, is why each of the 20 needed its own template.

So the format wants to describe **widgets in a grid**:

```json
{
  "name": "dashboard",
  "layout": [
    { "cols": 4, "widgets": [ { "type": "kpi", "metric": "visits" } ] },
    { "cols": 2, "widgets": [
        { "type": "areachart", "metric": "pageViews", "period": "last_thirty_days" },
        { "type": "piechart", "dimension": "medium", "metric": "visits" } ] },
    { "cols": 2, "widgets": [
        { "type": "table", "dimension": "pageUrl",
          "metrics": ["pageViews", "uniquePageViews"], "limit": 10 } ] }
  ]
}
```

**One format, two levels.** A widget is a query plus a display type, and the 49
dimensional reports are simply a page holding one widget. It is not "49 config
records and 20 special cases" -- it is one format where some pages hold one
widget and some hold several.

That also bounds the vocabulary risk. The danger with definitions-as-data is
drifting into a worse programming language; a widget-with-layout format has a
natural edge -- a widget is a query and a rendering, and whatever is not
expressible as those two things stays code. `ReportGoalFunnel`'s funnel maths is
a genuine widget *type*, not a failure of the format.

**JSON rather than PHP arrays**, because the same document then ships as a file,
is stored in a table when user-defined, is served by the API, consumed by the
client, and exported or imported. A PHP array only manages the first.

**The retrofit is additive, with a clean split.** In 1.x, one generic controller
loads a definition and performs the same `set()` calls into the same subview --
behaviour identical, since the subview does not change. Roughly 49 classes
become 49 documents, and reports become **user-definable in 1.x**, which today
requires writing and deploying a PHP class.

v2 discards that controller -- its API serves definitions to the client instead
-- but **reuses the definitions unchanged**. The definitions are the durable
artefact; only the renderer differs. And a client-rendered UI needs a layout
description it can map onto components, which hand-written PHP templates cannot
provide, so this is one artefact serving both.

One decision the format must make rather than defer: **where responsive
behaviour lives**. `"cols": 2` has to mean something on a phone, and with the v2
client doing layout, breakpoint behaviour belongs in the definition rather than
in CSS the client cannot see.

### Two parallel paths to the same data

**150 web actions against 9 REST routes**, and only 7 of 69 report controllers
go through the API at all. So the HTML path and the API path are separate
implementations of the same questions, which is where drift lives -- and it is
why the recordings list hand-writes SQL against `owa_domstream` while the
resolver that would have segmented it sits unused.

API-first is the non-negotiable part of this: one data path, with the UI as a
consumer like any other client. Everything below is then a reversible choice.

### Split the two UIs by their nature

**Decided**: reporting client-rendered, admin server-rendered.

- **Reporting** is genuinely interactive -- periods, segments, drill-down -- and
  is **client-rendered against the API**.
- **Admin** is forms and CRUD across ~23 controllers and stays **server-rendered**.
  Paying SPA complexity for a user-edit form helps nobody, and self-hosted
  administrators benefit from an admin that keeps working without a build step.

This is one application with a PHP shell, not two applications. Navigation,
authentication, the site selector and the period picker stay server-rendered for
both halves; reporting mounts into that shell rather than replacing it. So there
is one session, one navigation, one place a page is assembled -- and the
client-rendered part is a region of a page rather than a separate front end that
has to reimplement the furniture around it.

The consequence to accept deliberately: **two rendering paths coexist
permanently**, not transitionally. Someone will eventually propose unifying them.
The answer is that the split follows the work -- interactive analysis and CRUD
forms are different problems -- and that unifying costs a build step on the one
part of the system that most benefits from not having one.

### The actual problem is not the framework

The reporting UI's visual layer rests on abandoned jQuery plugins:

```
jquery.flot      0.8.3   last release 2013   <- all charting
jquery-sparkline         last release 2013
free-jqgrid      4.15.5  fork of a commercial product, ~2019   <- the data grid
chosen-js                ~2018
jquery + jquery-migrate  migrate present, so legacy patterns remain
```

Charts and the grid **are** a reporting UI, so replacing flot and jqgrid is the
substantive decision and the framework choice follows from it.

### Framework: Vue 3

For reasons specific to this project rather than general merit:

- **Incremental adoption is decisive.** 91 templates and 150 web actions make a
  big-bang rewrite a multi-year risk for a small team. Vue mounts into an
  existing server-rendered page, so report bodies can be replaced one at a time
  behind the current PHP shell. React can do this but its ecosystem assumes it
  owns the page.
- **It suits PHP-first contributors**: single-file components map onto how a
  page is already conceived, with a lower barrier than JSX plus toolchain.
- **Less pull toward what cannot be used.** React trends toward meta-frameworks
  and server components -- reasonable elsewhere, wrong for an app that must
  deploy as a static build onto shared hosting.

The honest argument for **React** is a larger contributor pool. It does not
outweigh the above, since OWA's contributors are PHP developers for whom neither
is native, and the lower barrier matters more than the larger pool.

**Lit / Web Components** deserves naming as a third option: no framework
lock-in, very small, and excellent longevity for a project that outlives
framework cycles -- at the cost of a thinner grid and chart ecosystem and more
manual state handling.

**The framework is the least consequential decision here.** API-first and
replacing the chart and grid layer are what matter; get those right and changing
framework later is contained.

### Retire the homegrown template engine

`Core/Template.php` is 1,107 lines, and its `extract()` behaviour turns a missing
variable into a runtime crash -- an undefined value reaching `foreach` fails on
PHP 8.2 -- for what should be an obvious authoring mistake. Worth noting a third
dialect already in the tree:

```
'There were <*= this.d.resultSet.aggregates.actions.formatted_value *> actions'
```

a custom interpolation syntax, inside PHP, addressing a JavaScript-shaped object
path. Evidence that the seam between server and client rendering is currently
drawn nowhere in particular.

## Authentication and permissions

### What is already right, and stays

Enforcement is centralised -- one `checkCapabilityAndAuthenticateUser()` before
every action in `Core/Controller.php` -- and permissions are **per site**, via
`isCapable( $capability, $siteId )`, with four roles (`admin`, `analyst`,
`viewer`, `everyone`) and a `capabilitiesThatRequireSiteAccess` list. A
client-rendered UI changes none of that, and it should not be redesigned.

Three authentication methods exist, chosen by which credentials arrive: session
cookies, `api_key`, and password login.

### Decisions

**The session is shared between the v1 and v2 UIs** -- one login serves both
during the overlap. **Existing API keys keep working** on their current path, so
nothing anyone has integrated breaks. If v2 wants a different key system it is
built **alongside**, not instead.

**Auth cookies may change, but no server-side sessions.** Statelessness is the
constraint; the current scheme is not.

### A defect to fix rather than inherit

```php
createCookie( 'u', user_id,                  +10 years );
createCookie( 'p', generateAuthCredential(), +2 days   );
```

`generateAuthCredential()` takes an `$expiration` argument and `saveCredentials()`
**passes none**, so the credential is an HMAC over `user_id . ''` with no expiry
inside it. The only expiry is the cookie attribute -- which the client enforces,
not the server -- and verification is `$hash === $passed_credential` with no time
check. A captured `p` cookie therefore stays valid indefinitely.

What bounds it today is that `substr( $password, 8, 4 )` is mixed into the key,
so changing a password invalidates outstanding credentials. That is a genuinely
good property, statelessly achieved, and worth keeping. It is simply the only
one.

### The v2 shape, still stateless

- **one signed token instead of two cookies**: user id, issued-at and expiry,
  HMAC'd with the server secret -- fewer bytes per request, one thing to
  validate, and no plaintext user id persisting for ten years after logout
- **expiry inside the signature**, so the server enforces it rather than trusting
  a cookie attribute; sliding renewal on use keeps it convenient
- **keep the password linkage**, which gives logout-everywhere-on-password-change
  for nothing
- **optionally a `token_epoch` on the user row**, included in the HMAC, so
  bumping it revokes outstanding tokens. That is not a session store -- it is one
  value on a row already being loaded

### Additive to 1.x

- **Scoped tokens** -- site-scoped, read-only, expiring -- beside the existing
  keys rather than replacing them. 1.x users gain a safe credential for a
  dashboard integration immediately; v2 gains the credential model an API-first
  product needs.
- **A "describe my permissions" endpoint.** A client-rendered UI must know which
  sites it may show before rendering a site picker, and 1.x's own JS can use it
  too.

### The nonce stays

An earlier draft proposed replacing it with `SameSite` plus an `Origin` check.
That is wrong for this product, for two reasons.

**`SameSite` is site-scoped, not origin-scoped.** It compares registrable
domains, so `evil.example.com` is same-site as `analytics.example.com` and the
cookie is sent. That matters wherever OWA shares a registrable domain with
software it does not control -- an install at `analytics.example.com` beside the
site it measures, or anything on shared hosting -- which is precisely the
attacker `SameSite` does not stop. It is a property of sharing a domain, not of
any particular install method.

**`Strict` breaks ordinary navigation**, since following a link in from an email
arrives logged out, so in practice it would be `Lax` -- which permits top-level
GETs and is therefore only safe if no GET changes state. The REST routes are
registered per method and the nonce check applies regardless of method, so
removing it would rest on a property of the API that has not been established.

The `Origin` check is still worth having, precisely because it *is* origin-scoped
where `SameSite` is not. But `Origin` is sometimes absent on same-origin
requests, so its absence has to mean something, and treating absence as
acceptable is a hole.

The real motivation for removing it was friction rather than security: a
long-lived client application cannot embed a fresh nonce per render. That is
solved without giving anything up -- **issue a token at application load**, in
the same bootstrap response that describes the caller's permissions, and refresh
it on expiry or transparently on a 403 retry.

That also removes the per-path divergence the earlier draft introduced. Both
UIs use the same enforcement, which is one fewer thing to get wrong.

**So: keep the nonce, and add `SameSite=Lax` and the `Origin` check as defence
in depth** -- three inexpensive mechanisms rather than trading a proven one for
two conditional ones.

## Privacy, retention and erasure

The weakest area measured, and the one where OWA's positioning and its behaviour
diverge most. Self-hosting is the privacy story -- the data never reaches
Google -- but self-hosting is not by itself a privacy *feature*, and everything
below is about what the software does with data it holds.

### What exists today

**IP anonymisation exists and is off.** `anonymize_ips` defaults to `false`, and
`Lib::anonymizeIp()` masks the final IPv4 octet (`255.255.255.0`) or the last 64
bits of IPv6 -- a reasonable implementation, simply not enabled. On the
installation measured, **691,680 full IP addresses** are stored.

**Identifiable data is spread across seven tables**, which is what makes erasure
hard rather than merely absent:

```
owa_request      visitor_id, inbound_visitor_id, user_name, user_email, ip_address
owa_session      visitor_id, user_name, user_email, ip_address
owa_click        visitor_id, ip_address, user_name
owa_domstream    visitor_id, ip_address, user_name
owa_visitor      user_name, user_email
owa_host         ip_address, full_host
owa_commerce_transaction_fact  visitor_id, ip_address, user_name
```

**There is no erasure path at all.** Of 23 registered CLI commands, none deletes
a person's data -- `partition-drop` prunes by date, which is retention, not
erasure. Answering "delete everything you hold about this visitor" today means
hand-written SQL across seven tables.

**There is no retention policy**, only the manual `partition-drop`. Nothing
expires by default, and `partition-rotate` deliberately ships with no `keep`, so
an untouched installation keeps everything forever.

**There is no consent or Do Not Track handling** of any kind.

### What v2 changes, for better and worse

**Better: erasure becomes tractable.** Seven tables become one event table plus
the recording payloads and any rollup. "Delete everything about this visitor" is
a delete by `visitor_id` in one place, a delete of the recordings referenced by
those events, and a rollup rebuild for the affected sessions. That is a command
someone can actually write and test, which the current shape is not.

**Worse: denormalisation multiplies what erasure must reach.** A referrer URL
carrying a query string, a page URL with an email in it, a custom variable
holding a user id -- in the star schema those sit in one dimension row; in the
event model they are on every event that referenced them. Erasure has to scan,
not look up.

**And recordings need explicit handling.** A domstream is pointer telemetry with
no page content, which is a genuine privacy advantage over DOM-recording tools
and worth keeping deliberately -- but it is still behavioural data tied to a
visitor, and must be deleted with them.

### What v2 should do

1. **Anonymise by default.** The implementation exists; the default is wrong. A
   privacy-positioned product should ship privacy-preserving and let an
   administrator opt out, not the reverse.
2. **An installation-wide retention policy**, enforced by a scheduled job, with a
   sane default rather than "forever". `partition-drop` already does the
   mechanical part; what is missing is a policy that runs without being asked.

   **Deliberately not per site.** Retention that varies by site cannot be served
   by dropping a partition, because a partition of the shared event table holds
   every site's rows -- it would need `DELETE ... WHERE site_id = ? AND yyyymmdd
   < ?`, a row-level delete sweeping every partition, leaving fragmentation
   behind and competing with live writes. Installation-wide retention is a
   partition drop: metadata-only, effectively instant, and already implemented.
   The single event table makes uniform retention cheap and varying retention
   expensive, so the simpler policy is also the one the storage model wants.
3. **An erasure command** -- `forget visitor=<id>` -- deleting across events,
   recording payloads and rollups, and **tested**, because an erasure path that
   silently misses a table is worse than none. For declared PII specifically, see
   the crypto-shredding subsection under visitor identity: destroying a
   per-subject key reaches backups that a `DELETE` cannot.
4. **Separate retention from erasure in the design.** Retention is time-based and
   bulk; erasure is subject-based and exact. They share mechanics and must not
   share a code path, or one will quietly satisfy neither.
5. **Honour Do Not Track and a consent signal**, configurable, and record the
   decision rather than inferring it later.
6. **Decide what a domstream is** in policy terms and document it, since a
   session replay is the most sensitive thing OWA collects even without page
   content.

### Additive to 1.x

Most of it. Anonymisation-by-default is a settings change plus a release note.
A retention job is a scheduled job over the existing `partition-drop`. An
erasure command in 1.x has to cross seven tables rather than one, so that work
does not carry over -- but the **command surface, the tests and the semantics do**,
and having them defined against the harder schema first is a reasonable way to
prove them.

## The API as the contract

Once reporting is client-rendered, the API stops being a side door and becomes
the product's interface. Three things follow: it has to be complete enough to
build a UI on, stable enough to build integrations on, and versioned so that
neither commitment blocks the other.

### What already exists, and is better than the route count suggests

```
v1  domstreams  GET
v1  sites       GET, POST
v1  users       GET, POST, DELETE
v1  siteUsers   POST
v1  reports     GET
```

Eight routes, but `reports` is the general-purpose one -- it accepts `metrics`,
`dimensions`, `segment`, `period`, `startDate`, `endDate`, `sort`, `siteId`,
`report_name` -- so the query interface the whole reporting UI needs is already
there in outline, and already capability-gated on `view_reports`.

Two foundations worth naming because they mean less new mechanism than expected:

- **Routes are keyed by version, name and method**, so a `v2` namespace can be
  registered beside `v1` and both serve simultaneously. The API supports the
  same coexistence the schema strategy relies on, natively and today.
- **Pagination is modelled already** -- `PaginatedResultSet` carries `page`,
  `resultsPerPage`, `total_pages`, a total row count and a `more` flag.

### What is missing

**Discovery is present, and better designed than a catalogue would be.** It is
not a separate endpoint -- it is embedded in every response. While resolving a
query, `ResultSetManager` tests each dimension against the chosen base entity
with `isDimensionRelated()`, collects the compatible ones through
`setRelatedDimension()`, and `getAllRelatedDimensions()` attaches the list to the
result set. `resultSetExplorer.js` reads `resultSet.relatedDimensions` to fill
its picker, with `setExclusions()` removing those already in use.

That is **contextual discovery** -- "what may I add to *this* query" -- rather
than a static catalogue, and it is the right shape, because validity is a
property of the combination and not of a dimension on its own. A flat list of
metrics and dimensions would be less useful and more misleading.

So the v2 requirement is not to build discovery but to **preserve it**, and that
is harder than it sounds. Today the answer falls out of entity resolution: a
dimension is offered because some fact table can join it. A single event table
has no such structure, so the same list has to come from the metric and
dimension registry enforcing the additivity rules explicitly -- the point made
above about `bounceRate x pagePath`, seen from the other side. The registry must
be able to answer "what is compatible with this selection" as a first-class
question, not merely refuse an illegal request.

What is genuinely missing alongside it is a **static catalogue for the empty
case** -- before any query exists, a client still has to offer a starting set --
and a machine-readable expression of the rules for integrators building queries
outside the UI.

**An error taxonomy.** Responses carry `status_code` and an `error` message
string. A client needs codes it can act on -- an illegal metric and dimension
combination is a different thing from an expired token or a rate limit, and only
one of them is worth retrying.

**A permissions endpoint.** The client must know which sites it may show before
rendering a site picker, and which capabilities the caller holds before offering
actions it cannot perform.

**Report and widget definitions**, served from the same place they are stored,
per the UI section.

**Rate limiting**, once scoped tokens exist and the API is a supported
integration surface rather than an internal detail.

### Query shape

The existing style is a compact URL DSL -- `metrics=a,b&dimensions=c,d&constraints=...`
-- which is cacheable, loggable and easy to hand-write. It should stay for
ordinary queries. What it will not carry is a complex segment expression, so
**POST with a JSON body** belongs alongside it for those, with identical
semantics. Two encodings of one query language, not two query languages.

### Versioning and stability

The route registry already gives the mechanism. What is needed is the
**commitment**: `v1` is frozen when `v2` opens, receiving fixes but no changes,
so existing integrations keep working across the whole coexistence period. That
is the same bargain the schema strategy makes -- nothing is taken away until the
administrator chooses to remove it.

### Additive to 1.x

Most of it, and unusually cleanly, because the API is the one layer the schema
change does not reshape -- the same questions get asked of different storage.

- **discovery, permissions and error codes** can ship in 1.x and be inherited
  whole; 1.x's own JS benefits from all three
- **the combination rules** are already computed in 1.x by entity resolution and
  returned as `relatedDimensions`; what is additive is expressing them in the
  registry directly, so the same answers survive the loss of the entity graph
- **scoped tokens and rate limiting** pair with the auth work already described

What cannot carry over is anything shaped by the star schema itself -- the
`report_name` indirection, and any endpoint whose response embeds the six-table
structure.

## Bot filtering

The largest single lever on data quality, and the weakest implementation
measured.

### How it works today

`robotRegexCheck()` is a case-insensitive substring match against 17 hardcoded
tokens:

```
bot, crawl, spider, curl, host, localhost, java, libcurl, libwww,
lwp, perl, php, wget, search, slurp, robot, WordPress.com mShots
```

Matched traffic is discarded before logging unless `log_robots` is set. On the
installation measured, 17,763 of 325,836 sessions are flagged -- 5.5%, against a
web where automated traffic is routinely a third or more of raw hits.

### A defect, verified

```php
$match = stripos( $this->ua, $robot );
if ( $match ) { break; }
```

**`stripos()` returns `0` for a match at position 0, and `if ( $match )` treats
that as false.** Any user agent that *begins* with a robot token is therefore
missed:

```
curl/7.68.0                   ->  not detected
Wget/1.21.3                   ->  not detected
Java/17.0.1                   ->  not detected
python-requests/2.31.0 curl   ->  detected  (match at position 23)
```

The same token is caught mid-string and missed at the start -- and `curl`,
`wget`, `java`, `php`, `perl`, `lwp` and `libwww` are precisely the tokens that
lead a user agent, so the misses concentrate on the most unambiguously
non-human traffic. A one-character fix (`!== false`) closes it.

### The parser already installed does this better

`Browscap` is a legacy name: the class wraps `UAParser\Parser`, and
**`ua-parser/uap-php ^3.9` is already a declared dependency**, already parsing
every user agent. `isRobot()` ignores the parsed result and runs the substring
list instead.

The parsed output is both more accurate and maintained upstream:

```
Googlebot     ->  device=Spider   ua=Googlebot
AhrefsBot     ->  device=Spider   ua=AhrefsBot
GPTBot        ->  device=Spider   ua=GPTBot
Java/17.0.1   ->  device=Spider   ua=Java          <- the substring list misses this
curl/7.68.0   ->  device=Other    ua=curl
Wget/1.21.3   ->  device=Other    ua=Wget
python-req.   ->  device=Other    ua=Python Requests
```

So crawlers are `device.family === 'Spider'`, and automation tools are exact
matches on `ua.family` -- `curl`, `Wget`, `Python Requests`, `Java`. Matching
parsed fields rather than raw substrings removes the position-zero bug and the
`search`-matches-"Researcher" class of false positive in one change, and moves
crawler coverage onto a list somebody else maintains.

Two weaknesses remain regardless: the substring list is hardcoded with no update
path, and detection at collection is final -- which is **decided**, not merely
current. Retroactive reclassification is too expensive; a crawler recognised
later stays misclassified.

### What GA does, and where it stops

GA4 filters against the IAB/ABC International Spiders and Bots List plus its own
research -- **always on, no toggle, no tuning, and no report of what was
removed**. They reached the same conclusion: preemptive filtering is not a
contest worth exposing, so it is invisible and unconfigurable. They also do not
solve Measurement Protocol spam, because fabricated hits posted straight to the
endpoint carry no user agent to inspect.

Separately, GA offers an **unwanted referrals list**, which does not discard
sessions -- it reclassifies the traffic source. The property that matters is
that it is applied **at reporting time from a list**, so changing the list
changes all history.

### Two v2 features

**1. Detection from parsed output.** Replace the substring list with
`device.family === 'Spider'` plus exact `ua.family` matches for automation
tools. Confidently-identified traffic is still discarded at collection.

**2. A reporting-time exclusion list**, covering user agent, IP, or a
combination.

The second is cheaper than it appears, because of what it actually filters.
Traffic discarded at collection is what was identified *confidently*. Everything
ambiguous -- a datacentre IP behind a plausible Chrome string, a headless
browser, an unrecognised scraper -- **is already stored and already counted as
human**. So an exclusion list costs no additional storage; it gives users a way
to remove what they later recognise, from data being kept regardless.

| tier | mechanism | storage cost | retroactive |
|---|---|---|---|
| confident bots | parsed output, discarded at collection | none | no |
| everything else | exclusion list at reporting time | none extra | **yes** |

Both UA and IP are on the event, so both are filterable, as are combinations --
"this user agent from this /24". With IP anonymisation enabled, exclusion is
limited to /24 granularity, which is close to how datacentre ranges are
allocated in any case.

**This is not the losing battle.** Chasing bots preemptively is unwinnable,
which is why the counter idea was dropped. An exclusion list is the opposite
posture: **reactive and user-driven**. Nobody maintains it in anticipation. A
user sees junk in a report, excludes it, and history corrects. The list stays
small because it only ever holds what somebody actually noticed.

Implementation caveat: a `NOT IN` per query is fine for a small list and poor for
a large one. Past a few dozen entries it wants materialising into the sessions
rollup -- which is also the natural place to apply it, since users mean
"exclude that visitor's sessions" rather than "exclude these individual events".

Both features belong to **v2**. The parsed-output change would be additive in
principle, but detection and exclusion are two halves of one design and are
better shipped together.

## Visitor identity

Identity is the one thing a single event table cannot derive. Every other
dimension in v2 is content-derived -- the id *is* a function of the value -- but
a visitor is an arbitrary identity with nothing to hash. It has to be assigned,
carried, and trusted, which makes it the most fragile column in the schema and
the one worth being explicit about.

### What 1.x does

The tracker assigns the id client-side and stores it in the `v` state store as
`vid`, alongside `fsts` (first-session timestamp), `nps` (prior session count)
and `dsfs` (days since first session). `owa_visitor` is a dimension row holding
those same history fields plus `user_name` and `user_email`, both gated behind
the `log_visitor_pii` setting.

Two things are worth knowing before carrying any of it forward.

**The id has ~30 bits of entropy, not 63.** `Util.generateRandomGuid()` returns a
19-digit decimal string built as a 10-digit unix timestamp followed by a 6-digit
and a 3-digit random number. It looks wide because it is a BIGINT, but within a
given second the space is only `10^9`:

| new visitors/sec | expected id collisions/year |
|---|---|
| 10 | 1.4 |
| 100 | 156 |
| 1,000 | 15,752 |

A collision merges two people into one visitor -- silently, and permanently.
Worth stating plainly, though: **GA is not meaningfully better here.** Its `_ga`
cookie is `GA1.1.<random>.<timestamp>` with roughly 31 bits in the random field,
the same construction with one extra bit. This is a defect to fix on the merits,
not a competitive gap, and the fix is free -- widen the random component, since
nothing depends on the field widths.

**The site salt is silently discarded.** All four call sites pass one --
`Util.generateRandomGuid( this.siteId )` -- and the function is declared
`generateRandomGuid ()`, taking no parameters. The third component is even named
`client`, which says what it was meant to be, and is a random 3-digit number
instead. Impact today is low, because per-site cookie stores keep the collision
domain per-site anyway, but it is a dead parameter that reads as a working one.

### A single table changes where history lives, not how it is assigned

`owa_visitor`'s first/last-session columns are the same problem as `owa_session`,
and take the same answer: what only the client knows travels on the event, and
what needs global knowledge goes in a rollup. 1.x already does the first half --
`fsts`, `nps` and `dsfs` are carried in the cookie today -- so this is largely
inherited rather than designed.

**But `is_new_visitor` must stop being a marker.** It is currently a flag set on
one event, which is precisely the shape the session work rejected: *never make a
record's existence depend on a single packet*. Lose that beacon and the visitor
is a returning visitor forever, with no way to notice.

The fix falls out of what is already carried. `fsts` is on **every** event, so
new-visitor is derivable -- the visitor is new when `fsts` falls inside the
current session -- and the derivation survives any single event being lost. Same
principle as the sticky `engaged` flag, applied to the other identity that 1.x
signals with a one-shot.

### Known users are resolved at collection time only

`user_name` and `user_email` become event columns rather than a dimension row.
The rule that matters is when they attach: **from the moment the site declares
them, forward only.** Events collected before the visitor identified themselves
stay anonymous and are never revisited.

This is the same answer already given for goals and for bot reclassification, and
it should be given for the same reason rather than a new one -- retroactive
rewriting of history is expensive, and in a single event table it means updating
every prior row for that visitor. GA4 behaves this way too: `user_id` applies
going forward, and identity stitching is a reporting-time concern in BigQuery,
not something collection does.

**Cross-device follows from this and nothing else.** Two devices are the same
person only when the site tells OWA so by supplying the same user id. v2 makes no
attempt at probabilistic matching, fingerprinting, or IP-and-user-agent
heuristics. That is a privacy position, but it is also a correctness one: a
probabilistic join produces a number nobody can check, and analytics whose
numbers cannot be checked is the thing this whole document is arguing against.

### PII belongs beside the events, not on them

Here the single table is genuinely worse, and it is the same shape as the
enrichment finding measured earlier. An email address on a dimension row exists
once; an email address on an event row exists once **per event**. Erasing it then
means rewriting millions of rows rather than one, and the privacy section's
"denormalisation multiplies what erasure must reach" applies with the sharpest
possible edge, because this is the field most likely to be the subject of an
actual erasure request.

So: **the event carries a pseudonym** -- a hash of the declared user id -- and
the mapping from pseudonym to real name and email lives in one small table beside
the events.

To be unambiguous, because this document has rejected exactly this shape
elsewhere: **that table is never joined in a reporting query.** Aggregations
group by the pseudonym and never need the plaintext. The mapping is consulted
only to display one specific person, or to erase them -- single-row lookups, not
a join in the scan path. The objection to a lookup table for search engines was
that it put a join in the hot path of every report; this puts nothing there.

The payoff is that identity erasure stops being a scan. Removing one subject's
entry leaves every event carrying that pseudonym permanently anonymous without
touching the event table at all. Behavioural erasure by `visitor_id` still scans,
exactly as the privacy section describes -- but the PII half, which is the part
with a legal clock attached, does not. The next subsection makes that removal
stronger still.

### Crypto-shredding: proposed, NOT settled

**Status: recorded for evaluation, not decided.** A candidate erasure primitive --
**encrypt the PII and, when erasure is requested, destroy the key**. It is an
established technique (cryptographic erasure) and the instinct is sound, but
several of its load-bearing assumptions have not been tested and at least two
look shaky for a self-hosted install. The mechanics below are what the design
*would* be; the open questions after them are what has to be answered first.

**It cannot go on the event row, for a reason unrelated to cryptography.**
Reporting has to group by identity, and semantically secure encryption is
randomised: the same email encrypts to different ciphertext every time, so
`GROUP BY` is impossible. Making it groupable means deterministic encryption,
which leaks equality across the whole table and opens frequency analysis -- and a
deterministic ciphertext used as a grouping key *is* a keyed hash, only
reversible. So the event would need a stable pseudonym **and** the ciphertext,
with the pseudonym doing all the reporting work and the ciphertext repeated on
every row for nothing. That is the denormalisation cost this design set out to
avoid.

**So the pseudonym stays on the event, and the mapping table holds ciphertext
instead of plaintext.** Erasure destroys that subject's key. Nothing on the event
table changes, and nothing is rewritten.

**The real advantage is backups, and it is a large one.** Deleting a mapping row
does not erase the copies you no longer control -- nightly dumps, a replica, an
export someone took last quarter. Destroying a key makes every one of those
unreadable too, including the ones you have forgotten about. That is a
qualitatively stronger erasure guarantee than a `DELETE`, and it is the argument
that makes this worth the machinery.

**Two keys, with deliberately different lifetimes.** Conflating them breaks
either grouping or erasure:

| key | scope | lifetime |
|---|---|---|
| pseudonym HMAC key | install-wide | stable; rotating it re-keys every visitor and breaks grouping continuity |
| PII encryption key | **per subject** | destroyed on erasure |

The pseudonym must be an **HMAC, not a bare hash** -- `sha256(email)` is trivially
reversible by dictionary, since the email space is guessable, so an unkeyed hash
on the event table is PII wearing a costume.

**Three caveats, all of which have to be honoured or the guarantee is theatre.**

1. **Keys must be independently random and stored, never derived.** A key
   computed as `KDF(master_secret, subject_id)` cannot be destroyed -- it is
   recomputable from material that still exists. This is the mistake that turns
   crypto-shredding into a no-op.
2. **The key store inherits the backup problem in miniature.** Restore a
   month-old key-store backup and the destroyed key returns. The key store needs
   its own backup and retention policy, narrower than the database's, and that
   policy is part of the feature rather than an operational detail.
3. **It covers declared PII only.** An email in a page URL's query string, in a
   referrer, or in a custom variable is plaintext on the event and denormalised
   across every row that saw it. The privacy section's "erasure has to scan, not
   look up" still applies to all of it. Crypto-shredding shrinks the erasure
   problem to its most legally-loaded field; it does not eliminate it.

**New machinery, but not much.** There is no encryption anywhere in the tree
today -- no `openssl_encrypt`, no `sodium_crypto` -- so this is genuinely new,
though libsodium has been in PHP core since 7.2 and the operation is one
authenticated-encryption call in each direction. `OWA_SECRET`, `OWA_AUTH_KEY` and
`OWA_NONCE_KEY` establish the config precedent for the install-wide HMAC key; the
per-subject keys need a table, because there is one per subject and they must be
individually destroyable.

### What has to be evaluated before committing to it

**1. Whether the field is worth protecting at all -- measured, and the answer is
unclear.** `log_visitor_pii` defaults to `true`, so PII capture is *on* out of the
box. But across two live installs holding 135,798 visitor rows between them,
**three values resemble an email address**. Everything else is the `(not set)`
sentinel:

| install | rows with a value | distinct values | resembling an email |
|---|---|---|---|
| A | 73,045 | 1 | 0 |
| B | 62,753 | 2 | 3 |

This is the same pattern found repeatedly in this codebase -- a feature present in
the schema, enabled by default, and not actually carrying data. It does not settle
the question, because a v2 that made identity useful might change the usage, but
it does mean the machinery would currently be protecting an empty field, and that
the more urgent defect is 135,795 rows of sentinel masquerading as data.

**2. The backup advantage may not survive a self-hosted deployment.** The argument
above rests on ciphertext and keys being backed up separately. A typical OWA
install is one MySQL database captured by one `mysqldump` -- which would contain
the ciphertext *and* the keys, making every backup fully readable and the
advantage zero. It only materialises if the key store lives outside the primary
database with its own regime, which is a real operational ask for a single-box
install. **This needs answering first, because it is the argument the whole idea
rests on.**

**3. Legal standing is genuinely unsettled.** Encrypted personal data is generally
still personal data while a key exists anywhere; whether destroying the key
constitutes erasure or merely strong pseudonymisation is read differently by
different authorities. Claiming GDPR erasure via crypto-shredding is a position to
take deliberately with advice, not a technical conclusion to reach from a design
document.

**4. Key loss becomes data loss.** A corrupted or lost key store destroys PII for
every subject at once, permanently. A plaintext mapping table has no equivalent
failure mode, and self-hosted operators lose things.

**5. The alternative nobody has costed: do not hold PII at all.** Store only an
opaque, site-supplied user id -- never a name or an email -- as GA4 does with
`user_id`. The site already holds the mapping and is already the data controller,
so erasure becomes their operation on their system, and OWA has nothing to
encrypt, no keys to manage, no key store to back up, and no legal position to
take. Given finding 1, this may simply dominate: it is less code than
crypto-shredding *and* less code than the plaintext mapping table, and the cost is
a feature that measurably nobody uses.

That alternative should be evaluated head-to-head against crypto-shredding before
either is built. They are not variations on a theme -- one is "protect the PII
well" and the other is "stop being a PII processor" -- and the second is a
stronger privacy position for a product that markets itself on privacy.

**6. Smaller, still open**: crypto agility over a decade of ciphertext; the cost
of bulk decryption when a report lists many identified users; and whether key
destruction should be synchronous with the erasure request or a scheduled job.

### Rejected

- **Server-assigned visitor ids.** Better entropy and not spoofable, but it needs
  the server to set a cookie on a response the tracker treats as fire-and-forget,
  and it breaks the no-DB logging node. GA does not do this either. Widening the
  client-side random component gets most of the benefit for none of the cost.
- **Deriving the visitor id from the declared user id**, so identity is
  content-derived like every other dimension. It would retroactively re-key
  everything the moment someone logs in, which is the retroactive rewrite this
  section exists to avoid, and it would leak the user id into the id space.
- **A visitor rollup mirroring the session rollup.** Not yet -- `fsts`, `nps` and
  `dsfs` already answer the questions 1.x asks of `owa_visitor`, and a rollup
  should be added when a metric actually needs one rather than by symmetry.

## Attribution

### How it works today

Attribution is decided **in the browser**. `setTrafficAttribution()` reads the
campaign state from the `c` cookie and applies one of two models chosen by the
`trafficAttributionMode` setting:

```
direct    last touch   (the default)
original  first touch
```

The verdict is written to `owa_session.latest_attributions` -- a BLOB -- and
flattened onto every fact row as `medium`, `source_id`, `campaign_id` and
`ad_id`. The window is `campaignAttributionWindow`, nominally 60 days.

### Four problems

**The 60-day window does not work.** `StateManager.set()` overwrites its own
`expiration_days` argument, so the `c` store is written as a browser-session
cookie. First-touch attribution therefore cannot survive a browser restart, and
the setting has no effect. The fix belongs with the tracker state work already
described.

**The model is frozen at collection.** `trafficAttributionMode` is a tracker
setting, so changing it affects only future traffic. An installation cannot
compare last-touch against first-touch, or correct a choice made a year ago --
it gets whichever model was configured at the time, permanently.

**`latest_attributions` is an opaque BLOB** on the session: not queryable, not
segmentable, not usable as a dimension.

**The decision is made by an untrusted client.** The browser decides which touch
gets credit and the server records the verdict.

### What v2 should do: store touches, attribute at query time

The touchpoints are **already on every event** -- medium, source, campaign, ad
are flattened onto each fact row today and would be columns on the event in v2.
So the model does not need deciding at collection at all: store what was
observed, and apply a model when reporting.

That yields three things the current design cannot:

- **changing the model re-attributes history**, because the raw touches are
  intact -- which is how GA4 behaves and is the property users actually expect
  from an attribution setting
- **models can be compared** side by side rather than chosen blind
- **the client stops deciding**, and simply reports the campaign parameters it
  observed

**This does not contradict the no-post-hoc-enrichment decision.** That decision
was about data fetched from outside after the fact -- crawled referer titles, a
re-run geo lookup. Attribution is *derived from events already stored*, which is
computation rather than enrichment. Retroactivity is free here in a way it is
not there.

**Where the work lands: the sessions rollup.** Last-touch is the session's
opening campaign, which the rollup carries anyway. First-touch across sessions is
the visitor's earliest campaign-bearing event inside the window -- derivable,
since `visitor_id` and the campaign columns are both on events, but a lookup
across history rather than within one session.

So the rollup stores **both attributions per session**, computed by the scheduled
job, and a report selects which to group by. Query-time model choice then costs
nothing, and adding a third model later means adding a column and rebuilding --
possible precisely because the rollup is derived rather than authoritative.

The tracker's job shrinks accordingly: capture and persist the campaign
parameters it sees, and stop deciding what they mean.

## Search engine classification

Already server-side, and staying there. `TrackingEventHelpers::isSearchEngine()`
matches the referrer host against a 46-entry list from `conf/searchengines.php`,
and `RefererHandlers` sets `is_searchengine`. The tracker's `owa_search_terms`
is a campaign parameter a site may set explicitly, not detection.

**Classification happens at ingestion, and the result is stored on the event.**
No lookup join at query time.

That was considered and rejected. A join would make the classification
retroactive -- add an engine, and history reclassifies -- but it reintroduces
precisely what the single-table design removes, on a column (`medium`) that
appears in a large share of reports. The measured gain from dropping dimension
joins was substantial: browser breakdown went from 418.9 ms to 144.3 ms for that
reason alone. Joins are also markedly more expensive on the columnar backends
this design exists to keep available, and "it is only 46 rows" is how a schema
reacquires joins one at a time.

It would also be inconsistent. Retroactive **bot** reclassification was rejected
as too expensive, and a new search engine appearing is rarer than a new bot while
mattering less. The same trade should be taken in both places: classify once at
collection, and accept that a list change affects future traffic only.

**The one fix worth making** costs nothing at query time. The current test is
`stripos( $host, $domain ) !== false`, so any host merely *containing* an
engine's domain is classified as organic search -- `notgoogle.com.evil.net`
included. An exact host-suffix match, applied where the check already happens,
corrects it.

Note this leaves the **referrer exclusion list** from the bot section untouched:
that is `WHERE referrer_host NOT IN (...)` over a short user-managed list, or
materialised into the sessions rollup. A predicate, not a join, and no per-row
derivation.

## Goals

### How they work today

Three goal types, evaluated by `ConversionHandlers` at collection:
`url_destination`, `pages_per_visit`, `visit_duration`. A match writes into
`owa_session`'s dedicated columns -- `goal_N`, `goal_N_start`, `goal_N_value`
for N in 1..15, which is 45 of that table's 121 columns -- and `numGoals` is
fixed at 15.

### A live bug

```php
case 'pages_per_visit':
    $match = $this->checkPagesPerVisitGoal( $event, $goal );   // correct
case 'visit_duration':
    $match = $this->checkPagesPerVisitGoal( $event, $goal );   // wrong function
```

`checkVisitDurationGoal()` exists and is **never called from anywhere**. Every
visit-duration goal is evaluated against pages-per-visit criteria, so a goal
configured as "stayed longer than 120 seconds" fires on "viewed more than 120
pages" and therefore never converts. Nothing catches it because both functions
exist and both return a boolean.

### Three structural problems

**Goals are frozen at collection.** The evaluation happens once, and the result
is stored. Define a goal today and yesterday's traffic has not met it -- not
because it did not, but because nobody asked. Changing a goal's definition
likewise leaves history describing the old one, with no record of which
definition a stored conversion refers to.

**The cap is structural, not configurable.** Fifteen goals is 45 columns; a
sixteenth is a schema change, an update class and a migration.

**The goal *number* is the identity.** Reports address `goal3Completions`, so
repurposing goal 3 silently rewrites the meaning of its history.

### What v2 should do: mark at collection, on named criteria

A goal is a **stored definition matched as events arrive**, and the match is
recorded on the event. Not evaluated at reporting time.

Query-time evaluation was considered and rejected. It would make goals
retroactive, but every report would then evaluate N goal predicates over the
events it scans, and counting conversions by goal needs a branch per goal.
Marking once at ingestion makes the same question a single indexed predicate.
It is also what GA4 does, from more experience than anyone: key events are
matched as they arrive and are explicitly **not retroactive** -- mark an event
today and the preceding months read zero, though the event was firing
throughout. Users accept that, and it is consistent with the decisions taken for
bots and for post-hoc enrichment: **decide at collection.**

What the redesign is actually worth, then, is not retroactivity:

- **unlimited goals.** Fifteen is 45 columns today; a sixteenth is a schema
  change. Recording the matched goal's **name** on the event -- a parameter, or a
  small side table -- removes the cap entirely.
- **stable identity.** The goal *number* is the identity today, so repurposing
  goal 3 silently rewrites the meaning of its history. A named definition cannot
  do that: an edited goal is visibly a different thing.
- **definitions become data**, editable and inspectable, rather than 45 columns
  and a `numGoals` constant.

The rule is simply that **matching applies going forward**. Define a goal and
future events are marked; delete or edit it and future events are not, or are
marked differently. Past marks stay exactly as they were.

No definition versioning, no snapshot of criteria on the event. **The mark is an
observation, not a derivation** -- a record of what was determined at the moment
the event arrived, like the event's timestamp or its URL. Nobody versions those
either. A report may therefore show conversions for a goal that no longer
exists, which is correct: it describes what happened, not what is currently
configured.

### Where the cost lands

With matching at ingestion, most goal reporting is a filtered count -- the same
shape as any other event query.

The awkward remainder is **goal rates** -- `goalConversionRateAll`,
`goalAbandonRateAll` -- which divide conversions by sessions and therefore need
per-session aggregation. Since the match is already on the event, the sessions
rollup can carry a conversion count per goal, computed by the same job that
builds it. Adding a goal means later sessions carry it and earlier ones do not,
which is the retroactivity trade restated rather than a new problem.

**Funnels stay code.** `ReportGoalFunnel` computes step-to-step progression,
which is not a predicate and should remain a widget type rather than distort the
definition format.

### Additive to 1.x

The `visit_duration` dispatch bug is a one-line fix and belongs in 1.x now --
it is live on every installation with a duration goal configured, and those
goals have never converted.

The rest is not additive: goals-as-definitions requires the query-time model,
and 1.x's 45 columns are the schema.

## Multi-site

### What exists, and mostly survives untouched

```
owa_site: id, site_id, domain, name, description, site_family, settings, id_1_3
```

`site_id` is the hash string pasted into tracking code; `id` is derived from it.
Isolation is a `site_id` predicate, permissions are already per site via
`isCapable( $capability, $siteId )`, and per-site configuration lives in the
`settings` blob, read through `getSiteSetting()` -- `enableEcommerce` and
similar.

This is the part of the design that needs the least work, because a single event
table handles multi-tenancy naturally: `site_id` is a column, isolation is a
`WHERE`, and it is already the leading column of the event indexes
(`site_date`, `site_type_date`). The star schema's multi-site model was never
the problem.

### What v2 changes

**`site_id` becomes more load-bearing, and must stay immutable.** It is already
hardcoded in every tracking snippet in the wild, so it cannot change. In v2 it
additionally scopes page identity -- `hash( site_id + uri )` -- and leads every
index. That is fine, but it makes the constraint absolute: an installation
cannot rekey a site without orphaning both its data and its deployed snippets.

**Per-site retention becomes a requirement**, per the privacy section. Retention
is a site-level policy, so it belongs in the site's settings and is enforced by
the scheduled job rather than applied globally.

**Two vestigial columns go.** `site_family` is written by `createNewSite()` and
`createDefaultSite()` and **never read anywhere** -- another entry in the
declared-but-unused list alongside `is_assigned` and `dom_element_parent_id`.
`id_1_3` is a migration artefact.

**The site settings blob inherits the falsy-write defect.** It is the same
mechanism as global settings, so a per-site boolean cannot be stored as `false`
-- only as absent. Worth fixing where the storage format is being revisited
anyway.

### What is absent and might be wanted

There is **no cross-site reporting**. `getSitesList()` serves the site picker,
not aggregation, so an installation running twenty sites cannot ask a question
spanning them.

The single table makes that nearly free -- dropping the `site_id` predicate, or
widening it to a set, is all it takes, and the index already leads with
`site_id` so a multi-site range is a natural scan. In the star schema the same
question is equally possible but the answer has to be assembled per site.

It is not required for parity, and should be judged on whether anyone wants it
rather than because it became cheap. The permission model already answers the
hard part: a cross-site query is legitimate only over the sites the caller is
capable on, which `isCapable` already knows per site.

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

## Reporting freshness

1.x has one freshness and never has to explain it. `owa_session` is written by an
event handler chained off `page_request_logged` (`Module.php:2424`), in the same
request that logged the pageview, so session metrics are as current as event
metrics -- both are simply "now". Nothing in the product has ever had to say when
a number was computed.

v2 gives that up on one path. The session model settled in Addendum 3 derives
sessions from an incrementally-maintained rollup built by a scheduler job, so a
session metric is current as of the last time that job ran. Peter's constraint:
delay in summary-derived data is acceptable **provided it is eventually
consistent**. That is the right trade, but eventual consistency is a property
that has to be built. Delay alone does not produce it -- a summary that is merely
late converges on the wrong answer just as reliably as it converges on the right
one.

### Recency is cheap; breadth is not

The instinct is that the rollup exists to make reports fast, so anything wanting
speed must read it, so everything inherits the delay. That is backwards, and the
numbers already gathered say so.

`COUNT(DISTINCT session_id)` over the full corpus costs **39.2 s**; over one
month, **106.2 ms**. The cost tracks the width of the window, not the size of the
table -- which means a *thirty-minute* window, roughly 1/1400th of that month, is
trivial against raw events. The rollup earns its 2.9 ms/month by answering
questions that span a year. It buys **breadth, not recency**.

So the two access patterns separate cleanly, and they separate in the direction
that helps:

| view | reads | freshness |
|---|---|---|
| realtime ("what is happening now") | raw events, short window | immediate |
| historical ("last month by source") | rollup | last job run |

**The freshest thing in the system is computed the most naively.** A realtime
view does not read a summary at all, so it inherits no delay -- and "is my
tracking working?", the question whose answer must never be stale, is exactly the
one served without a summary. The delay lands only where a user is already
reading history, and history does not move.

This also means the realtime view is not a feature that needs building on top of
the rollup. It is a query.

### A realtime query must carry the partition key

Measured on a real partitioned install (427k events, 2013-2026), the obvious way
to write "the last thirty minutes" does not prune:

| predicate | partitions | index | rows |
|---|---|---|---|
| `timestamp > X` | **all** | none | 405,217 |
| `yyyymmdd >= D AND timestamp > X` | all from D | none | 351,937 |
| `yyyymmdd IN (D-1, D) AND timestamp > X` | **1** | `yyyymmdd` | **4,080** |

Partitioning is on `yyyymmdd`, so a filter on `timestamp` alone is invisible to
the pruner -- and unselective enough that the optimiser abandons the `timestamp`
index too and full-scans. An open-ended `>=` on the partition key prunes only the
past. Only a **closed** range on the partition key prunes to the partition the
data is actually in: a 99% reduction in rows examined, for a query that returns
the same answer.

The realtime view is the query most likely to be written the wrong way, because
"last 30 minutes" is a statement about time and `yyyymmdd` looks like a reporting
artefact. In v2 the same trap exists in the same shape. The date-bound belongs in
the query builder, derived from the time bound, not left to whoever writes the
report.

### Eventual consistency is a rollup property, not a scheduling one

The failure mode to design against is not lateness. It is a summary that never
catches up.

A rollup that advances a watermark -- "process events since the last run" --
**cannot** be eventually consistent, because an event that arrives after the
watermark has passed its own timestamp is never seen again. It is not late; it is
lost, and the summary is permanently wrong by a quiet margin nobody notices.
v2 has three sources of exactly that:

- **queue backlog** -- an event's delivery is decoupled from its occurrence by
  design, which is the entire point of the queue
- **deferred beacons** -- `sendBeacon` and the terminal beacon may not be sent
  until the next page load, or at all
- **clock skew** -- the tracker reports engagement deltas against its own clock

So the rule: **the rollup recomputes a trailing window, idempotently, rather than
consuming new rows.** Each run rebuilds every period touched by events seen since
the last run, and rebuilding a period that was already correct is a no-op that
costs time and changes nothing. Convergence then follows from the recompute being
total over the window, not from anything about the schedule.

That is affordable because of the same measurement as above: rebuilding the
**busiest single day** costs 377 ms. A trailing window of a few days, recomputed
every minute, is a rounding error against the 66 ms it costs simply to boot PHP
for the tick.

**The lateness horizon is the trailing window.** Beyond it, an event still lands
in the event table -- raw data is never rejected -- but no longer folds into a
summary until someone rebuilds that period explicitly. That bound has to be
stated rather than left implicit, because "eventually" without a horizon is a
promise that cannot be tested, and this is the number a test asserts against.

### The scheduler already supplies the hard parts

Since 1.11 this needs no new machinery. `ScheduleRunCli` already gives per-job
overlap locking -- so a rollup run that overruns its cadence is skipped rather
than doubled, which for a recompute would be wasted work rather than corruption,
but wasted work on a shared database is still the thing you were trying to avoid.
It is level-triggered, so a rollup that misses an hour runs **once** on the next
tick rather than backfilling hour by hour.

And the convergence rule the scheduler already imposes on anything registered --
*a job must be convergent, not incremental* -- is not a coincidence here. It is
the same property, arrived at from the other direction: the rollup must recompute
rather than consume in order to be eventually consistent, and it must recompute
rather than consume in order to be safely schedulable. A watermark rollup would
violate both at once.

What remains is a registration:

```php
$this->registerJob( 'rollup-sessions', 'rollup-sessions', '* * * * *', array() );
```

At a one-minute cadence the delay is bounded by roughly one minute plus the run,
which is below the threshold at which a human reading a report would notice --
so "eventually consistent" costs a user-visible delay only when something is
already broken, and `schedule-status` already reports that case.

### The UI has to say which kind of number it is

Two numbers with different freshness on one screen is the actual product risk. A
session count that reads 40 now and 43 after a refresh is indistinguishable from
a bug, and the support cost of that is real -- 1.x has never had to answer the
question because it has never had two answers.

The minimum is an as-of timestamp on anything summary-derived, and no such marker
on event-grain numbers. That is honest and it is cheap. It also puts a useful
constraint on the API contract: freshness is a property of a **result set**, not
of the installation, so it belongs in the response envelope next to the row
count, and a client that renders a mixed dashboard can then show it per widget
rather than guessing.

Worth deciding, not decided here: whether a report may mix tiers within a single
table. A row of event-grain columns beside session-grain columns is one object
with two as-of times, which no single marker describes honestly.

### Rejected

- **Reading the rollup for the realtime view**, for uniformity. It inherits a
  delay for a query that is already fast without it, in the one place where
  staleness is most visible and least excusable.
- **Writing session rows synchronously as 1.x does**, keeping one freshness. That
  is the 121-column `owa_session` write on the hot path -- the cost Addendum 3
  removed -- and it re-imposes ordering constraints on a queue whose delivery is
  deliberately unordered.
- **A dual write** -- maintain the rollup incrementally on the write path *and*
  recompute it in a job. Two writers to one summary, and the recompute exists
  precisely because the incremental path cannot be trusted to be complete. Keep
  one writer.
- **Blocking a report until the rollup is current.** Converts a freshness
  question into an availability one, and does it at exactly the moment the
  installation is under load.

## Domstreams

### Measured

```
229,663 recordings on a real installation
  average    1,809 B      p99  6,098 B      max  52,521 B
  blob total   396 MB     table total 656 MB
```

**Safely inside SQS's 256 KB limit** -- the largest recording measured is 20% of
it, so the queue concern raised above does not apply to this data. Worth noting
the caveat: the installation measured is not a heavy single-page application,
where longer sessions could close that gap.

What is stored is not a DOM recording. It is **pointer and scroll telemetry**:

```json
[{"timestamp":1422088758,"event_type":"dom.scroll","x":0,"y":290},
 {"timestamp":1422088758,"event_type":"dom.scroll","x":0,"y":292},
 {"timestamp":1422088758,"event_type":"dom.scroll","x":0,"y":294}, ...]
```

Replayed by `Player.js` (335 lines) as a cursor animated over a freshly loaded
copy of the page.

### Five things wrong with it

**1. The encoding is grossly redundant, and this is measured.** Consecutive
samples repeat every key and the whole timestamp to record two pixels of scroll
-- roughly 50 bytes per sample. Over 200 recordings:

| encoding | size | reduction |
|---|---|---|
| raw JSON as stored today | 637,441 B | 1.0x |
| gzip only | 65,591 B | 9.7x |
| delta-encoded tuples | 104,405 B | 6.1x |
| delta tuples + gzip | 21,304 B | **29.9x** |

396 MB becomes roughly 13 MB with no change to the feature at all.

**2. It is a 46-column fact table wrapped around a blob.** About forty of those
columns are session, campaign, geo and user-agent context copied from the page
request the recording belongs to. A recording is an **attachment to a pageview**,
not a parallel fact in its own right.

**3. The payload is inline.** Any query against the table drags the blobs with
it; the table is 656 MB for 229k rows. Metadata that is queried and payload that
is streamed want separating -- and a payload is object-storage shaped, which
matters more once a column store is in play, where large blobs are the worst
case.

**4. Replay is fragile by construction.** Coordinates are animated over a
*currently loaded* page. When the page has changed -- content, CSS, layout --
the replay is against a page the visitor never saw, and the positions mean
nothing. Nothing detects this; the replay simply misleads.

**5. The recording list is meant to be segmentable, and the UI has not caught
up.**

Choosing which replays to watch is the whole problem: an unfiltered list of
229,663 recordings is unusable. The list wants the ordinary metric and dimension
vocabulary -- recordings from mobile visitors, from a campaign, from sessions
that converted or stayed engaged.

The schema is already built for that, deliberately. A domstream is a first-class
fact with the full context of the page request it belongs to, and the wiki
documents a `domstream` metric group combining with eight dimension families.
What has not happened is the UI: `DomstreamsRestController` hand-writes its
query --

```php
$rsm->db->selectFrom('owa_domstream');
$rsm->db->where('page_url', ...);
$rsm->db->where('site_id', ...);
$rsm->db->groupby('domstream_guid');
```

-- so the recordings list filters on page URL, site and date only. The capability
is present and unexercised, not absent.

Two consequences, one for each timeframe.

**In 1.x** this is reachable now: routing the endpoint through the resolver
instead of hand-written SQL would light up segmentation that the schema already
supports, with no schema work at all.

**In v2** the same capability arrives without a parallel fact table to carry it.
A recording becomes an event, so the context it needs for segmentation is on the
row already, the standard metric and dimension machinery applies with nothing
special-cased, and metric-scoped filters -- "sessions that converted", "engaged
sessions" -- resolve through session scope like any other session-scoped
question. The requirement must be carried forward deliberately rather than
assumed: segmentation is the reason the fact table looks the way it does, and
whatever replaces it has to serve the same purpose.

The payload is the part that needs no querying: written once, fetched by id,
never scanned. Metadata and payload therefore want different treatment, which is
the distinction a single blob-bearing fact table cannot make.

### How a recording is actually stored, and what v2 should change

Recordings are **streamed in chunks**, which is the fact the rest of the design
turns on:

```
229,663 rows  =  82,861 recordings   ->  2.8 chunks per recording
heaviest: 279 chunks, 294,244 bytes total, 1 document
```

One document per recording, so a recording is 1:1 with a page view rather than an
occurrence in its own right.

**A queue correction.** The earlier measurement -- max 52,521 B, comfortably
inside SQS's 256 KB -- is per *chunk*. A complete recording reaches 294 KB and
would exceed the limit. Chunks must stay the unit of transport; assembling a
recording before queueing it would not fit.

#### Chunks are payload, not events

The samples inside a chunk carry an `event_type` and look like events, but
nothing ever queries them individually. Counted:

```
5,767,986 pointer and scroll samples   (25.1 per chunk x 229,663)
1,192,454 real events
       -> 4.8x the entire fact table, for cursor telemetry alone
```

Promoting chunks -- or worse, their samples -- to events would leave the fact
table roughly 83% mouse movement, dominating every scan, index and partition to
serve data no report asks a question of. The test for what belongs in the event
stream is not "is it an event?" but **"will anyone ever query it individually?"**

#### The shape

```
owa_event        pageview row, carrying recording_id (NULL when none)
                 <- all segmentation happens here, on every dimension
payload store    chunks keyed by (recording_id, seq)   <- append-only
owa_recording    small derived metadata: duration, chunks, bytes, expires_at
```

`recording_id` is a **reference, not a dimension foreign key**: no report joins
on it, it is dereferenced only when a human presses play, and the row displays
fine without it. The recordings list stays an ordinary event query --
`WHERE event_type='pageview' AND recording_id IS NOT NULL AND <dimensions>` --
so segmentation is native and join-free.

#### Writes stay on the hot path only where they must

Naively the first chunk costs three writes: the event, a recording row, and the
payload. Two decisions remove the extra two:

- the **tracker mints `recording_id`**, so it rides on the pageview event and no
  separate write establishes the recording
- **`owa_recording` is derived, not maintained** -- a scheduler sweep computes
  duration, chunk count and bytes once the chunks stop arriving, exactly as the
  sessions rollup is derived rather than maintained in the write path

Leaving one event write plus one write per chunk: the same write *count* as
today, without 46 columns of duplicated context on each, and nothing extra on
the hot path. It also satisfies the rule from the session work -- `recording_id`
is present from the first event, so a lost final chunk costs the tail of a
replay rather than the recording.

#### Append-only, never an update

Each chunk is its own row. Updating one growing blob would cost, for the
heaviest recording:

```
1055 B x (279 x 280 / 2)  =  ~41 MB written for a 294 KB recording   (~140x)
```

MySQL rewrites the row and its overflow pages on every BLOB update, so that is
real I/O. **S3 has no append operation at all**, so chunk-per-object is forced
there regardless -- designing around append-to-one-blob would rule out the
object-store backend entirely. Today's implementation already writes one row per
chunk; that part is right and should survive.

An optional refinement, since the sweep exists anyway: **seal** a finished
recording by concatenating its chunks, recompressing as one object and deleting
the chunk rows. That earns the full ~30x ratio -- one gzip window instead of 279
-- and collapses the row count. Gzip members also concatenate and still
decompress, so even unsealed chunks can be streamed back in order without
recompression.

#### Where each piece lives

By access pattern rather than by what the data resembles:

| | access | store |
|---|---|---|
| `owa_event` | scan many rows, few columns | columnar, where it is worth it |
| `owa_recording` | fetch ~50 rows by id | row store |
| payload | fetch one blob by id | object store, or a blob column |

A blob has no columns to scan, so columnar storage buys nothing there and
actively hurts: large opaque values defeat the encoding and compression that
make columnar worthwhile. The recordings list therefore spans stores in the only
way that is safe -- filter events (columnar strength), paginate to 50, fetch 50
metadata rows by id, stream one payload on play. Small-N application-side join,
never a cross-store join over large sets. On the default single-MySQL install
all three live in one database and the boundary is invisible.

#### Where `seq` comes from, and what it replaces

From the tracker: a counter in the page's JS context. A recording belongs to one
page view, so it is a local variable -- none of the cross-tab races that ruled
out a shared session counter apply.

This is not the sequence number rejected in the session work, and the difference
is principled:

| | session engagement | recording chunks |
|---|---|---|
| does order matter? | no, deltas commute | **yes**, out-of-order replay is broken |
| can the server derive it? | not needed | **no** -- arrival order is not emission order |
| scope | shared across tabs, races | one page view, no sharing |

One integer does three jobs: **ordering** for replay, **dedup** via
`(recording_id, seq)` as a primary key -- which matters because an at-least-once
queue will redeliver -- and **gap detection**, so a replay missing a chunk can
say so rather than silently playing with a hole.

**What it replaces.** There is no `seq` today. Chunks are ordered by
`orderBy('timestamp', 'ASC')` on a second-resolution INT, and
`mergeStreamEvents()` appends without re-sorting, so replay order rests entirely
on that. Chunks sharing a second have undefined order. Measured:

```
240 (guid, timestamp) groups hold more than one chunk
223 of 82,861 recordings affected  (0.3%)
worst: 5 chunks within one second
```

0.3% is low enough that this is latent fragility rather than a live fault -- the
tracker mostly flushes at intervals longer than a second, and a cursor animation
with two slightly transposed segments is not obviously wrong. But it degrades in
exactly the directions v2 moves: more engaged pages flush more often, routing
chunks through an unordered at-least-once queue breaks the assumption that
arrival order tracks emission order, and there is no dedup key at all, so a
redelivered chunk silently appends its samples twice.

Which is this redesign in miniature: much of it is not new capability, but
replacing what works **by circumstance** with what works **by construction**.

### Replace JSONP with CORS for playback

Both playback surfaces still fetch over JSONP:

```
Player.js:66    dataType: 'jsonp',  jsonp: 'owa_jsonpCallback'
Heatmap.js:217  dataType: 'jsonp',  jsonp: 'owa_jsonpCallback'
```

served by the `jsonp` branch in `ApiRequest` and `resultSetToJsonp()`.

JSONP works by injecting a `<script>` tag, which means the response is
**executed, not parsed**. The consequences are the reasons to remove it rather
than merely modernise it:

- the endpoint can only ever be `GET`, with the request in the query string
- the same-origin policy is bypassed by design, so the response is readable by
  any page that can name the callback -- there is no origin check to fail
- an authenticated overlay session makes that a data-disclosure surface, since
  the browser sends cookies with the script request
- errors are invisible: a failed JSONP request cannot report a status code, only
  a callback that never fires
- the callback name is reflected into executable output, which is a class of
  injection that only exists because of the mechanism

None of this is theoretical for the overlay: it runs on the customer's own page,
cross-origin from the OWA install, which is exactly why JSONP was reached for.

**The CORS replacement does not work, and never has.** `Core/View/RestApi.php`
has an `addCorsHeaders()` and `Base/View/CorsPreflight.php` answers preflight, so
the outline is present -- but the origin check cannot match:

```php
foreach ( CoreAPI::getSitesList() as $allowedOrigin ) {
    if ( $allowedOrigin !== $HTTP_ORIGIN ) { continue; }
```

`getSitesList()` returns **row arrays** (`id`, `site_id`, `domain`, `name`,
`settings`, ...), each compared with `!==` against the Origin **string**. An
array is never identical to a string, so `continue` always fires and no header
is ever sent. Verified against a live install: both the REST route and the
api-request controller answer a request carrying a valid `Origin` with **no
`Access-Control-*` headers at all**.

A second fault sits behind the first. Even comparing the right field, `domain`
holds `example.com` while an `Origin` header is `https://example.com` -- scheme
included, and port when non-standard -- so correcting the array/string
comparison alone still would not match.

That reframes the JSONP dependency: it is not legacy nobody replaced, it is the
only mechanism that works. Removing it therefore requires **building** the CORS
path rather than switching to it:

- compare against an origin, not a site row -- and decide deliberately what the
  allowed set is, since a site's `domain` is not an origin
- an install serving one site over both `http` and `https`, or on a non-default
  port, needs each variant allowed or normalised
- preflight has to be reachable and correct for the methods playback uses
- and it wants a test: this failed silently for however long precisely because
  nothing exercised it

Independent of the rest of v2, and worth doing in 1.x -- but as real work with
its own verification, not the deletion it first appeared to be.

### On rrweb, and why "better" is not obvious

The modern standard for this is rrweb: capture a DOM snapshot plus mutations,
replay faithfully. It would replace `Heatmap.js` (614 lines) and `Player.js`
(335) with a maintained library and fix the fragility in point 4.

Two reasons not to reach for it reflexively:

- **Payloads grow by one to two orders of magnitude.** The 256 KB queue limit
  stops being comfortable, and 396 MB of storage becomes tens of gigabytes.
- **It records page content.** Form values, names, anything on screen. rrweb
  offers masking, but the default posture inverts.

OWA's coordinate-only capture is **privacy-preserving by construction**: it
records no content, because it never had any to record. For a self-hosted tool
whose appeal is not sending visitor data to Google, that is a property worth
keeping deliberately rather than an limitation to be corrected.

### What v2 should do

1. **Keep coordinate-only capture** as the default, and say why in the docs --
   it is a feature.
2. **Fix the encoding**: delta tuples plus gzip, measured at ~30x.
3. **Make the list segmentable through the normal machinery**, which
   recording-as-event gives for free, and retain payloads on a window: nothing
   is derived from them, so a scheduler job expiring them costs nothing
   downstream. Metadata may outlive the payload it points at, which is worth
   deciding deliberately -- a list entry whose recording has expired is
   honest, a dangling player link is not.
4. **Make a recording an attachment to an event**, not a parallel fact row --
   which deletes about forty duplicated context columns.
5. **Store the payload outside the row**, so metadata queries do not drag it.
6. If faithful replay is genuinely wanted, offer **rrweb as an opt-in backend**
   with its storage and privacy costs stated plainly, rather than as the default.

## Other consolidation worth doing

Measured against the tree, in rough order of value.

### The "(not set)" placeholder convention

Ten custom-variable columns (`cv1_name`/`cv1_value` through `cv5`) exist on every
fact table. On the installation measured:

```
cv1_name = '(not set)'   636,644 rows
cv1_name = NULL           56,180 rows
cv1_name with a real value:    0 rows
```

Zero real values, in all ten columns, on every row -- a nine-character
placeholder stored six times over per event. The same convention explains the
heatmap finding: `dom_element_id` is "populated" on 94% of clicks and holds a
real value on 0.09%, because 439,839 rows say `(not set)`.

Two changes follow. **Absent should be NULL**, not a sentinel string -- the
distinction between "no value" and "the value is the words not set" is currently
unavailable, and every count of populated columns is wrong by default.
### Custom variables must stay segmentable -- by name, not by slot

Custom variables are user-defined **dimensions**, so filtering and grouping on
them has to work. The improvement over today is smaller and more specific than
it first appears.

**What today does.** The registered dimensions are `customVarName1`..`5` and
`customVarValue1`..`5`, so an analyst segments **by slot**: they must know the
variable landed in slot 2, and nothing guarantees it lands in the same slot on
the next event. That is very likely why the feature is unused on every
installation measured here -- ten columns carrying `(not set)` on every row and
zero real values anywhere.

**What v2 should do.** Store them as event parameters and segment **by name**:

```sql
WHERE site_id = ? AND yyyymmdd BETWEEN ? AND ?
  AND params->>'$.plan' = 'premium'
```

Unlimited variables, no wasted columns, and the slot problem disappears
entirely.

**No registration step is required**, and proposing one was premature. Filtering
on a custom variable happens inside an already-narrowed scope -- a site and a
date range -- and month-scoped queries over 692k events measured at 18-160 ms
here, so a JSON path filter applied to an already-pruned set is very likely
fine. If measurement later says otherwise, a virtual generated column and index
on that path is available as **tuning**, not as a gate a user must pass first.

**Measured, on 692,828 rows in MySQL 8.4.** The syntax is `->>`, which is
`JSON_UNQUOTE(JSON_EXTRACT())` and returns text so it compares directly:

```sql
WHERE site_id = ? AND yyyymmdd BETWEEN ? AND ?
  AND params->>'$.plan' = 'premium'
```

| | unindexed | with a generated column + index |
|---|---|---|
| one month | **46.9 ms** | 4.1 ms |
| one year | 320.8 ms | 24.8 ms |
| whole table | 883.1 ms | 1,300.3 ms |
| `GROUP BY` the variable, one month | 484.9 ms | 555.0 ms |

At the scope reports use, unindexed is 47 ms: the site and date index prunes
first and the JSON extract runs on what remains. No index is needed to ship.

The tuning lever, when a particular variable becomes hot:

```sql
ALTER TABLE ev ADD COLUMN cv_plan VARCHAR(64)
  GENERATED ALWAYS AS (params->>'$.plan') VIRTUAL;          -- 63 ms
ALTER TABLE ev ADD INDEX cv_plan_idx (site_id, cv_plan, yyyymmdd);  -- 13.3 s
```

Three properties matter. Adding the column is **instant and free in storage** --
63 ms, table unchanged at 136.3 MB -- because `VIRTUAL` computes on read and
never materialises, so no row is rewritten; only the index costs anything, once.
It is **retroactive**: the column was added after the rows existed and matched
173,123 of them immediately, which is the property GA cannot offer. And it is
**not universally better** -- whole-table counts got slower (883 -> 1,300 ms) as
the optimiser chose the index over a scan on an unselective filter, and
`GROUP BY` barely moved. Indexes help selective lookups inside a scope, which is
what reports do; adding them speculatively can hurt.

Worth noting the contrast, since it is the reverse of the usual direction: GA4
*does* require registering a custom dimension before it appears in reports, and
registration is not retroactive -- the raw parameter sits in the export while
historical events never appear under the dimension. Storing values as parameters
and querying them by name means OWA needs no such step, and no history is ever
stranded.

What is genuinely needed is **labelling and discovery**, which is a different
problem: a segment picker has to know which variables exist and what to call
them. Both are derivable -- collect the distinct keys seen, let an administrator
optionally supply a friendlier label than the key. Nobody is blocked if they
never do.

### Compatibility aliases

411 entries in `owa_compat_aliases.php`, each mapping a legacy class name onto a
namespaced one, and mandatory -- `entityFactory()` throws without them. A major
version is the one opportunity to delete the lot.

### Vestigial declarations

Things declared and never used, found while measuring other questions:

| | state |
|---|---|
| `is_assigned` on the queue item | declared, never read or written -- the missing claim |
| `dom_element_parent_id` | declared, 0% populated |
| the 11 `*InVisit` dimensions | registered, zero references outside registration |
| `owa_exit` / `base.exit` | registered, no write path |
| `owa_request.os` beside `os_id`, `site` beside `site_id` | legacy duplicates of the key next to them |
| `owa_site.id_1_3` | migration artefact |
| `processQueuesJobSchedule` | setting read nowhere |
| `error_log_level` | never read; debug is gated by `OWA_DEBUG` |

Individually trivial. Collectively they are the reason the schema reads as more
capable than the implementation is, which has now caused three wrong conclusions
in this document alone.

### Derived date parts

Nine columns per fact row -- `year`, `month`, `day`, `dayofweek`, `dayofyear`,
`weekofyear`, `hour`, `minute`, `second` -- all derivable from the timestamp.
They exist because deriving in SQL prevented index use, which a single event
table with a date-keyed partition column no longer requires. Deriving at query
time also makes a timezone change correct history rather than leave it wrong.

### Modules

Seven modules, of which five are small: `Hello` (199 lines, an example),
`FileCache` (343), `MemcachedCache` (221), `RemoteQueue` (61), `MaxmindGeoip`
(321). The module system is a genuine extension point and should stay, but the
caches belong behind one cache interface rather than as modules, and `Hello`
belongs in documentation rather than in the shipped tree.

### The big classes

```
Core/Db.php          3,171 lines, 118 methods
Core/CoreAPI.php     2,257 lines
Base/Settings.php    1,469 lines
Core/Template.php    1,107 lines
```

`CoreAPI` in particular is a static facade over everything -- the class every
other class reaches through, and therefore the one that makes the system hard to
test in isolation. Breaking it up is not urgent, but v2 should avoid adding to
it, and the storage seam work already pulls `Db` apart along a useful line.

## The tracker

### What is already right

```
owa.tracker.js   52.6 KB raw, 17.2 KB gzipped, no jQuery bundled
                 (jQuery appears only in the separate heatmap and player bundles)
```

`sendBeacon` is already the primary transport with an `Image` fallback, and
state persistence is deferred until delivery is confirmed -- so a failed beacon
does not mint a duplicate session. The hard parts of the engagement-beacon design
already exist.

### Transport: replace the hidden-iframe POST

When a request URL exceeds `getRequestCharacterLimit`, `logEvent()` falls back to
`cdPost()`, a hidden-iframe POST which by its own comment *"gives us no delivery
signal, so commit optimistically"*. That is the path large payloads take, and it
is why their delivery cannot be confirmed.

The cause is that `sendBeacon` is called as `sendBeacon( url )` with everything
in the query string, so payload size is bounded by URL length. `sendBeacon` with
a `Blob` body, or `fetch(..., { keepalive: true })`, removes the limit and
returns a real result -- no iframe, and it still works during unload.

### `Util.js` is 1,383 lines of ES5-era helpers

`Util.strpos`, `Util.trim`, `unescape()`, `toGMTString()`, `new Array()` used as
an object -- PHP string functions reimplemented in JavaScript alongside
deprecated APIs. Most are one-liners now, and the tracker's browser baseline can
be modern.

### What the tracker should start sending

- **engagement deltas and a sticky `engaged` flag**, per the session model above
- **`seq` on recording chunks** -- a page-scoped counter giving ordering, dedup
  and gap detection
- **an element path** for clicks, without which heatmaps stay coordinate-based:
  measured, `dom_element_id` holds a real value on 0.09% of clicks
- **the URI rather than the full URL**, letting the server derive page identity
  scoped by site, which is what collapses the seven-way homepage split
- **custom variables by name**, as parameters rather than five fixed slots

### What it should stop sending

`Tracker.js` is 2,221 lines, much of it computing what the server can derive --
date parts especially. The tracker should send only what the client alone knows:
location, referrer, viewport, engagement, element identity.

### Cookies and parameters

Three stores are registered, in **two serialisation formats**:

```js
OWA.registerStateStore('v', 364, '', 'assoc');   // visitor
OWA.registerStateStore('s', 364, '', 'assoc');   // session
OWA.registerStateStore('c',  60, '', 'json');    // campaign attribution
sharableStateStores = ['v', 's', 'c', 'b'];      // 'b' referenced, not registered here
```

The `assoc` format is homegrown:

```js
string += prop + '=>' + obj[prop];
if (i < count) { string += '|||'; }
```

so every value carries `=>` and every pair after the first carries `|||`, before
URL-encoding inflates both -- on a cookie sent with every request to the domain,
including every image and stylesheet.

**Standardise on one format.** Two encoders, two decoders, a `format` argument on
`registerStore` and a branch at every read and write, all for flat maps.

**Multiple cookies remain necessary** -- expiration is a property of the cookie,
so state with different lifetimes cannot share one. But grouping is by
**expiration class, not by logical store**, and OWA already has the mechanism for
finer granularity: `s` is registered for 364 days precisely so the *last* session
id survives for `prior_session_id`, `days_since_prior_session` and
`num_prior_sessions`, with the 30-minute session lifetime enforced as a timestamp
check on read rather than by cookie expiry. The same treatment gives `c` its
60-day attribution window inside a persistent cookie -- and fixes it by
construction, since a logical check on read cannot be silently dropped the way
`set()` currently drops its `expiration_days` argument.

**Compare GA4, which has solved this and migrated once already.**

```
_ga                    client id only, 2 years
_ga_<MEASUREMENT_ID>   session state, 2 years

GS1.1.SESSION_ID.TIMESTAMP.EVENT_COUNT.ENGAGEMENT_TIME      <- fixed positional
GS2.1.s<id>$o<num>$g<engaged>$t<ts>$j..$l..$h..             <- labelled, $-delimited
```

Two cookies, and the session one moved **from positional to labelled key-value**
because fixed positions are rigid: adding or removing a field breaks every
parser. Their keys are single characters -- `s` session id, `o` session number,
`g` session engaged, `t` last hit timestamp.

So OWA already has the right *concept* -- `key=>value` is labelled -- and simply
pays about five times too much for it: `|||` and `=>` against GA's single `$`,
and full words against single characters, on a cookie sent with every request to
the domain. Worth noting GA's `g` field is session-engaged, which independently
confirms the sticky-flag design above.

**What OWA lacks entirely is a format version marker.** `GS1`/`GS2` is the first
field, so a parser knows what it is reading, and Google migrated formats while
running both during a coexistence phase. OWA carries `cdh`, a domain hash, but
nothing identifying the format.

A leading version field is worth having, and the single-tracker decision below
makes it **more** important rather than less. An earlier sketch had v2 using its
own cookie namespace, which would have meant v1 cookies were never reinterpreted
and a marker was merely tidy. With one tracker there is one store, and its format
changes underneath visitors who already hold the old one -- exactly the case a
version marker exists for, and exactly what Google shipped `GS1`->`GS2` to
handle. `Util.getCookieValueFormat()` sniffing `{` covers *this* change; a marker
is what covers the one after it.

Visitor continuity needs no special handling for the same reason: one tracker
means one visitor id, written to both schemas, so there is nothing to adopt or
reconcile.

**The cookie domain hash stays.** `cdh` is `crc32(cookie_domain)` stored inside
each store's value, and the load path iterates `cookie_values` -- plural,
indexed -- because **several cookies can share one name**. A cookie called
`owa_v` may exist at `example.com` and at `.example.com` simultaneously, and
`document.cookie` returns both as bare `name=value` pairs: **JavaScript cannot
read a cookie's domain attribute**, so without a marker inside the value there is
no way to tell them apart.

The Cookie Store API is the obvious modern replacement and explicitly declines
the job. It is now supported in Safari 18.5+ and Firefox 138+ as well as
Chromium, but **Safari and Firefox implemented only the subset matching
`document.cookie` -- names and values, no domain** -- having agreed not to expose
more than `document.cookie` already does. The limitation is not merely
unchanged; it has been reaffirmed by the standards process.

Two further reasons it earns its place. Duplicate-name cookies are routine
rather than exotic: any change to `cookie_domain` -- apex to `.apex`, `www` to
bare, adding a subdomain -- leaves the previous cookie in place and the browser
sends both, and any install that has ever moved between an apex and a `www`
host, or between a subdomain and its parent, has produced exactly that. And the fallback is arbitrary: with
`hashCookiesToDomain` off the code takes "the last cookie set by that name",
which is not reliably ordered, so it picks close to at random when duplicates
exist.

What should change is its encoding, not its existence:

- it becomes a one-character key under the single format, rather than the four
  bytes `cdh` costs on every request to the domain
- **`hashCookiesToDomain` should go** -- the check is either necessary or
  harmless, and making it optional only preserves a path on which the arbitrary
  fallback is reachable
- it belongs at the front alongside the format version marker: both are cookie
  metadata, and both are read before the payload is trusted

**Drop the `owa_` prefix from URL parameters.** It is a byproduct of OWA once
being embeddable inside other applications, a design since removed, so the bytes
are paid on every tracking request for a collision that can no longer occur. The
prefix should stay on **cookie names**, where other software on the same domain
genuinely may collide, and a short prefix costs four bytes once against a
name collision that silently corrupts state.

## Migration: coexistence, not conversion

**The strategy.** v2's schema is created alongside v1's in the same database.
No 1.x data is converted. v1 keeps serving history through its own reporting UI
until the administrator decides the history is no longer needed and drops it.
Both trackers may report to the same installation during an overlap period; the
two reporting UIs are entirely separate.

**Both reporting interfaces are reachable at once**, for as long as the
administrator wants them -- v2's is not a replacement that displaces v1's on
upgrade, and v1's does not become read-only or hidden. Two consequences. They
need one entry point that offers both rather than two bookmarks, since a user
comparing them is the entire point of the overlap. And they must share the
session: a separate login for each would make the comparison tedious enough that
nobody does it, which is the failure mode that turns an overlap period into a
rubber stamp. Shared auth is already the constraint the authentication section
works under, so this costs nothing extra.

This is a far better trade than in-place conversion, and today's exercise is the
evidence: converting *one id width* on two installations took five pull
requests, a storage upgrade and most of a day, with tracking dropped in the
window where the flag and the data disagreed. A total schema change is not that
problem scaled up -- it is a different and much worse problem.

### What is shared and what is parallel

```
SHARED (admin)              6 tables      0.1 MB
PARALLEL (facts, v1 keeps) 10 tables  2,358.7 MB
PARALLEL (dims, v1 keeps)  10 tables     38.6 MB
```

Effectively all the volume is in the parallel set and the shared set is trivial,
which is what makes coexistence cheap. Sites, users, goals and configuration are
**shared** -- an administrator must not manage sites twice, and duplicating them
would create two sources of truth for the one thing both halves agree on. Event
storage is **parallel**: `owa_event` and its companions are new tables beside
the existing ones, colliding with nothing.

The constraint that follows: **v2 must not alter the shared admin tables
destructively.** Additive columns are fine; changing how a site id is derived is
not, because v1 would stop resolving its own data.

### One tracker, two pipelines

**Decided, and it replaces the two-tracker model sketched earlier.** There is a
single tracker. It describes each event richly enough that *both* pipelines can
consume it, and the server fans one received event out to both -- v1's handlers
write the star, v2's write the event table. When v2 reaches feature parity, the
fan-out to v1 is switched off. Nothing about the page changes on that day.

Two trackers would have split each site's data by which snippet happened to be
deployed, so v1's history would step or stop at the moment a site upgraded --
destroying the value of the thing being kept. One tracker keeps v1's history
continuous right up to the cutoff, which is the only reason retaining it is worth
anything.

**The wire format is therefore free.** Because the fan-out happens server-side,
the payload does not have to satisfy v1's parameter conventions; a shim presents
each pipeline with the shape it expects. This is what rescues the tracker
decisions taken above -- dropping the `owa_` namespacing and standardising on one
cookie format are client-side changes, and v1's handlers never see the wire.
Under a two-tracker model those changes would have been blocked until v1 was
gone. The tracker sends a union of *information*, not a union of *formats*.

Identity collapses the same way. One tracker means one cookie store and one
visitor id, written to both schemas, so the earlier refinement about v2 adopting
the v1 visitor id is unnecessary -- there are not two ids to reconcile.

### Dual-write is a parity test, not just a migration step

This is the part worth more than the migration convenience. Every production
event passes through both pipelines against real traffic, so a metric that
disagrees between the two schemas is **observable before anything is committed
to**. That is a far stronger position than porting a metric definition and
reasoning about whether it still means the same thing.

For the comparison to mean anything, both pipelines must consume identical
inputs: the same event id, the same server-assigned timestamp (1.x already
assigns event time server-side rather than trusting the client), the same
visitor and session identity. Divergence must be attributable to the *pipeline*,
not to the input.

**And the intended differences have to be declared in advance**, or every
comparison is ambiguous -- an unexplained gap is indistinguishable from a bug,
and a bug hiding behind an expected gap is invisible. v2 is deliberately
different in at least these places, all of them decided elsewhere in this
document:

| metric | why it should differ |
|---|---|
| bounce rate | if redefined as "not engaged" rather than "one pageview" |
| visit duration | derived from engagement deltas, not last-minus-first timestamps |
| exit page | an `is_exit` flag set by a closer job, not derived at query time |
| goals | key events marked at collection, not evaluated retroactively |
| the 11 `*InVisit` dimensions | deliberately not ported |

A parity report subtracts that list. Whatever is left over is a defect, and the
list itself is the specification of what "parity" means -- without it, "we
reached parity" is an opinion.

**Failure isolation is deliberately asymmetric.** For the whole overlap, v1 is
production and v2 is the one on trial. A failing v2 handler must not be able to
fail v1's write, or the request, or the response to the browser. The converse
matters far less, and pretending the two deserve equal protection is how a
trial system takes down a working one. An event that one pipeline has nothing to
do with is a **success** for that pipeline, not an error -- the same rule the
schema-versioning section arrives at for a missing table.

**The cost, from the measured write paths.** v1 is 7 statements per pageview at
79 events/s; v2 is 1 statement at 202. Composed, dual-write lands near **57
events/s -- roughly 28% below v1 alone**. That composition is pessimistic, since
the two writes share connection, parse and request overhead rather than paying
it twice. It is a real cost and it falls exactly during the period when
confidence is lowest, which is an argument for the queue carrying the fan-out
rather than the request doing it inline.

**Switching off is a setting, and reversible** until v1 is dropped. The only
ragged edge is events queued across the cutoff: either the fan-out decision is
recorded on the event when it is accepted, or a handful of events land in v1
after the switch. The first is tidy; the second is free and harmless, because by
definition the switch happens when v1's numbers have stopped mattering.

### Fan-out is per feature, and some features cut over early

The tracker **evolves in place**. It is not rewritten and not forked: the same
codebase is extended until it emits what both pipelines need, which is why the
tracker-additive work above is worth shipping in 1.x rather than banked against
a rewrite.

But the fan-out itself is **per event type, not global**, and some features
should skip the dual-write period entirely -- going to v2 only, from early on.
A feature qualifies when both of these hold:

1. **v1 has no history worth preserving for it** -- the reporting is thin or
   unused, so there is nothing to strand and no oracle to compare against
2. **keeping it dual-written would mean renovating v1's storage** to carry data
   only v2 can use -- spending real work on a schema already being retired

**Domstreams are the clear case, and qualify on both.** Everything v2 changes
about them is storage-shaped: a recording becomes an attachment to an event
rather than a parallel fact row, the payload moves outside the row, and the
encoding becomes delta tuples plus gzip. Dual-writing leaves only bad options --
either `owa_domstream` keeps its present shape, in which case the new capture
has nowhere to land, or the table is renovated to accept a format v1 will never
report on. The second is precisely the wasted work worth avoiding. And OWA's own
domstream reporting has never exploited the dimensions that fact table already
carries, so there is very little history to lose by cutting over.

Heatmaps follow domstreams for the same reason, being built on the same capture.

**This creates a second exclusion list for the parity harness, and it is not the
same list.** Intended differences (bounce, visit duration, exit page, goals) are
metrics that *are* compared and *are expected* to differ. Early-cutover features
are **not compared at all**, because no v1 side exists. Conflating the two is how
a real regression hides behind "that one's expected" -- so both lists are
declared, separately, and the harness treats them differently: one is a diff with
a tolerance, the other is an absence.

**Early cutover is one-way in practice.** Once recordings exist only in v2, v1's
player has nothing new to play. That is acceptable exactly when criterion 1
holds, which is why the list should be decided deliberately per feature and
written down, rather than arrived at by noticing that something stopped being
dual-written.

### Work that is additive to 1.x, and is not redone for v2

Much of what this document proposes can ship in 1.x, but "additive" needs
splitting three ways, because some of it carries over whole and some leaves
1.x-specific plumbing behind.

**Fully additive -- shipped once, inherited unchanged.**

| work | what it fixes in 1.x |
|---|---|
| **CORS, replacing JSONP** | `addCorsHeaders()` compares row arrays to a string and has never emitted a header; playback runs on JSONP, which bypasses same-origin by design |
| **PDO driver** | real bound parameters instead of `mysqli_real_escape_string`, and retires the hand-rolled connection handling |
| **`Util.js` modernisation** | a smaller tracker for every 1.x user |
| **`preconnect` and snippet placement** | removes DNS, TCP and TLS from the critical path on a cold origin, which matters far more for a self-hosted tracker than for GA |
| **Bot filtering improvements** | data quality, unchanged by the schema |

**Tracker-additive -- the client work is inherited, the 1.x server plumbing is
discarded.** These are still worth doing, because the tracker half is the hard
and novel part and shipping it first proves it against real traffic before v2
depends on it. But the benefit in 1.x is smaller than it looks:

| work | inherited | discarded in 1.x |
|---|---|---|
| **engagement deltas, sticky `engaged` flag** | accumulation, piggybacking, terminal beacon | a column to receive the delta, a `SessionHandlers` line to accumulate it, a metric to read it |
| **chunk `seq`** | the page-scoped counter and its three uses | a column on `owa_domstream`; v2 keys a payload store instead |
| **element path capture** | the selector or ancestor-chain logic | a column on `owa_click` |
| **transport: `sendBeacon` with a body** | the client transport | the endpoint's body-parsing path is small but v1-shaped |

One caution specific to engagement. Redefining `visitDuration` and `bounces` in
1.x to use engagement would **break historical comparability** -- every existing
trend steps at the release boundary, because the old values came from timestamps
and the new ones do not. In 1.x these belong as *new* metrics beside the old
ones, not as redefinitions, which mutes the reporting benefit further.

**1.x improvements the requirement outlives, but the mechanism does not.**

| work | note |
|---|---|
| **domstream list through the resolver** | lights up segmentation the 1.x schema already supports and the UI never used; v2 replaces the mechanism entirely, but carries the requirement |
| **queue lease semantics** | `is_assigned` is declared and never read, so two drainers process the same rows -- a real 1.x bug fix, though v2's queue is a different implementation of the same design |

The pattern worth noting: what carries over cleanly is **tracker, transport and
security work** -- the parts the schema change does not touch. Anything that
lands data in a v1 table is plumbing with a known expiry date, which is fine as
long as it is chosen knowingly.

### One tracker, one protocol

The additive argument above holds only if v2's tracker is an **evolution of the
same source**, not a fork. If it were a fork, every row in the tracker-additive
table -- engagement deltas, chunk `seq`, element paths, beacon transport --
would have to be written twice, and the argument collapses.

So: **one tracker codebase, and -- given server-side fan-out -- one protocol.**
An earlier draft here proposed a v1/v2 protocol mode, the tracker serialising two
ways at the edges. Fan-out removes the need for it: there is one wire format, and
v1 compatibility is a translation the *server* performs rather than a mode the
client carries. Capture, transport, state and serialisation are all simply
shared.

**The state cleanup is additive, and should ship in 1.x.** Two facts settle it.

The keys already overlap almost entirely:

```
v: vid, fsts, nps, dsfs          visitor identity and history
s: sid, last_req, referer, dsps  session
c: attribs                       attribution
```

v2 needs exactly this set plus an engagement accumulator and the engaged flag,
so the state model is being *extended by two fields*, not replaced. They are
also already short -- three and four characters, not the full words claimed
earlier when comparing with GA, so the waste is in the separators (`|||` and
`=>`) rather than the key names.

And **the dual-format reader already exists**. `Util.getCookieValueFormat()`
sniffs the first character -- `{` means JSON, anything else means `assoc` -- so
changing what is *written* is safe today: existing cookies keep parsing, new
ones use the chosen format, and visitors upgrade on their next write. The
migration risk that would have justified deferring this is not present.

So unifying on one write format, deleting the `format` argument on
`registerStore`, collapsing the two encode paths and dropping the optionality of
`hashCookiesToDomain` are all **shared StateManager work that v2 inherits**,
with only the namespace prefix differing between modes. Doing it in 1.x means v2
begins from clean code rather than inheriting the tangle or rewriting it.

The **`owa_` prefix on URL parameters** was the one item held back as v2-only,
on the reasoning that 1.x would otherwise have to accept both spellings on the
ingestion side. Server-side fan-out dissolves that as well: the shim feeding v1's
handlers is exactly where the prefix is reapplied, so the tracker can stop
emitting it once and neither pipeline notices. It is additive after all -- which
is the general shape of this decision, since anything the server can translate
stops being a client-side compatibility problem.

### What cannot be additive

- **the single event table**, and everything downstream of it: reports as data,
  the API-first UI, the metric and dimension registry enforcing additivity
- **URI-based page identity** -- changing id derivation in 1.x would require
  precisely the migration this strategy exists to avoid
- **custom variables as named parameters**, since the five fixed slots are the
  schema
- **cookie format consolidation and dropping the `owa_` parameter prefix**,
  which belong to the v2 tracker's own namespace
- **`NULL` instead of `(not set)`**, because 1.x reports and queries may test
  for the sentinel; new data would be clean while old data is not, and the
  inconsistency is worse than either state

### Cutover

1. Ship the additive work in 1.x releases, each standing on its own
2. v2 creates its tables alongside; the single tracker's events are fanned out
   to both pipelines, except for features on the early-cutover list, which go to
   v2 only from the start
3. The administrator runs both for an overlap period, comparing the two UIs over
   *the same events* -- not merely the same traffic, which is what makes the
   comparison a test rather than an impression, and is the only validation
   available with no reference implementation to check a total schema change
   against
4. When satisfied, they switch off the fan-out to v1 and keep v1 reporting for
   history
5. When the history is no longer wanted, a command drops the 20 parallel tables,
   leaving the shared admin tables untouched

No step is irreversible until the last one, and the last one is the
administrator's decision rather than an upgrade's side effect.

## Schema versioning: one schema, one sequence

**Decided.** There is one schema version and one numbered update sequence. An
update class may touch v1 tables, v2 tables, or both, and the version number
denotes a change to *either*. v1 and v2 are not two schemas that coexist; they
are one schema that happens to contain both shapes.

That is a smaller decision than it sounds, because the mechanism is already built
that way.

### Three things that already hold

**There is effectively one sequence today.** Only Base has an `Update/` directory
and a version above 1 (currently 16). The other six modules -- Domstream,
FileCache, Hello, MaxmindGeoip, RemoteQueue, MemcachedCache -- all declare
`required_schema_version = 1` and ship no updates at all. The per-module
versioning in `Core/Module.php` is real but has only ever been exercised by one
module, so "one sequence" is a continuation rather than a change.

**The update sequence does not build the schema.** `Module::install()` iterates
the module's registered entities and calls `createTable()` on each
(`Core/Module.php:534`), then stamps `schema_version` straight to
`getRequiredSchemaVersion()` (line 561) without replaying a single update. Updates
exist *only* to migrate installs that already exist. So which tables a fresh
install has is decided by **entity registration**, and the numbering is free to
span both shapes because it was never what created either.

**Ordering and resumability already work.** The apply loop sorts numerically,
runs in order, and returns on the first failure (`Core/Module.php:648-658`);
each update stamps its own sequence number as the new version. A failure
therefore leaves the install at the last update that succeeded, and re-running
resumes from there. `CREATE TABLE IF NOT EXISTS` (`Core/Db/Mysql.php:45`) makes
creation idempotent, so a re-run is safe.

Cross-schema ordering -- "must the v2 event table exist before this v1 backfill?"
-- is answered by the sequence number, the same way it is answered within one
schema today. That is the main thing a single sequence buys: there is no version
*pair*, so there is no matrix of which v1 versions are compatible with which v2
versions, and no ordering question between two independent sequences.

### What a fresh install creates

This is the one place the decision needs an explicit answer, because
`install()` iterates registered entities: registering the v2 entities in Base
means a brand-new install creates the full 1.x star **and** the v2 event table.

Measured on a real install: **19 empty tables cost 27.0 MB**, and the cost is not
the tables. 15 of the 19 are partitioned, and a partitioned table pays a
tablespace per partition -- `owa_commerce_line_item_fact` holds zero rows and
occupies 1.3 MB across 14 partitions. An unpartitioned empty table is 16-32 KB.
So the bill for an unused v1 schema is roughly 27 MB, essentially all of it
partition overhead on fact tables that will never receive a row.

**Create both.** 27 MB is not a reason to add conditional entity registration,
and creating both is what "one schema" means. It also preserves something the
migration section already requires: an install that can accept a v1 tracker
beacon without a schema change. A fresh install that had only v2 tables would
have to be *migrated forward into v1* to serve an old snippet, which is absurd
in exactly the way this decision exists to avoid.

The real cost is presentational, not structural: on a fresh install the v1
reporting UI renders empty reports rather than being absent. Since the two
reporting UIs are already separate, the fix is that v2 does not link to v1's,
not a schema change.

### The sharp edge: a dropped v1 is a supported state

Dropping v1 is the user's decision and can happen at any time, after which the
schema version keeps advancing. So **every update written from that point on must
tolerate the v1 tables being absent** -- not just updates that intend to touch
them.

This is sharper than it first reads, because of how the apply loop fails. It
stops at the first failure and returns, so an update that assumes `owa_session`
exists does not merely fail itself: it **blocks every later update** for every
install that has dropped v1. And since `isUpdateRequired()` intercepts every
controller, those installs are then wedged out of the admin entirely by an update
about a table they deliberately removed.

The mitigation is a convention, and it needs to be a stated one rather than a
habit: an update that touches a table must first establish that the table is
there, and treat absence as success rather than as an error. Tolerating a missing
table is not defensive coding here -- absence is a *legitimate configuration*,
not a fault, and the update has nothing to do.

Worth building rather than documenting: a helper on the update base class that
makes the tolerant form the short one. A convention that requires remembering is
a convention that fails on the update written eighteen months from now, which is
precisely when the installs that dropped v1 are most numerous.

### One version gates both UIs

`isUpdateRequired()` intercepts every controller, so a v2-only update holds back
the v1 reporting UI, and a v1-only update holds back v2's. An install that has
never enabled v2 tracking still has to apply v2 updates before it can report.

That is the correct consequence of the decision rather than a defect of it -- one
install has one state, and the alternative is two version numbers whose skew is
itself a thing to reason about. But it should be said out loud, because it means
v2 updates are not optional for v1-only users, and the update that creates the v2
tables will land on installs that asked for none of it. That is an argument for
creating those tables in **one** update rather than dribbling them across
several, and for that update declaring `isCliModeRequired()` -- the mechanism
already exists (`Core/Module.php:629`) -- so the expensive creation happens in a
window someone chose.

### Rejected

- **A version per schema.** Produces a compatibility matrix, an ordering question
  between two sequences with no shared clock, and a skew state to reason about,
  in exchange for letting v1-only installs skip updates they can already apply as
  no-ops.
- **A separate module for v2.** Superficially clean -- its own entities, own
  updates, own version -- and it reintroduces exactly the cross-sequence ordering
  problem, while splitting handlers and the registry across a module boundary
  that no longer matches how anything is deployed.
- **Replaying updates on fresh installs** so one path builds every schema. The
  install path deliberately does not do this, and making it do so would mean
  every historical update stays executable forever against a schema it no longer
  recognises.

## Query-time sampling: no

**Decided: v2 does not sample.** Every reported number is computed over every
matching row.

GA samples because it answers for millions of properties on shared capacity, so
bounding the cost of one query protects everyone else. A self-hosted install
inverts every term of that: the operator owns the hardware, runs one tenant, and
chose the box. There is no one else to protect, and the person who would suffer
the imprecision is the same person who could have provisioned for the query.

Sampling is also the single thing users most distrust GA for -- a number that
changes when you narrow a date range, with a threshold nobody can see. "Every
number is exact" is a real competitive property for a self-hosted analytics tool,
and it is worth more than the scale it costs.

Scale is answered elsewhere and better: partition pruning bounds what a query
touches (measured above: 4,080 rows versus 405,217 for the same answer), the
rollup bounds what a session metric costs, and an installation that outgrows a
row store has the columnar path the storage abstraction already anticipates.
Those bound cost **without** trading away correctness. Sampling would be reaching
for the one lever that does.

## Packaging and distribution

**Unchanged from 1.x.** The same tarball, built by the same Release Packager
workflow, dropped into a directory and installed through the same wizard or CLI.
No Composer requirement for end users, no build step at install time, no new
runtime dependency.

This is not inertia. The distribution model is the reason a self-hosted analytics
tool gets installed at all, and a rewrite is exactly the moment one is tempted to
"modernise" it into something that needs a toolchain on the target machine. The
schema changing is not a reason for the delivery mechanism to change, and the two
decisions have no coupling.

The one packaging-adjacent thing v2 does add is a hard dependency on the
scheduler -- the rollup means a correct installation now requires the cron entry
that 1.x could omit. That belongs in install verification and in the
`schedule-status` output that already exists, not in how the software is shipped.

## Testing strategy

### The parity harness is the primary test, and dual-write is what makes it possible

For a total schema change there is no reference implementation to test against --
except that the dual-write decision creates one. Both pipelines consume identical
events, so v1 *is* the oracle for everything v2 is not deliberately changing.

The comparison surface is already enumerated: **5,493 valid metric x dimension
pairs** from the live registry. That is a generated test matrix, not a hand-written
one. For each pair, query both schemas over the same window and compare, then
apply the two exclusion lists -- the intended differences (bounce, visit duration,
exit page, goals, the 11 dead `*InVisit` dimensions), which are compared and
expected to diverge, and the early-cutover features, which have no v1 side and
are absent from the matrix entirely. Anything remaining is a defect with a name.

Two properties make this stronger than any unit test that could be written
instead. It runs against **real traffic**, so it exercises the distributions and
malformed inputs that fixtures never contain. And it is **exhaustive over the
API surface** rather than over the cases someone thought to write down -- 5,493
pairs is far past what anyone hand-authors, and the pairs nobody would think to
test are precisely where a schema change breaks something quietly.

This should be a command, not a script somebody keeps locally, and its output
should be the parity report the cutover decision is made from. "We reached
parity" then means a specific artefact showing a specific empty list.

### What the existing suite is worth

77 test files, 509 test methods, plus 14 Playwright specs across three configs
and five phpstan configurations. The encouraging finding: only 8 of the 77 files
are named for star-schema artefacts. Most of the suite tests things a schema
change does not touch -- CLI commands, settings persistence, authentication,
validation, the queue, the scheduler -- and ports unchanged.

What genuinely dies is the part testing the star's *mechanics*: dimension FK
derivation, id widths, session upsert semantics. That work is not wasted, but it
should be recognised as expiring rather than migrated out of loyalty.

The install-flow and self-hosted Playwright configs matter **more** in v2, not
less. v2 creates a new schema on a fresh install, which is the path with no
existing users to notice a break, and the self-hosted runner is what proves the
result works somewhere other than the machine it was written on.

### Two pre-existing hazards that a rewrite amplifies

Both are already known here, and both make a suite look healthier than it is --
which is the specific failure a rewrite cannot afford, because the suite is the
only thing standing between a rewrite and a silent regression.

**Order-dependent tests**, which pass only because an earlier file warmed a
registry or autoloaded an alias. CI already runs an isolation sweep executing
each file alone (`ci.yml:91`). That sweep is more valuable during a rewrite, not
less, because a new schema means new shared registries and new opportunities for
one test to leave state another depends on.

**Vacuous assertions** -- double-quoted PHP needles containing `$this->`
interpolate away and make source-scanning assertions pass unconditionally. A
rewrite writes a great many tests quickly, which is exactly the condition that
produced these the first time. Worth a lint rather than vigilance.

To which a rewrite adds a third: **coverage that silently narrows**. A test
deleted because it tested the star, and never replaced, leaves no trace. The
parity harness covers the reporting surface, but not the write path, the tracker
or the admin -- so those need an explicit accounting of what was dropped and what
replaced it, rather than a green suite that is green because it now asks less.

### Claims made in this document that are only real if tested

The sections above generate their own test list. Each of these is a design claim
that is worth nothing unstated in a test, because each is an intention that code
can quietly stop honouring:

| claim | the test |
|---|---|
| the rollup is eventually consistent | an event arriving after its period was rolled up folds in on the next run |
| the lateness horizon is the trailing window | an event older than the window does *not* fold in, and lands in the event table anyway |
| the rollup is convergent | running it twice changes nothing the second time |
| fan-out isolation is asymmetric | a throwing v2 handler fails neither the v1 write nor the request |
| a dropped v1 is supported | the full update sequence runs clean on an install with the v1 tables removed |
| realtime queries prune | the generated SQL carries a closed range on the partition key, asserted on the SQL, not the timing |

The last one is deliberately a test of the *query text* rather than of elapsed
time. A timing assertion on a small fixture would pass whether or not pruning
happened, which is how the 405,217-row scan measured above survives review.

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

### The real argument for markers is grain, not payload

An earlier framing here justified `session_start` and `first_visit` by what they
*carry* -- attribution captured at the moment it is known. That is true and it is
the weaker half. The stronger reason is **dimensional combination**, and it is
the whole purpose `owa_session` serves in 1.x.

A metric and a dimension can only be combined if a row exists at their shared
grain. `owa_session`'s 121 columns are not hoarding: they are what makes
"sessions by landing page", "sessions by campaign", "sessions by country"
answerable at all. Strip the session-grain table out of the schema and those
combinations do not become slower, they become **unavailable** -- so a single
event table must put the session grain back somewhere, and there are only two
places to put it.

| | session grain lives in | breakdowns available by |
|---|---|---|
| **Marker events** | the event table itself | **every dimension an event carries** -- unbounded, no schema change |
| **Rollup only** | the rollup's columns | only what the rollup denormalises |

**The custom variables prove the point.** 1.x carries `cv1_name`/`cv1_value`
through `cv5_name`/`cv5_value` on the session -- ten columns implementing exactly
five slots. That fixed-slot design is not a preference; it is what a fixed-width
session row forces. Ride the same data on an event's parameter map and the limit
disappears, which is precisely the "segment reporting by custom variables without
registering them first" capability wanted for v2. The five-slot wart *is* the
cost of holding session grain in a fixed row.

Excluding 46 goal columns and 12 date parts, `owa_session`'s remaining 63 columns
are roughly 26 genuine dimensions, 10 custom-variable slots, 13 aggregates and a
tail of prior-session bookkeeping. So a dimension-carrying rollup would not be
121 columns -- but it would be about 30, and that number only grows.

### Which leaves a consistency trap, and it decides the design

If session *totals* come from the rollup and session *breakdowns* come from
marker events, the two disagree whenever a marker is lost -- the breakdown will
not sum to the total. That is the most corrosive class of reporting bug: both
numbers are defensible, neither is wrong in isolation, and a user who notices
stops trusting the whole product. **One authority per metric, no exceptions.**

So the choice is forced, and it goes to the rollup:

- **The rollup is the authority for session counts and their breakdowns.** It is
  derived from every event, so it cannot be erased by a lost packet, and it is
  exact where GA4 must approximate.
- **It therefore carries the session's dimensional context**, taken from the
  session's own events -- landing page, source, medium, campaign, device, geo --
  plus a parameter map for custom variables rather than five slots.
- **Markers keep the roles a rollup cannot fill**: something to mark as a key
  event, a streaming signal, and the attribution the client knows at that instant
  and the server cannot reconstruct.

**The bounded-column objection is answered by the rebuild, and this is the part
worth noticing.** A rollup's column list is not a ceiling; it is a current state.
Because the rollup is *derived* from events that are retained, adding a
session-scoped dimension later is a rebuild -- measured at 50.4 s over the full
corpus -- and it applies **retroactively to all existing data**, with no change to
what is collected.

That is post-hoc enrichment, recovered. The star's one measured advantage in this
document was enrichment at 589x, and the reason it won was that a dimension row
could be rewritten without touching facts. A derived rollup restores exactly that
property at the session grain: rewrite the summary, leave 1.19M events alone. The
capability was never really lost, only relocated.

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
