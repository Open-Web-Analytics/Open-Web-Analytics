// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Playwright config for the OWA INSTALL-FLOW specs (install-web.spec.js and
 * install-cli.spec.js) ONLY.
 *
 * These are kept in a SEPARATE config -- and out of the normal `npm run test:e2e`
 * run -- because they manipulate the two pieces of global state every other spec
 * depends on: they stash the live owa-config.php and install into throwaway
 * scratch databases. Interleaving that with the reporting/admin specs (which read
 * the live config + live DB) would break them. Run this explicitly:
 *
 *   npm run test:e2e:install
 *
 * globalSetup stashes the live config + creates the scratch DBs; globalTeardown
 * restores the config + drops the scratch DBs, and RUNS EVEN IF THE SPECS FAIL
 * (so a mid-flow crash can't leave the site without its config file). All the
 * provisioning lives in tests/e2e/install_harness.php.
 *
 * The two specs must run in order (web wizard first, then the CLI path reuses the
 * slot): testMatch is an ordered list and workers:1 keeps them serial.
 *
 * TWO MODES:
 *   - LOCAL (default): runs against the already-running live install (Apache), and
 *     the harness stashes/restores the live owa-config.php. This is the historical
 *     behavior -- nothing changes when OWA_E2E_DB_HOST is unset.
 *   - ENV / SELF-SERVED (CI): when OWA_E2E_DB_HOST is set, there is NO live install
 *     to protect. The harness gets its creds from OWA_E2E_DB_* and installs into
 *     scratch DBs on the provisioned MySQL, and THIS config starts a `php -S`
 *     server so the web wizard has something to drive. Set OWA_E2E_BASE_URL to the
 *     php -S URL (default below). See tests/e2e/install_harness.php `inEnvMode()`.
 *
 * The whole tests/ tree is excluded from the release tarball, so this never ships.
 */

// ENV MODE (CI): the install suite serves itself with php -S on this port. Kept
// distinct from the selfhost runner's port (8964) so both CI steps could, in
// principle, run without colliding.
const ENV_MODE = !!process.env.OWA_E2E_DB_HOST;
const DEFAULT_ENV_BASE_URL = 'http://127.0.0.1:8965/index.php';

const BASE_URL =
    process.env.OWA_E2E_BASE_URL ||
    (ENV_MODE ? DEFAULT_ENV_BASE_URL : 'https://test.openwebanalytics.com/owa/index.php');

// The php -S origin (repo root), used only in env mode for the webServer block.
const SERVER_URL = BASE_URL.replace(/index\.php.*$/, '');
const { hostname: SRV_HOST, port: SRV_PORT } = new URL(SERVER_URL);

// Same WAF-allowlisted UA token the reporting config uses so the install
// wizard's page loads aren't tripped into 403s.
const CHROME = {
    ...devices['Desktop Chrome'],
    userAgent: `${devices['Desktop Chrome'].userAgent} Open Web Analytics`,
};

module.exports = defineConfig({
    testDir: './tests/e2e',
    globalSetup: require.resolve('./tests/e2e/install-global-setup.js'),
    globalTeardown: require.resolve('./tests/e2e/install-global-teardown.js'),
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    workers: 1,
    reporter: process.env.CI ? 'line' : 'list',
    timeout: 120_000, // schema install + browser wizard is slower than a normal page
    expect: { timeout: 15_000 },

    // ENV MODE only: serve the repo with php -S so the web wizard has a server.
    // globalSetup (harness `stash`) runs FIRST and, in env mode, leaves NO config
    // file -- exactly the un-installed state install.php needs to render the wizard.
    // In local mode there's no webServer: we target the live Apache install.
    ...(ENV_MODE
        ? {
              webServer: {
                  command: `php -d variables_order=EGPCS -S ${SRV_HOST}:${SRV_PORT} -t ${__dirname}`,
                  url: SERVER_URL + 'install.php',
                  reuseExistingServer: !process.env.CI,
                  timeout: 30_000,
                  env: { PHP_CLI_SERVER_WORKERS: '8' },
              },
          }
        : {}),

    use: {
        baseURL: BASE_URL,
        ignoreHTTPSErrors: true,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },

    // ORDER MATTERS and is NOT the testMatch/filename order: Playwright runs spec
    // FILES alphabetically within a project, which would run install-cli before
    // install-web. The web wizard MUST run first -- it needs NO config file
    // present (install.php renders the wizard only when un-installed). The CLI
    // spec then reuses the slot: its prepare-cli step removes the wizard-written
    // config and writes a fresh one pointing at the CLI scratch DB. So we make
    // each spec its own project and use a dependency to force web -> cli.
    projects: [
        {
            name: 'install-web',
            testMatch: /install-web\.spec\.js/,
            use: CHROME,
        },
        {
            name: 'install-cli',
            testMatch: /install-cli\.spec\.js/,
            dependencies: ['install-web'],
            use: CHROME,
        },
    ],
});
