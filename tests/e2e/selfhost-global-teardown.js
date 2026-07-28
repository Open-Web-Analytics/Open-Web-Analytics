// @ts-check
/**
 * Playwright globalTeardown for the SELF-HOSTED runner (playwright.selfhost.config.js).
 *
 * Undoes selfhost-global-setup.js: remove the seeded fixtures, then tear the
 * throwaway install down (drop the scratch DB, remove our config, and -- on this
 * box -- restore the live owa-config.php that `up` stashed). Runs even if specs
 * failed.
 *
 * ORDER MATTERS: unseed FIRST (it boots OWA against the scratch config to delete
 * fixture rows), THEN `down` (which drops that scratch DB and swaps the config
 * back). Doing `down` first would drop the DB out from under the seeder.
 *
 * Teardown must not throw on a cleanup hiccup and mask the real test result -- with
 * ONE exception: if `down` cannot restore the live config the box is left down, so
 * that is rethrown. A failed unseed is only logged.
 */
const { execFileSync } = require('child_process');
const path = require('path');

module.exports = async () => {
    const harness = path.join(__dirname, 'selfhost_harness.php');
    const seeder = path.join(__dirname, 'seed_reporting_fixtures.php');

    // 1. Remove fixtures (best effort -- the scratch DB is about to be dropped
    //    anyway, so a hiccup here doesn't matter, but do it for symmetry/logs).
    try {
        execFileSync('php', [seeder, 'teardown'], { stdio: 'inherit' });
    } catch (e) {
        console.error('[selfhost-global-teardown] fixture teardown failed:', e.message);
    }

    // 2. Tear the install down + restore the live config. If THIS fails, the box
    //    may be left without its config -- surface it loudly (and try doctor).
    try {
        execFileSync('php', [harness, 'down'], { stdio: 'inherit' });
    } catch (e) {
        console.error('[selfhost-global-teardown] CRITICAL: `down` failed:', e.message);
        try {
            execFileSync('php', [harness, 'doctor'], { stdio: 'inherit' });
        } catch (e2) {
            console.error('[selfhost-global-teardown] doctor also failed:', e2.message);
        }
        throw e; // a failed restore leaves the site down -- do not hide it
    }
};
