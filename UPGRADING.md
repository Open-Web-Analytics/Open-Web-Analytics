# Upgrading OWA

This file lists the deprecated interfaces third-party code still relies on, what
replaces each one, and when the old path goes away. It exists because OWA's
recent modernization work replaced several long-standing conventions while
keeping the old ones working — so nothing breaks on upgrade, but the old paths
are on a clock.

**Everything listed here still works today.** Each entry is scheduled for removal
in **v2.0**, and none of it will be removed in a 1.x release.

Audience: authors of third-party modules, site owners with local template
overrides, and anyone maintaining a custom theme.

---

## Behaviour changes in this release

These are not deprecations — they change what happens on upgrade. Those with a
way back say what it is.

### A release with a new tracker is applied as an update

A release that changes the tracker now asks for its update to be applied --
`php cli.php cmd=update`, or Apply on the update notice in the admin screens --
even when it changes no schema. That update publishes the Profiles' tracking
bundles that are stale or missing: in the run from the command line, or as a job
for the scheduler's next minute when applied from the screen. A release that
changes neither the tracker nor the schema asks for nothing.

Until it is applied, the admin screens show the update notice. Tracking is
unaffected, and so are scheduled jobs unless the release also changes the
schema, which stops them until it is applied.

### The tracker honours Global Privacy Control as well as Do Not Track

A browser reporting Global Privacy Control (`navigator.globalPrivacyControl`)
is now treated like one reporting Do Not Track: the tracker runs no command and
sends nothing.

**Way back:** the tracker option `honorPrivacySignals`, queued before the page
view, turns off both checks:

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

### PDO is preferred over mysqli where it is available

`db_type = 'mysql'` now means "MySQL", and OWA reaches it through PDO wherever
the `pdo_mysql` extension is present, falling back to `mysqli` where it is not.
**No configuration change is required**, and a host that has `mysqli` but not
`pdo_mysql` keeps working exactly as before.

The reason to move is that PDO carries bound parameters, so values are no longer
escaped into the statement text. To pin a driver explicitly:

```php
define( 'OWA_DB_TYPE', 'mysqli' );     // force the legacy driver
define( 'OWA_DB_TYPE', 'pdo_mysql' );  // force MySQL over PDO
```

Third-party drivers dropped in at `plugins/db/owa_db_<type>.php` are unaffected
in selection, but note that the driver interface gained an optional `$params`
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
- `OWA.dimensionSelectors` is removed. `OWA.constraintBuilder` remains, for
  screens that build their own filter control.
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
A step is "Page is" a path or "Event is" a goal event, and steps move by
dragging or with the arrow keys. A step's name is no longer asked for: a step
with none is named for its page or goal event, and a stored name is kept while
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
- custom themes (`OWA_THEMES_DIR`)

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
resolving through `class_alias()`. **v2.0 removes it**: no `owa_*` class name
resolves, and a module that uses one gets "class not found". `extends owa_module`,
`owa_coreAPI::getSetting(...)` and `instanceof owa_entity` all need the namespaced
name. `tests/fixtures/legacy_class_names.json` lists all 406 retired names, and
`tests/LegacyClassNameContractTest.php` checks none of them resolves.

**Factories no longer `require` class files by name.** In 1.x, when a factory
found no namespaced class it fell back to requiring a file such as
`modules/<module>/<name>.php` declaring `owa_<name>`. v2.0 builds only PSR-4
class names — `OWA\Module\<Module>\Entity\<Name>`, `...\Controller\<Name>`,
`...\Metric\<Name>` — and raises an error naming the class it looked for when
there is none.

**Still works.**

- **Third-party database drivers.** A driver at `plugins/db/owa_db_<type>.php`
  declaring `class owa_db_<type> extends \OWA\Core\Db` is still loaded for
  `db_type = <type>`. The class name is the plugin's own; only its base class
  changed.

---

### 3. Lowercase module directories — REMOVED in v2.0

**What changed.** A module is a PascalCase directory (`modules/Base/`,
`modules/MemcachedCache/`) holding an autoloadable `OWA\Module\<Dir>\Module`
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
objects, which v2.0 does not read. Run `php cli.php cmd=processEventQueue` on
1.x until it is empty; the upgrade refuses to run while it holds a beacon that
was never ingested.

`owa_queue_item` does not stop the upgrade. It held only retries of events whose
handlers had already failed, and is dropped; the upgrade says how many were
still awaiting a retry.

**What replaces them.**

- **The tracking intake**, `tracker-ingest`. Turn queueing on with
  `define('OWA_QUEUE_TRACKER_INGEST', true);` (`OWA_QUEUE_EVENTS` and the
  stored `queue_incoming_tracking_events` are still read while it is unset).
  The shipped `drain-tracker-ingest` job ingests what is queued every minute;
  `cmd=processEventQueue` and `cmd=flush-processed-events` are gone.
- **Failed writes are retried** through the intake in either mode, then kept in
  its dead-letter queue: `cmd=tracker-ingest-replay` sends them back.
- **`queue.php` and the RemoteQueue module**, which forwarded beacons to
  another install over HTTP, are replaced by the **SQS module**: a logging node
  queues beacons on AWS SQS and the reporting install drains them. Remove
  `queue.php` from any web server allowlist, and `OWA_REMOTE_EVENT_QUEUE_ENDPOINT`
  from `owa-config.php`.
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

**`Caller` methods removed:** `placeHelperPageTags()`,
`handleHelperPageTagsRequest()`, `handleRequestFromUrl()` and
`setErrorHandler()`. The tracking tag comes from a Profile's Tracking Tag
screen, or `CoreAPI::getJsTrackerBundleTag()`.

`registerFormatter()` stays: `ResultSetManager` falls back to a module's
formatter for a data type it does not format itself.

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

---

## Reporting a problem

If something in your module breaks on upgrade and it is not covered above, that
is a bug in the compatibility layer rather than something for you to work
around — please open an issue with the module code that triggers it.
