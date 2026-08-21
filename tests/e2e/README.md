# Reporting-UI end-to-end tests (Playwright)

Real-browser characterization of the reporting UI. This is the top layer of the
reporting safety net for the Phase 3 jQuery migration:

| Layer | Location | Runs in CI | What it proves |
|-------|----------|------------|----------------|
| Build integrity | `tests/js/reporting/BundleIntegrity.test.js` | yes (Jest) | the reporting bundle is built from the expected inputs, in the right order |
| jsdom load | `tests/js/reporting/BundleLoad.test.js` | yes (Jest) | the bundle executes and the OWA objects construct outside a browser |
| **Real render (this dir)** | `tests/e2e/reporting-dashboard.spec.js` | **no — manual/local** | Flot charts, jqGrid tables, and chosen select menus actually paint on a live logged-in dashboard |
| **Overlay render (this dir)** | `tests/e2e/overlay-heatmap.spec.js` | **no — manual/local** | the heatmap overlay's control panel + canvas build via jQuery **3.x** on the tracker path (the overlay's biggest untested jQuery surface) |

The jsdom layers cannot paint; these Playwright tests drive headless Chromium
against a live OWA dashboard. As of Phase 3.2 the reporting bundle runs on jQuery
**3.6.0** (jquery-migrate + a `$.browser`/`$.curCSS` compat shim bridge the
legacy 1.6/1.8-era plugins, and jqGrid 3.6.5 was replaced by free-jqgrid 4.15.5),
so the version assertion here (and in the build-integrity test) pins 3.6.0 — a
future bump must be a conscious, reviewed change.

## Why this isn't in CI

These tests need a **live, seeded OWA instance** (web server + database), which
the stateless CI jobs don't provide. They are a local/pre-merge gate. The
build-integrity and jsdom tests — which need only the built bundle — cover the
same bundle in CI.

## Running

From the repo root:

```bash
# 1. one-time: install the browser (system deps may need root on some distros:
#    `npx playwright install-deps chromium`)
npx playwright install chromium

# 2. seed the deterministic fixtures (idempotent — safe to re-run)
npm run test:e2e:seed

# 3. run the browser tests
npm run test:e2e

# 4. (optional) remove the fixture site/user/data
npm run test:e2e:teardown
```

By default the tests target this install's configured public URL. Point them at
a different host with:

```bash
OWA_E2E_BASE_URL=https://your-host/owa/index.php npm run test:e2e
```

## Fixtures

`seed_reporting_fixtures.php` creates, idempotently:

- a tracked site (`https://owa-e2e.example.test`),
- an **analyst** user with a known password **and** a `base.site_user` grant so
  it passes the per-site `view_reports` access check,
- 8 pageviews across 2 sessions and 4 page titles, so a report page renders a
  non-empty chart + grid.

The credentials are throwaway values for a **local fixture user on the test
schema** — never a production account. The identifiers are constants shared with
`fixtures.js` (the source of truth is the PHP seeder).

## Overlay test (`overlay-heatmap.spec.js`)

The heatmap/player overlay does **not** render on an OWA page — in production
the report page links to `base.overlayLauncher`, which redirects the browser to
the *tracked* page with a `#owa_overlay.<params>` anchor. On that page the
tracker bundle (already on jQuery **3.x**) decodes the anchor and builds the
overlay DOM. `overlay_harness.html` stands in for that tracked page: it loads
the real `dist/owa.tracker.js` **same-origin** with this install and carries the
anchor, so the tracker's `checkForOverlaySession()` → `startOverlaySession()` →
dynamic-import of `Heatmap.js` runs exactly as in production.

This test needs **no DB seeding** — it drives the client-side render only (the
subsequent click-data fetch is asserted separately, cross-origin, by
`overlay-cross-origin.spec.js`). It shares the same
`OWA_E2E_BASE_URL` / public-URL target as the dashboard tests because the
harness must be served from under that install's webroot.
