// @ts-check
/**
 * Playwright globalSetup for the SELF-HOSTED runner (playwright.selfhost.config.js).
 *
 * Stands a throwaway OWA install up from scratch so the app-behavior specs have
 * something to run against WITHOUT this box's Apache/RDS/installed config:
 *
 *   1. selfhost_harness.php up  -- stash any live config, CREATE a scratch DB,
 *      write a config pointing at the php -S URL, run the CLI installer.
 *   2. seed_reporting_fixtures.php seed -- the same deterministic fixtures the
 *      live-server runner uses (site, users, pageviews) that the specs assert on.
 *
 * Playwright's webServer block starts `php -S` AFTER globalSetup returns, so the
 * install + seed (which run via the CLI, not over HTTP) complete against the
 * scratch config before the server comes up. Paired with selfhost-global-teardown.js
 * which unseeds + tears the install down (even on failure).
 *
 * Anything non-zero here throws, failing the run loudly -- a green run against a
 * half-installed or un-seeded DB would be a false pass.
 */
const { execFileSync } = require('child_process');
const path = require('path');

module.exports = async () => {
    const harness = path.join(__dirname, 'selfhost_harness.php');
    const seeder = path.join(__dirname, 'seed_reporting_fixtures.php');

    // 1. Bring the scratch install up (config + DB + schema via the CLI installer).
    execFileSync('php', [harness, 'up'], { stdio: 'inherit' });

    // 2. Seed the deterministic fixtures the specs characterize.
    execFileSync('php', [seeder, 'seed'], { stdio: 'inherit' });
};
