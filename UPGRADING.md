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

**Process queued events on 1.x before upgrading.** 1.x queued events as
serialized PHP objects, in the file queue under `owa-data/logs/` and in
`owa_queue_item`. v2.0 reads neither: its tracking intake holds one JSON line
per beacon. Run `php cli.php cmd=processEventQueue` on 1.x until both are
empty. The upgrade refuses to run while either holds an unprocessed event.

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
