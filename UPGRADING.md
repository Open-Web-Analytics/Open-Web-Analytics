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

These are not deprecations — they change what happens on upgrade, and each has a
one-line way back.

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

### 2. Legacy `owa_*` class names

**What changed.** OWA's framework classes moved from the global namespace with an
`owa_` prefix into real PSR-4 namespaces:

| Legacy | Current |
| --- | --- |
| `owa_coreAPI` | `OWA\Core\CoreAPI` |
| `owa_base` | `OWA\Core\Base` |
| `owa_entity` | `OWA\Core\Entity` |
| `owa_module` | `OWA\Core\Module` |
| `owa_lib` | `OWA\Core\Lib` |
| `owa_db_mysql` | `OWA\Core\Db\Mysql` |

**What still works in v2.** The alias bridge (`owa_compat_aliases.php`) resolves
only the names a module builds on:

- the base classes it extends: `owa_base`, `owa_module`, `owa_observer`,
  `owa_update`, `owa_controller`, `owa_adminController`, `owa_reportController`,
  `owa_cliController`, `owa_view`, `owa_adminPageView`, `owa_restApiView`,
  `owa_mailView`, `owa_cliView`, `owa_entity`, `owa_factTable`, `owa_metric`,
  `owa_calculatedMetric`, `owa_validation`, `owa_cacheType`, `owa_eventQueue`;
- the static API it calls: `owa_coreAPI`;
- `owa_event`, which queued data written before the migration names.

**Removed in v2.** Every other legacy name, including `owa_lib`, the service
classes (`owa_siteManager`, `owa_userManager`, `owa_settings`, ...), the event
handlers, the concrete validators, controllers and views, and `owa_db_mysql`.
A module that used one gets "class not found" and must use the namespaced name.
`tests/LegacyClassNameContractTest.php` lists every retired name.

**Checking a module.** Define `OWA_DISABLE_COMPAT_BRIDGE = true` in your config
before OWA boots. With it set, no legacy name resolves. OWA itself runs correctly
that way; if your module does not, it still has legacy references to migrate.

---

### 3. Lowercase module directories

**What changed.** Module directories are PascalCase and PSR-4 (`modules/Base/`,
`modules/MemcachedCache/`), with one class per file.

**What still works.** A module shipped in the old convention — a lowercase
directory plus an `owa_<name>Module` class in `module.php` — is still discovered
and loaded. `Lib::moduleDirName()` resolves to the legacy lowercase directory
when no PascalCase one exists, so the module's entities, controllers, views and
classes all continue to resolve.

**Migrating.** Rename the module directory to PascalCase and adopt the PSR-4
layout (`Entity/`, `Controller/`, `View/`, `Classes/`), one class per file, with
namespaced class names under `OWA\Module\<YourModule>\`.

The shim is pinned by `tests/ThirdPartyModuleCompatTest.php`.

---

## Reporting a problem

If something in your module breaks on upgrade and it is not covered above, that
is a bug in the compatibility layer rather than something for you to work
around — please open an issue with the module code that triggers it.
