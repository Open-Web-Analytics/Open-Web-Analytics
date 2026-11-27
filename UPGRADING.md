# Upgrading OWA

This file is for upgrading to 2.0 from 1.x, and within 2.0. It lists what
behaves differently after the upgrade, the 1.x interfaces 2.0 removed and what
replaces each one, and what 2.0 itself deprecates.

**2.0 upgrades from 1.14 only.** Upgrade an older 1.x installation to the latest
1.14 release first. The move to 2.0 runs a one-time migration of the 1.x history
from the command line; the wiki's
[Upgrading from 1.x](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/Upgrading-from-1.x)
page covers it, and
[Changes from 1.x](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/v2-Changes-from-1.x)
lists the differences area by area.

Audience: site owners upgrading an installation, authors of third-party modules,
site owners with local template overrides, and anyone maintaining a custom theme.

---

## Moving from 1.x

### Tracking stops from the deploy until the first update has run

2.0 writes each event to `owa_event_raw`, which its first update creates. A
tracking request that arrives after the 2.0 code is in place and before that
update has run is dropped, and is not kept to retry. The update takes seconds:
run `php cli.php cmd=core-update-schema` straight after deploying. Queueing on 1.x does not
cover the window, since 2.0 does not read 1.x's queue.

### A 1.x site that queues beacons

Before deploying 2.0, on 1.x: turn queueing off (remove `OWA_QUEUE_EVENTS` from
`owa-config.php`, or set it to false), then run
`php cli.php cmd=processEventQueue` until the queue is empty. Lines still in
1.x's file queue when the update runs are moved, unchanged, to an archive file
in the queue directory, `update062-v1-queue-<date>-<time>.txt`, and are not
ingested; the update says how many lines it moved and where. Rolling the update
back leaves the archive where it is.

To queue beacons on 2.0, define `OWA_QUEUE_TRACKING_EVENTS` after the upgrade.

---

## Behaviour changes in this release

These are not deprecations — they change what happens on upgrade. Those with a
way back say what it is.

### A release with a new tracker is applied as an update

A release that changes the tracker now asks for its update to be applied --
`php cli.php cmd=core-update-schema`, or Apply on the update notice in the admin screens --
even when it changes no schema. That update publishes the Profiles' tracking
bundles that are stale or missing: in the run from the command line, or as a job
for the scheduler's next minute when applied from the screen. A release that
changes neither the tracker nor the schema asks for nothing.

Until it is applied, the admin screens show the update notice. Tracking,
scheduled jobs, the other command line commands and the REST API are
unaffected, unless the release also changes the schema, which stops them until
it is applied.

### Tracked text is stored as it was sent

A page title, search term, URL, campaign name or custom property value is
stored as the text the tracker sent, and escaped where it is shown. It used to
be HTML-escaped and stripped of anything shaped like a tag on the way in, so
"Tom & Jerry" was stored as `Tom &amp; Jerry`, "Café" as `Caf&eacute;`, and
"a<b and c>d" as `ad`.

**If you read OWA's data from the REST API or the database**, values are now
plain text: escape them for wherever you put them, as you would any text.
Values stored before this release keep their escaped form.

### The tracker honours Global Privacy Control as well as Do Not Track

A browser reporting Global Privacy Control (`navigator.globalPrivacyControl`)
is now treated like one reporting Do Not Track: the tracker runs no command and
sends nothing.

**Way back:** the Tracking Tag setting **Honor Privacy Signals**, at the
install, a Property or an Observation Profile, turns off both checks; the
Profile's bundle and classic tag then carry the tracker option. A tag can also
queue the option itself, before the page view:

```js
owa_cmds.push(['setOption', 'honorPrivacySignals', false]);
```

It is read from the queue before any command runs, so queue it in the tag; a
`setOption` on a tracker that is already running has no effect on it. Browsers
under automation are still refused, as before.

### `OWA_DEBUG` replaces `OWA_ERROR_HANDLER`

Debug mode is `define('OWA_DEBUG', true);` in `owa-config.php`. It writes debug
lines to OWA's error log as well as notices and errors, routes PHP's own errors
there, and has the tracker log to the browser console.

`OWA_ERROR_HANDLER` is no longer read. A 1.x config that sets it to
`'development'` logs at the normal level until it is replaced with `OWA_DEBUG`;
`'production'` was already the default and needs nothing. Uncaught exceptions
are handled the same way in either mode.

The log is written without Monolog, which is no longer a dependency. Its file,
line format and permissions are unchanged.

### Strict SQL mode is now the default

OWA used to send `SET SESSION sql_mode=''` on every connection, which disables
MySQL's strict mode. That silently converted bad writes into wrong data rather
than errors: a value too long for its column was truncated, and a non-numeric
value written to an integer column became `0`.

That was not theoretical. Two live installs were found carrying rows whose
`yyyymmdd` — the fact-table partition key, and the column every date-range report
filters on — had been coerced to `0`, which made those rows invisible to
reporting and put them in the catch-all partition.

The default is now `STRICT_ALL_TABLES`. A write that would previously have been
coerced now fails and is logged instead of storing something that looks like
data and is not.

**Everything OWA itself does was fixed before this default moved** — the full
test suite and the end-to-end ingestion path both pass under strict, on both
database drivers. A **third-party module** that writes through the entity layer
may not have been. If one starts failing after upgrade, revert in
`owa-config.php`:

```php
define( 'OWA_DB_SQL_MODE', '' );                  // the old, permissive behaviour
define( 'OWA_DB_SQL_MODE', null );                // leave whatever the server sets
define( 'OWA_DB_SQL_MODE', 'STRICT_ALL_TABLES' ); // the new default, stated explicitly
```

Please report it rather than leaving the override in place: a write that strict
mode rejects was storing wrong data before, not right data.

### 64-bit PHP and zlib are required

OWA's ids are 64-bit integers, so a 32-bit build of PHP is refused. The `zlib`
extension is required for session recordings. The install wizard, System Health
and `cmd=instance-info` check both, along with the database driver.

### PDO is preferred over mysqli where it is available

`db_type = 'mysql'` now means "MySQL", and OWA reaches it through PDO wherever
the `pdo_mysql` extension is present, falling back to `mysqli` where it is not.
**No configuration change is required**, and a host that has `mysqli` but not
`pdo_mysql` keeps working, provided that `mysqli` is built on mysqlnd, as
distribution packages are: the driver reads prepared statements with
`mysqli_stmt_get_result()`.

The reason to move is that PDO carries bound parameters, so values are no longer
escaped into the statement text. To pin a driver explicitly:

```php
define( 'OWA_DB_TYPE', 'mysqli' );     // force the legacy driver
define( 'OWA_DB_TYPE', 'pdo_mysql' );  // force MySQL over PDO
```

Third-party drivers (`owa_db_<type>`) are unaffected in selection, but note that the driver interface gained an optional `$params`
argument on `query()`, `get_results()` and `get_row()`. A driver that overrides
those with the old signature will need it added.

### The navigation is a rail of sections and a column of pages

The report sections are a rail at the left, with Settings at its foot; the
current section's reports are the column beside it. Settings screens use the
same rail and column. The header no longer carries Analytics, Settings or
Donate: Donate is in the help menu.

For module and template authors:

- `Template::makeNavigationMenu()` and the `report_nav.php` and
  `hierarchy_nav.php` templates are removed. `Template::navRail()`,
  `settingsColumn()` and `profileTabs()` build the navigation, and
  `nav_shell.php` draws it.
- Navigation a module registers with `addNavigationSubGroup()` and
  `addNavigationLinkInSubGroup()` appears as before: a group is a section of the
  rail and its links are its column. A group may set `overview_label` to name
  its own entry in the column, which is "Overview" otherwise.
- `nav_header` still adds entries to the header, which has none of its own.
- A settings page registered into the `Observation Profile` section is a tab of
  the Profile's page rather than an entry in the column. The hierarchy nav has a
  new `Profiles` section: one entry per Profile of the Property, and Add profile.

### The report grid is a plain table, and jqGrid is gone

A report's grid (`OWA.dataGrid`) draws its own table. Above it are the Filter
button, a chip per filter, and Rows per page, Go to and the pager. The dimension
headings are pickers, with a plus to add a second dimension. A Total row comes
first, and each count shows its share of the total.

For module and template authors:

- free-jqgrid and `ui.jqgrid.css` are no longer bundled. The table is
  `.owa_gridTable`, a data row is `tr.owa_gridRow`, and a cell carries its column
  name as `data-column`.
- `OWA.dimensionSelectors`, `OWA.constraintBuilder` and `OWA.dimensionPicker`
  are removed. A screen that builds its own filter control uses the filter
  pills: `OWA.filterPills` over the markup in `filter_pills.php`, with
  `OWA.filterPills.choices()` to list a result set's `relatedDimensions` and
  `relatedMetrics` by group. `OWA.util.strpos` is removed.
- The formatters a report names in `formatters` are `OWA.dataGrid.formatters`
  rather than jqGrid's `fmatter`; they take the same arguments.
- Grid options: `showRowNumbers` is on by default; `compact` is new (no row
  numbers, Total row or shares, for a card); `minGridWidth` is gone.
- The grid's element also publishes `page_change` (page, resultsPerPage).
- Each entry of a result set's `relatedMetrics` carries its `metric_type`.

### Custom reports and funnels are one list

Custom Reports lists reports and funnels together, with a Type column, and the
Visualizations section is gone from the navigation. New asks whether to make a
report or a funnel. A star on each row adds it to the reader's favorites, which
sort to the top and are listed in the Custom Reports column. The old
`base.visualizations` address opens the same list.

A custom report or funnel opens for its author, for administrators, and for
anyone else who can view reports on its Property only while it is shared. To
anyone else it is not found. Turning sharing off closes it to them again,
links already sent included.

For module and template authors:

- A navigation link may carry a `heading`, which groups it in the column, and an
  `icon_class`. A group may set `keeps_column` to have a column with no links
  under it, and `current_for` (sets of params; a value ending in `*` matches by
  prefix) to be current on other screens.

### Pie charts are share charts

A `pie` widget draws each value's share of its metric as a ranked list: the five
largest, then the rest as Other, each with its percentage and a bar. This
applies to the Dashboard, Traffic and custom reports. The widget type is still
`pie` in a report definition, so saved reports need no change; the builder
calls it "Share chart".

For module and template authors:

- `OWA.pieChart` and flot's pie plugin are removed. `OWA.shareChart` draws the
  list, and a result set explorer's `options.pieChart` and `makePieChart` are
  now `options.shareChart` and `makeShareChart`. `valueLabels` and `numSlices`
  work as before.

### The custom report editor is its own screen

The editor's header is the report's name, Shared and Cancel / Save. Widgets are
cards on the report's twelve columns, moved by dragging or with the arrow keys;
a widget's settings open in a panel at the right, with Width (a third, half, two
thirds or full) and Height (short or tall), the sort as a field and a direction,
and filters as pills. Reports saved before keep any width or height they have.
A widget's type can be changed from its panel; what the new type cannot use, such
as a second metric on a table card, is dropped.

For module authors:

- A `ReportController` can call `hideReportHead()` to leave out the title row,
  for a screen whose own header is its title.

### The funnel builder: steps, a filter and a live preview

The funnel builder has the report editor's header (name, Shared, Cancel / Save).
A step is "Page is" a path or "Event is" a goal event definition, and steps move
by dragging or with the arrow keys. A step's name is no longer asked for: a step
with none is named for its page or definition, and a stored name is kept while
its step is unchanged. A funnel may carry its own filter, which applies to every
step under any filter its reader adds, and the funnel says so. A preview beside
the steps counts the funnel as it stands over the last 30 days.

A funnel opened with no period on its URL is now counted over the default
reporting period that its header names. With a filter it failed with a server
error, and without one it counted all time.

For module and template authors:

- A funnel definition may hold `constraints`, in the format a widget's take.
- `OWA.filterPills` (with the `filter_pills.php` markup) is the Filter button,
  pills and form both builders use.
- `base.visualizationPreview` answers the counts for steps sent to it, as JSON.

### Settings screens have no breadcrumb, and Events is under Visitors

The line above a settings screen naming its Organization, Property and Profile
is gone; the Settings column and the Property chooser say where you are. The
Events report is in the navigation, last under Visitors.

For module authors:

- `hierarchy_tier` and `hierarchy_root_label` are no longer read. A screen that
  sets them is unaffected.

### Goal events: a count mode, a value, and new metric names

A goal event definition counts **once per event** (every match adds a goal
event) or **once per session** (the first match in a session adds one). A new
definition defaults to once per event; one migrated from 1.x counts once per
session, as it always did. The definition's value is now carried onto its goal
events, in the Property's currency; with no value, a goal event met by a
purchase carries the purchase's revenue. Goal events stored before this carry
no value.

| Before | Now |
|---|---|
| `goalConversions` (Goal Conversions) | `goalEvents` (Goal Events) |
| `goalConversionRatePerSession` (a percentage) | `goalEventsPerSession` (a decimal) |
| `goalConversionRatePerUser` (a percentage) | `goalEventsPerUser` (a decimal) |
| (none) | `goalValue` (Goal Value) |
| metric set `conversions` (Conversions) | metric set `goals` (Goals) |

Update078 renames them in saved custom reports, visualizations and shared
charts. A REST call or link naming an old metric fails until it uses the new
name. Update077 adds the `value` column to `owa_event_raw` and every cube.

### Funnel steps are events

Every funnel step is an event and, optionally, conditions on what it carries,
combined all or any, with the operators a goal event definition's conditions
use. A page is a `page_view` with a `page_path` condition. A condition can also
test a custom dimension applied to the funnel's Property. A funnel no longer
names a goal event definition.

Update079 rewrites saved funnels:

| Step before | Step now |
|---|---|
| a page path, `/checkout?step=2` | `page_view` where `page_path` is `/checkout` and `page_query` is `step=2` |
| a goal event definition | its trigger event, with a copy of its conditions and all/any |

A step whose goal event definition was deleted, or matched nothing, is left as
it is and named in the update's output; the funnel says which step to replace.
The funnel's headline figure is now **Completion Rate**, the share of those
who entered who reached the last step.

### Spam detection replaces "Log Requests From Known Robots"

The `log_robots` setting is gone. Every beacon now gets a spam score, the sum
of the points of the spam rules it meets, and a beacon whose score reaches the
spam threshold is not stored. Both settings are on Settings > General, under
Tracking Request Processing:

| Setting | Values | Default |
|---|---|---|
| `spam_detection` (Spam Detection) | `normal`: the user agent names a robot or crawler, as robot detection did. `strict`: also the request's headers, the page it came from and its URL | `normal` |
| `spam_threshold` (Spam Threshold) | a number of at least 0.1, one decimal place; blank stores every beacon | `5.0` |

Each stored row records its score and the rules it met in
`owa_event_raw.spam_score` and `spam_rules` (Update082). Blocked beacons are
counted per day, Profile and rule in `owa_spam_block` (Update083), and System
Health shows today's and yesterday's counts under Tracker ingest.

Update084 reads the install's `log_robots`:

| `log_robots` stored at the install | After Update084 |
|---|---|
| on | `spam_threshold` is blank: nothing is blocked, as robots were stored before |
| off, or not stored | nothing changes: the default threshold blocks what was dropped |

Either way the `log_robots` rows are removed; the update's down() puts them
back. A `log_robots` set in `owa-config.php` is not migrated and is no longer
read: to keep storing robots, set `spam_threshold` to blank there or on the
settings screen instead.

### Stored 1.x settings nothing reads

Update085 deletes the stored rows of `is_embedded_admin_user_password_reset`,
`resolve_hosts` and `fetch_refering_page_info`. No module declares them, so
their values were ignored. The update's down() puts them back.

### The object cache lasts one request

The File Cache and Memcached modules are removed. Rows OWA looks up are kept
for the rest of the request and read from the database again by the next one.
The persistent tier saved about a third of a millisecond a lookup and was off by
default; it could serve a row up to a week out of date.

Also removed: `OWA_CACHE_OBJECTS`, `OWA_CACHE_DIR`, the `cache_objects`,
`cacheType`, `cache_dir`, `default_cache_expiration_period`, `memcachedServers`
and `memcachedPersistantConnections` settings, `php cli.php cmd=flush-cache`,
and the installer's check of `caches/`. A config file that still defines either
constant is not an error. `owa-data/caches/` is no longer written, and can be
deleted.

Update086 deletes the stored rows of the two modules and of those settings, so
an install that ran the File Cache no longer lists `fileCache` as an active
module. The update's down() puts them back.

### Sign-in sessions expire, and signing out ends them everywhere

A sign-in ends one to two days after its last request. Signing out, or changing or
resetting a password, ends every session of that user, on every device.
Update087 adds `owa_user.session_epoch` for this; its down() removes it.

Session cookies issued before the upgrade sign nobody in: every user signs in
again once. Application passwords and overlay tokens are unchanged.

### Domains are stored without a scheme

A Profile's or Property's domain is a host, with a port when the site is served
on one: `www.example.com`, `localhost:8080`. 1.x stored the scheme the
installer's select added, `https://www.example.com`. Update088 removes a
leading `http://` or `https://` and trailing slashes from `owa_site.domain` and
`owa_property.domain`; its down() leaves them without one. A domain typed or sent
with a scheme (the settings screens, `POST /v1/sites`, `cmd=add-site`, the
installer) is stored without it. A heatmap opens its page on the scheme and
host the page's clicks recorded, and on the domain over https when none did.

### Domain Aliases is gone

The Domain Aliases setting is removed from the Profile's Observation Settings.
A page that arrives on a host other than the Profile's domain or one of its
subdomains is stored as it arrived and is not rewritten to the domain. To track
another domain, add it as its own Profile in the same Property. Update090
deletes the stored `domain_aliases` rows; its down() does not put them back.

### The Hello example module is removed

`modules/Hello/` no longer ships. Its code, with an explanation of each part, is
the wiki page [Writing a Module](https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki/v2-Writing-a-Module).
Update091 deletes the stored `hello` rows, so an install that had it active no
longer lists `hello` as an active module. The update's down() puts them back.

### Three privacy and announcement settings are removed

| Setting | What changes |
|---|---|
| Log Requests From Named Users (`log_named_users`) | A request from a user signed in to OWA is recorded like any other. |
| `log_visitor_pii` (`OWA_LOG_VISITOR_PII`) | A beacon's `user_id`, `user_name` and `user_email` are stored as sent, and 1.x's `user_name` migrates as `user_id`. A config that still defines the constant gets a notice in `cmd=server-check` and System Health. |
| Announce New Visitors Via E-mail (`announce_visitors`, `notice_email`) | No e-mail is sent when a session starts. The Admin Notice E-mail Address is unchanged. |

Update098 deletes their stored rows at every scope. Its down() puts them back.

### The API key and request signature are refused

1.x signed a REST request in with the user's API key (`apiKey`, or the
`X-API-KEY` header) and an HMAC of the request URL (`signature`, or
`X-SIGNATURE`). **2.0 accepts neither.** A request that carries one is answered
401 with a message naming application passwords, and is not signed in by any
other credential it also carries.

**Instead.** An application password, sent in the `Authorization` header
(`Bearer <token>`, or Basic with the user name and the token). Each user makes
them under My Preferences → Application passwords, or with
`php cli.php cmd=app-password-create`; each can be read-only and revoked on its
own. A script that calls the API with an API key, including a WordPress
plugin's server-side calls, needs an application password instead.

**Also removed:** the API Key row on the user edit screen, and the `api_key`
column of `owa_user`, which Update089 drops. Its down() adds the column back
empty.

### Report periods

The predefined periods are those the date range panel lists: `today`,
`yesterday`, `this_week`, `last_seven_days`, `last_week`, `last_28_days`,
`last_thirty_days`, `this_month`, `last_month`, `last_90_days`,
`quarter_to_date`, `this_year` and `last_year`, and `date_range` with its own
dates.

| 1.x period | 2.0 |
|---|---|
| `same_day_last_week`, `same_week_last_year`, `same_month_last_year` | A report link naming one opens on the default reporting period. The REST API refuses them. |
| `last_half_hour`, `last_hour`, `last_24_hours`, `time_range` | Refused as not a reporting period, by the report screens and the REST API. |

A `default_reporting_period` setting that names any of them is read as
`last_seven_days`.

### The command line: exit codes and arguments

`cli.php` exits **0** when a command did what it was asked or declined on
purpose, **1** when it failed (including a command this server's role does not
run, and pending schema updates), and **2** when the command line is wrong: no
`cmd=`, a command that does not exist, an argument the command does not take, or
a value it cannot read. 1.x exited 1 only for a command line it could not
parse or one with no `cmd=`, and 0 otherwise.

A command takes only the arguments its `usage()` declares. Given any other, it
does nothing, prints which it does not take, and exits 2. A scheduled job whose
`params` in `OWA_SCHEDULED_JOBS` name an argument its command does not take is
recorded as failed and not run.

These 1.x commands are not in 2.0: `processEventQueue`,
`flush-processed-events`, `flush-cache`, `clear-field-values`,
`rederive-dimension-ids`, `backfill-visitor-acquisition`, `repair-geo-encoding`,
`update-document` and `partition-init`. The upgrade creates the event table and
each reporting cube already partitioned.

### Server roles: `OWA_SERVER_ROLE`

A server's role says which requests it serves. With nothing set it is `full`,
which serves everything and runs the scheduler, as 1.x did. To split an install
across servers, set a role on each; `owa-config-dist.php` shows the minimal
configuration of each.

| Role | Serves | Scheduler | Database |
|---|---|---|---|
| `full` (default) | log.php, index.php, api/, the CLI, install.php | yes | yes |
| `collector` | log.php, and `cmd=drain-tracker-ingest forward_only=1` | no | none: every beacon is queued, and a queue other than the file queue is required (`OWA_SQS_QUEUE_URL`) |
| `worker` | the CLI, every command | yes | yes |
| `console` | index.php, and the CLI commands for passwords, app passwords, sites, modules and `schedule-status` | no | yes |
| `api` | api/ | no | yes |

A comma list combines roles (`console,api`). `cmd=server-check` and
`cmd=instance-info` run on every role. A request the role does not serve
answers 404; a CLI command not marked for it is refused. An unknown role, a
missing database setting or key on a role that needs them, a collector with no
queue, or two queue backends at once stop every request, with one line in the
error log. `php cli.php cmd=server-check` prints the role and what is missing.

| 1.x / earlier 2.0 constant | 2.0 |
|---|---|
| `OWA_QUEUE_EVENTS` (1.x) | `OWA_QUEUE_TRACKING_EVENTS`. The 1.x name is still read while the new one is not set; System Health and `cmd=server-check` ask for the rename. |
| `OWA_USE_STATIC_CONFIG_ONLY` (1.x) | `OWA_SERVER_ROLE`, `collector` for a server that only receives beacons. The 1.x switch is still read, and still only stops stored settings being read at boot: it does not make a server a collector. System Health and `cmd=server-check` ask for the role. |
| `OWA_SCHEDULER_ENABLED` | Removed. The role decides: `full` and `worker` run the scheduler. |
| `OWA_QUEUE_TRACKER_INGEST` | Removed: `OWA_QUEUE_TRACKING_EVENTS`. |
| `OWA_TRACKER_INGEST_QUEUE_TYPE` | Removed. The queue is the file queue unless a queue module's own constant is set: `OWA_SQS_QUEUE_URL` makes it AWS SQS. |
| `OWA_TRACKER_INGEST_DRAIN` | Removed. The servers whose role runs the scheduler drain the queue. |
| `OWA_ACTIVE_MODULES` | Removed. A module with a constant of its own is active while that constant is set, on any server, and the Modules screen cannot turn it off: `OWA_SQS_QUEUE_URL` for the AWS SQS module. Other modules are switched on in the Modules screen. |
| `OWA_SQS_REGION` | Optional: the region is read from `OWA_SQS_QUEUE_URL`. It still overrides. |
| `OWA_DB_TYPE` | Optional: defaults to `mysql`. |
| `OWA_PUBLIC_URL` | Required on every role but `collector`. |
| `OWA_TRACKING_URL`, `OWA_ASSETS_URL` | New, optional: see below. |

The stored 1.x setting `queue_incoming_tracking_events` is no longer read.

### Tracking and assets URLs

`OWA_PUBLIC_URL` is required on every role but `collector`. Two optional URLs
split where browsers reach OWA:

| Constant | What is reached there | Default |
|---|---|---|
| `OWA_PUBLIC_URL` | The screens, the REST API, links in mail, signed API requests | none: required |
| `OWA_TRACKING_URL` | log.php: tracking tags and bundles send beacons there | `OWA_PUBLIC_URL` |
| `OWA_ASSETS_URL` | The built files under `public/`: report scripts, styles, fonts and images, the tracker and its chunks, the Profiles' bundles (`public/tracker/<id>.js`) | `OWA_PUBLIC_URL` + `public/` |

Changing the tracking or assets URL makes every Profile's bundle stale, and the
next publish rewrites it. Set them on the servers that build pages and bundles
(full, console, worker).

**A CDN.** First choice: put the CDN in front of OWA's own hostname and leave
`OWA_ASSETS_URL` unset; it is the same origin, so nothing else is needed. A
separate assets hostname (a CNAME is still a different origin) needs the CDN to
send `Access-Control-Allow-Origin` for the web fonts and anything fetched by
script; plain `<script>` and `<link rel="stylesheet">` loads, the tracker's lazy
chunks among them, work across origins without it. Either way, the bundles in
`public/tracker/` keep fixed URLs (tags embed `tracker/<id>.js`): give that path
a short cache time or purge it on publish. Everything else is versioned
(`?version=`) and can be cached long. In a split deployment the server holding
`public/`, where `publish-trackers` writes, must be the CDN's origin or share
`public/tracker/` with it.

---

## Deprecated in 1.10.0, removed in v2.0

### 1. Bare template variables and `$this` inside templates — REMOVED in v2.0

**What changed.** A template receives its view data through an explicit `$view`
object and reaches the template helpers through `$view`. In 1.10 through 1.x,
`extract()` also made every view variable a bare local and the include made
`$this` the Template. **v2.0 does neither**: `fetch()` extracts nothing, and it
includes the template from a static closure, so `$this` does not exist there.

```php
<!-- 1.x, no longer works -->
<?php $this->out( $headline ); ?>
<?php foreach ($tabs as $tab): ?>

<!-- v2.0 -->
<?php $view->out( $view->headline ); ?>
<?php foreach ($view->tabs as $tab): ?>
```

**Why.** A key the controller never set was simply an undefined variable — a
warning in scalar context and a **fatal** in `foreach` (`foreach() argument must
be of type array|object, bool given`), raised inside the template rather than at
the controller that forgot the key. Nothing declared what a template required,
so no tool could check it. Reading a never-set key through `$view` raises an
`OutOfBoundsException` naming the key and the template instead.

**What breaks.** Any template still written the 1.x way:

- third-party module templates (`modules/<Module>/templates/`)
- site-owner overrides (`modules/<Module>/templates/local/`)

A `$this->` call fails with `Using $this when not in object context`. A bare
variable read is undefined — a warning, `null`, or the `foreach` fatal above.
**A bare variable inside `isset()` or `empty()` fails silently**: it is always
unset, so the branch it guards never runs.

**Migrating.** Replace each bare view variable with `$view-><name>` and each
`$this->helper(...)` call with `$view->helper(...)`. Three things to know:

- **Property reads go through `$view->owaTemplate()`.** `$this->config` becomes
  `$view->owaTemplate()->config`. `$view-><name>` resolves view data only — it
  deliberately does **not** fall back to template properties, because letting a
  view variable shadow a property is a silent wrong-value bug.
- **`isset()` and `empty()` behave as they did on a bare variable** — false for a
  null value, false for a missing key, and never throwing. A read guarded by
  `isset()` or by the `@` operator is safe to convert inside the same guard; `@`
  suppresses diagnostics but **not** exceptions, so dropping the guard converts a
  tolerated absence into a 500.
- **A partial included with `include` or `require` shares the including
  template's scope**, so locals the including template assigns are still visible
  to it, and `$view` is too.

If a variable is only populated on some controller branches, initialize it
unconditionally in the controller *before* migrating the template read.

The contract is pinned by `tests/ViewScopeCompatTest.php`, and
`tests/TemplatesReadOnlyViewTest.php` checks OWA's own templates against it.

---

### 2. Legacy `owa_*` class names — REMOVED in v2.0

**What changed.** OWA's framework classes moved from the global namespace with an
`owa_` prefix into real PSR-4 namespaces:

| Legacy | Current |
| --- | --- |
| `owa_coreAPI` | `OWA\Core\CoreAPI` |
| `owa_base` | `OWA\Core\Base` |
| `owa_entity` | `OWA\Core\Entity` |
| `owa_module` | `OWA\Core\Module` |
| `owa_controller` | `OWA\Core\Controller` |
| `owa_view` | `OWA\Core\View` |
| `owa_lib` | `OWA\Core\Lib` |
| `owa_db_mysql` | `OWA\Core\Db\Mysql` |

In 1.x a compatibility bridge (`owa_compat_aliases.php`) kept the old names
resolving through `class_alias()`, and 1.x's notes promised it for a full
major-version window. That window ends at 2.0. **v2.0 removes the bridge**: no
`owa_*` class name resolves, and a module or integration that uses one, such as
a WordPress plugin calling `owa_coreAPI::getSetting()`, gets "class not found".
Use the namespaced class instead, such as `\OWA\Core\CoreAPI::getSetting()`.
To find the old names before upgrading, run the code on 1.14 with
`define('OWA_DISABLE_COMPAT_BRIDGE', true);` in `owa-config.php`: only the
namespaced names resolve there, as on 2.0. `extends owa_module`,
`owa_coreAPI::getSetting(...)` and `instanceof owa_entity` all need the namespaced
name. `tests/fixtures/legacy_class_names.json` lists all 406 retired names, and
`tests/LegacyClassNameContractTest.php` checks none of them resolves.

**Factories no longer `require` class files by name.** In 1.x, when a factory
found no namespaced class it fell back to requiring a file such as
`modules/<module>/<name>.php` declaring `owa_<name>`. v2.0 builds only PSR-4
class names — `OWA\Module\<Module>\Entity\<Name>`, `...\Controller\<Name>`,
`...\Metric\<Name>` — and raises an error naming the class it looked for when
there is none.

**Third-party database drivers are autoloaded.** `db_type = <type>` loads the
class `owa_db_<type> extends \OWA\Core\Db`, as in 1.x, but OWA no longer
requires it from `plugins/db/owa_db_<type>.php`: an autoloader has to find it,
such as a module's `composer.json` `autoload` section, which `composer install`
merges. Without one, the error log says `No database driver for <type>`. A
validator `owa_<name>Validation` is found the same way; nothing reads
`plugins/validations/`.

**Path and version constants are gone.** 1.x defined these. v2.0 defines
none of them; `\OWA\Core\Env` answers instead:

| 1.x constant | v2.0 |
| --- | --- |
| `OWA_PATH`, `OWA_BASE_DIR` | `\OWA\Core\Env::root()` (no trailing slash) |
| `OWA_DIR`, `OWA_BASE_CLASSES_DIR` | `\OWA\Core\Env::root() . '/'` |
| `OWA_MODULES_DIR` | `\OWA\Core\Env::modulesDir()` |
| `OWA_BASE_MODULE_DIR` | `\OWA\Core\Env::modulesDir() . 'Base/'` |
| `OWA_BASE_CLASS_DIR` | none: classes are autoloaded |
| `OWA_CONF_DIR` | `\OWA\Core\Env::confDir()` |
| `OWA_VENDOR_DIR` | `\OWA\Core\Env::vendorDir()` |
| `OWA_VERSION` | `\OWA\Core\Env::version()` |
| `OWA_DATA_DIR` | `\OWA\Core\Env::dataDir()` (the `data_dir` setting) |
| `OWA_CACHE_DIR` | none: there is no file cache (see "The object cache lasts one request") |
| `OWA_PLUGIN_DIR` | none: `plugins/` is not read (see above) |
| `OWA_THEMES_DIR` | none: themes are removed, with the `theme` setting |

The directories end in a slash. `OWA_DATA_DIR` is now an optional setting in
`owa-config.php`: 1.x defined it before the config file loaded, so a value there
was ignored with an "already defined" warning. It moves the error log, the file
event queue (with its archive and dead letters), downloaded user-agent patterns
and GeoIP data. `owa_env.php` still exists and
only loads the autoloader; `owa.php` includes it. Also removed:
`\OWA\Core\CoreAPI::getInstance( $class, $path )` (call `$class::getInstance()`)
and the unread `plugin_dir` setting. A module's `registerAction()` file argument
is now relative to that module's own directory, as `registerImplementation()`'s
is, and may be omitted.

---

### 3. Lowercase module directories — REMOVED in v2.0

**What changed.** A module is a PascalCase directory (`modules/Base/`,
`modules/MaxmindGeoip/`) holding an autoloadable `OWA\Module\<Dir>\Module`
class, with one class per file in `Entity/`, `Controller/`, `View/`, `Classes/`
and so on.

1.x also loaded a module from a **lowercase** directory whose `module.php`
declared a global `owa_<name>Module`. **v2.0 does not.** A directory under
`modules/` without an `OWA\Module\<Dir>\Module` class is skipped, and a notice
in the error log names it. An old module that was active simply stops loading;
nothing else on the install is affected.

**Migrating.** Rename the module directory to PascalCase and adopt the PSR-4
layout, with namespaced class names under `OWA\Module\<YourModule>\`. The
module's runtime name — its settings key, the `<module>.` prefix of its actions
and entities — stays lowercase.

Pinned by `tests/ThirdPartyModuleCompatTest.php`.

---

### 4. The event queues, `queue.php` and the RemoteQueue module — REMOVED in v2.0

**Process queued beacons on 1.x before upgrading.** With queueing on, 1.x
wrote incoming beacons to a file queue under `owa-data/logs/` as serialized PHP
objects, which v2.0 does not read. Turn queueing off on 1.x and run
`php cli.php cmd=processEventQueue` until the queue is empty (see
[A 1.x site that queues beacons](#a-1x-site-that-queues-beacons)); the upgrade
moves any beacon still in it to an archive file, and does not ingest it.

`owa_queue_item` does not stop the upgrade. It held only retries of events whose
handlers had already failed, and is dropped; the upgrade says how many were
still awaiting a retry.

**What replaces them.**

- **The tracking intake**, `tracker-ingest`. Turn queueing on with
  `define('OWA_QUEUE_TRACKING_EVENTS', true);` (`OWA_QUEUE_EVENTS` is still
  read while it is not set).
  The shipped `drain-tracker-ingest` job ingests what is queued every minute;
  `cmd=processEventQueue` and `cmd=flush-processed-events` are gone.
- **Failed writes are retried** through the intake in either mode, then kept in
  its dead-letter queue: `cmd=tracker-ingest-replay` sends them back.
- **`queue.php` and the RemoteQueue module**, which forwarded beacons to
  another install over HTTP, are removed. Use the tracker-ingest intake: on
  disk, drained by the install that receives the beacons, or in AWS SQS
  (`OWA_SQS_QUEUE_URL`), where a `collector` queues beacons and a `worker`
  drains them (see Server roles above). Remove `queue.php` from any web server allowlist, and
  `OWA_REMOTE_EVENT_QUEUE_ENDPOINT` from `owa-config.php`.
- **Removed settings:** `queue_max_retry_count`, `queue_max_retry_age`,
  `remote_event_queue_endpoint`, `allowed_queued_event_types`.

---

### 5. Unregistered actions — REMOVED in v2.0

**What changed.** An action is reached only if a module registers it, with
`registerAction()` in its `registerActions()`. 1.x also resolved a name nothing
registered by convention, turning `?owa_do=<module>.<action>` into the
module's controller class of that name. **v2.0 does not**: an unregistered
name gets the missing-page response (404, or 400 for a name that is not
`<module>.<action>`), and no controller is built.

**Migrating.** Register each controller a URL or a form names:

```php
function registerActions() {
    $this->registerAction( 'mymodule.reportWidgets',
        'OWA\\Module\\MyModule\\Controller\\ReportWidgets', '' );
}
```

Pinned by `tests/ActionResolutionTest.php`.

---

### 6. The 1.x module hooks and the `do=` API — REMOVED in v2.0

**Module methods removed.** None did anything a v2 module can use:

- `registerMetric()` named a metric class per metric. Metrics are declared in
  the module's metrics config and built as `base.configurableMetric`.
- `registerApiMethod()` and the `do=` API it fed (`?owa_do=base.apiRequest&do=…`).
  Use a REST route: `registerRestApiRoute()` in `registerApiMethods()`, served
  from `api/`.
- `addAdminPanel()`: use `registerSettingsPage()`.
- `uninstall()`, which was an empty stub nothing called.
- `registerMetrics()`, an empty hook.
- `registerFormatter()`. No module registered a formatter; a result set
  formats the data types it declares, and a report widget names its column
  formatters in `formatters`.
- `_addHandler()`: use `registerEventHandler()`.

**`Caller` methods removed:** `placeHelperPageTags()`,
`handleHelperPageTagsRequest()`, `handleRequestFromUrl()` and
`setErrorHandler()`. The tracking tag comes from a Profile's Tracking Tag
screen, or `CoreAPI::getJsTrackerBundleTag()`.

**Formula metrics removed.** A `calculated` metric declares a `ratio`
(`numerator`, `denominator`, optional `precision`) or a difference (`minuend`,
`subtrahend`). The 1.x `formula` with `child_metrics` -- an expression run
through `eval()` with child values substituted in by name -- is refused with a
notice, and nothing in OWA uses `eval()` any more.

**Only declared settings can be stored.** `persistSetting()` and the options
form refuse a key no module declares (in its `settings.php`, `storable` and with
`install` among its scopes). A module that stored an undeclared key in 1.x must
declare it.

---

## Deprecated in 2.0

### Tracker option `cookiePersistence`

`owa_cmds.push(['setOption', 'cookiePersistence', false])`, added in 1.14.0,
makes every tracker cookie a session cookie. It still works in 2.0 and writes a
debug notice naming the replacement.

**Replace with** a lifetime of 0 days for each cookie:

```js
owa_cmds.push(['setOption', 'stateStoreExpirations', {"v": 0, "s": 0}]);
```

0 days is per cookie, so a site can end the visitor cookie with the browser and
keep a session cookie, or the other way round. A Profile's tracking bundle sets
it from the Tag Settings screen.

It does not touch the server's `cookie_persistence` setting, which governs only
the cookies OWA's own admin screens set.

Pinned by `tests/js/StateStoreExpirations.test.js`.

### Tracker command `setPageType`

A page's type and its content group are one dimension in 2.0, which the
**Page Types** report groups by. `owa_cmds.push(['setPageType', 'article'])`
sent a `page_type` that 2.0 does not read; it now sets the content group, so a
1.x snippet's page types reach that report. A page that calls both reports the
one called last.

**Replace with:**

```js
owa_cmds.push(['setContentGroup', 'article']);
```

The 1.x migration carries each page's 1.x page type into the content group of
its migrated events.

Pinned by `tests/js/TrackerMiscMethods.test.js` and `tests/MigrateRequestsTest.php`.

---

## Reporting a problem

If something in your module breaks on upgrade and it is not covered above, that
is a bug in the compatibility layer rather than something for you to work
around — please open an issue with the module code that triggers it.
