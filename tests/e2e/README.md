# Reporting-UI end-to-end tests (Playwright)

Real-browser characterization of the reporting UI. This is the top layer of the
reporting safety net for the Phase 3 jQuery migration:

| Layer | Location | Runs in CI | What it proves |
|-------|----------|------------|----------------|
| Build integrity | `tests/js/reporting/BundleIntegrity.test.js` | yes (Jest) | the reporting bundle is built from the expected inputs, in the right order |
| jsdom load | `tests/js/reporting/BundleLoad.test.js` | yes (Jest) | the bundle executes and the OWA objects construct outside a browser |
| **Real render (this dir)** | `tests/e2e/reporting-dashboard.spec.js` | **no — manual/local** | Flot charts, jqGrid tables, and chosen select menus actually paint on a live logged-in dashboard |

The jsdom layers cannot paint; these Playwright tests drive headless Chromium
against a live OWA dashboard and pin the pre-migration render (jQuery **1.6.4**)
as the baseline. When Phase 3.1 flips the reporting bundle to jQuery 3.x, the
version assertion here (and in the build-integrity test) fails on purpose and
must be updated as a conscious, reviewed change.

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
