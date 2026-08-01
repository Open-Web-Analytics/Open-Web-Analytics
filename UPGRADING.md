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
