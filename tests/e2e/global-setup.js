// @ts-check
/**
 * Playwright globalSetup: seed the deterministic reporting fixtures ONCE before
 * the browser specs run. Delegates to the PHP seeder (the single source of truth
 * for what a fixture is) rather than re-implementing seeding in JS.
 *
 * Paired with global-teardown.js so a normal `npm run test:e2e` run leaves the
 * database exactly as it found it -- no fixture site/user/pageviews left behind
 * in the live reporting interface. The standalone test:e2e:seed / :teardown npm
 * scripts still exist for manual use.
 */
const { execFileSync } = require('child_process');
const path = require('path');

module.exports = async () => {
    const seeder = path.join(__dirname, 'seed_reporting_fixtures.php');
    // Inherit stdio so the seeder's JSON summary is visible in the test output;
    // throw on non-zero exit so a failed seed fails the run loudly (a green run
    // against un-seeded data would be a false pass).
    execFileSync('php', [seeder, 'seed'], { stdio: 'inherit' });
};
