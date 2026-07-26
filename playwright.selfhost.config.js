// @ts-check
const { defineConfig, devices } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

/**
 * SELF-HOSTED Playwright config: run the app-behavior e2e suite on a bare machine
 * or in CI, with NO dependency on this box's Apache + RDS + installed config.
 *
 * The reporting / admin / tracker / cookie specs are ordinary app-behavior tests
 * written entirely against relative URLs + Playwright's baseURL (tests/e2e/
 * fixtures.js) and a deterministic seeder. They don't care WHAT serves OWA, only
 * that an installed OWA answers at baseURL. So here we serve the repo with PHP's
 * built-in server (`php -S`) against a throwaway scratch install:
 *
 *   globalSetup  -> selfhost_harness.php up   (stash live config if any, CREATE a
 *                   scratch DB, write a config pointing at the php -S URL, run the
 *                   CLI installer) THEN seed_reporting_fixtures.php seed.
 *   webServer    -> php -S 127.0.0.1:8964 at the repo root (started by Playwright,
 *                   torn down when the run ends).
 *   globalTeardown -> unseed + selfhost_harness.php down (drop scratch DB, restore
 *                   the live config). Runs even on failure.
 *
 * WHY A SEPARATE CONFIG FROM playwright.config.js:
 *   - playwright.config.js targets an ALREADY-RUNNING install (the live site or a
 *     staging host via OWA_E2E_BASE_URL) and does not stand up a server. It's the
 *     "run against a real deployment" config.
 *   - This one is the "stand everything up from scratch" config for CI / laptops.
 *
 * WHAT IT DELIBERATELY SKIPS:
 *   - install-*.spec.js: those own the config/DB lifecycle themselves (their own
 *     config, playwright.install.config.js) and would fight this harness.
 *   - @server-config specs (access-hardening.spec.js): they assert REAL web-server
 *     (.htaccess) deny/allow behavior. `php -S` serves every file itself and knows
 *     nothing about .htaccess, so those can only pass against a real Apache/nginx.
 *     They stay in the live-server run (playwright.config.js). We grep-INVERT the
 *     tag here so a green self-hosted run never implies hardening was checked.
 *
 * The whole tests/ tree is excluded from the release tarball, so this never ships.
 */

const HARNESS = path.join(__dirname, 'tests', 'e2e', 'selfhost_harness.php');

// The base URL is defined by the harness (php -S host/port). Ask it, so the config
// and the harness can never disagree about where the server is.
const BASE_URL = execFileSync('php', [HARNESS, 'baseurl'], { encoding: 'utf8' }).trim();

// The php -S command serves the repo root (this dir). Multiple workers would need
// PHP_CLI_SERVER_WORKERS, but the suite runs workers:1 (fixtures share one DB), and
// a single-threaded server can deadlock if a request makes a same-server subrequest;
// give it a small worker pool so a page + its assets/beacons can be served together.
const SERVER_URL = BASE_URL.replace(/index\.php.*$/, '');
const { hostname, port } = new URL(SERVER_URL);

module.exports = defineConfig({
    testDir: './tests/e2e',
    testMatch: '**/*.spec.js',
    // Install specs manage their own config/DB lifecycle -- never run them here.
    testIgnore: '**/install-*.spec.js',
    // access-hardening.spec.js asserts real .htaccess behavior php -S can't serve.
    grepInvert: /@server-config/,

    globalSetup: require.resolve('./tests/e2e/selfhost-global-setup.js'),
    globalTeardown: require.resolve('./tests/e2e/selfhost-global-teardown.js'),

    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,
    reporter: process.env.CI ? 'line' : 'list',
    timeout: 60_000,
    expect: { timeout: 15_000 },

    // Let Playwright own the php -S lifecycle: start it before the specs, kill it
    // after. reuseExistingServer lets a dev leave one running between local runs.
    //
    // READINESS URL -- must be install.php, NOT index.php. Playwright starts the
    // webServer and waits for this URL to answer BEFORE globalSetup runs (plugin
    // setup precedes globalSetups in the runner). But it's globalSetup (`up`) that
    // writes owa-config.php + installs the schema, so at readiness-probe time there
    // is NO config yet on a fresh machine/CI -- and index.php 500s with no config,
    // which the probe treats as "not ready" and retries until this timeout. Only on
    // this box did index.php happen to answer, because the LIVE config was still in
    // place. install.php returns 200 whether or not OWA is installed, so it's a
    // stable "is php -S serving?" signal independent of install state. (The specs
    // themselves still hit index.php via baseURL, after `up` has installed.)
    webServer: {
        command: `php -d variables_order=EGPCS -S ${hostname}:${port} -t ${__dirname}`,
        url: SERVER_URL + 'install.php',
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
        // php -S needs a worker pool so a request can be served while an earlier
        // one is still open (e.g. a page whose assets load from the same server).
        env: { PHP_CLI_SERVER_WORKERS: '8' },
    },

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
                // Match the live-server config: append the WAF-allowlisted token to
                // the real Chrome UA. Harmless under php -S (no WAF) and keeps the
                // UA identical across both runners so specs behave the same.
                userAgent: `${devices['Desktop Chrome'].userAgent} Open Web Analytics`,
            },
        },
    ],
});
