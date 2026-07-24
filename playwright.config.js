// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Playwright config for the reporting-UI characterization tests.
 *
 * These are the real-browser half of the reporting safety net: jsdom (see
 * tests/js/reporting/*) proves the bundle loads and OWA objects construct, but
 * it cannot paint a Flot chart, lay out a jqGrid, or open a chosen select menu.
 * These tests drive a headless Chromium against a LIVE, logged-in report page
 * backed by the deterministic fixtures in tests/e2e/seed_reporting_fixtures.php
 * and pin the pre-migration (jQuery 1.6.4) render as the baseline.
 *
 * The whole tests/ tree is excluded from the release tarball (see
 * .github/workflows/main.yml), so this harness never ships to a distro.
 *
 * Target URL: defaults to the configured public URL of this install. Override
 * with OWA_E2E_BASE_URL when running against a different host.
 */

const BASE_URL =
    process.env.OWA_E2E_BASE_URL || 'https://test.openwebanalytics.com/owa/index.php';

module.exports = defineConfig({
    testDir: './tests/e2e',
    // Only the browser specs; the PHP seeder shares this dir but isn't a test.
    testMatch: '**/*.spec.js',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,
    reporter: process.env.CI ? 'line' : 'list',
    timeout: 60_000,
    expect: { timeout: 15_000 },

    use: {
        baseURL: BASE_URL,
        ignoreHTTPSErrors: true,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },

    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                // Append "Open Web Analytics" to the REAL Chrome UA (keep the
                // browser UA base so Chromium still behaves normally). The site's
                // WAF/rate-limiter allowlists that token, so the full suite's
                // sustained load no longer trips it into intermittent 403s /
                // failures to serve the login form. NOTE: this MUST live in the
                // project `use` (not top-level) -- devices['Desktop Chrome']
                // carries its own userAgent that otherwise clobbers a top-level
                // one. Also lets server log filters identify the harness.
                userAgent: `${devices['Desktop Chrome'].userAgent} Open Web Analytics`,
            },
        },
    ],
});
