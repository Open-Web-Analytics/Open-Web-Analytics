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

### 1. Bare template variables and `$this` inside templates

**What changed.** OWA's own templates now receive their view data through an
explicit `$view` object instead of variables materialized by `extract()`, and
reach the template helpers through `$view` rather than `$this`.

```php
<!-- Deprecated -->
<?php $this->out( $headline ); ?>
<?php foreach ($tabs as $tab): ?>

<!-- Current -->
<?php $view->out( $view->headline ); ?>
<?php foreach ($view->tabs as $tab): ?>
```

**Why.** A key the controller never set was simply an undefined variable — a
warning in scalar context and a **fatal** in `foreach` (`foreach() argument must
be of type array|object, bool given`), raised inside the template rather than at
the controller that forgot the key. Nothing declared what a template required,
so no tool could check it. Reading a never-set key through `$view` raises an
`OutOfBoundsException` naming the key and the template instead.

**What still works.** `extract()` is still called, so **bare variables and
`$this` continue to work** in:

- third-party module templates (`modules/<Module>/templates/`)
- site-owner overrides (`modules/<Module>/templates/local/`)
- custom themes (`OWA_THEMES_DIR`)

OWA ships none of those and cannot migrate them, which is why the old path
remains for the full deprecation window.

**Migrating.** Replace each bare view variable with `$view-><name>` and each
`$this->helper(...)` call with `$view->helper(...)`. Two things to know:

- **Property reads stay on `$this`.** `$this->config` is the *Template object's*
  config, not a view variable of the same name. `$view` resolves view data only —
  it deliberately does **not** fall back to template properties, because letting a
  view variable shadow a property is a silent wrong-value bug.
- **`isset()` and `empty()` behave identically** on both paths — false for a null
  value, false for a missing key, and never throwing. A read guarded by `isset()`
  or by the `@` operator is safe to leave alone; the `@` form in particular is a
  signal that the key may legitimately be absent, and `@` suppresses diagnostics
  but **not** exceptions, so migrating such a read converts a tolerated absence
  into a 500.

If a variable is only populated on some controller branches, initialize it
unconditionally in the controller *before* migrating the template read.

The contract on both paths is pinned by `tests/ViewScopeCompatTest.php`.

---

### 2. Legacy `owa_*` class names

**What changed.** OWA's framework classes moved from the global namespace with an
`owa_` prefix into real PSR-4 namespaces:

| Deprecated | Current |
| --- | --- |
| `owa_coreAPI` | `OWA\Core\CoreAPI` |
| `owa_base` | `OWA\Core\Base` |
| `owa_entity` | `OWA\Core\Entity` |
| `owa_module` | `OWA\Core\Module` |
| `owa_db_mysql` | `OWA\Core\Db\Mysql` |

**What still works.** A lazy alias bridge (`owa_compat_aliases.php`) resolves
every legacy `owa_*` name to its namespaced class on demand, so `new
owa_document`, `extends owa_entity`, and `instanceof` in both directions all
continue to work. The bridge is **on by default**.

**Testing against the v2.0 behavior now.** Define
`OWA_DISABLE_COMPAT_BRIDGE = true` in your config before OWA boots. With the
bridge off, only the namespaced names resolve — which is what v2.0 will do. OWA
itself runs correctly in that mode; if your module does not, it still has legacy
references to migrate.

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
