// @ts-check
/**
 * Playwright globalTeardown: remove the reporting fixtures after the browser
 * specs finish, so the fixture site/user/pageviews never linger in the live
 * reporting interface. Delegates to the PHP seeder's `teardown` command.
 *
 * Teardown runs even if specs failed. It must NOT throw on a cleanup hiccup and
 * mask the real test result, so a non-zero seeder exit is logged, not rethrown.
 */
const { execFileSync } = require('child_process');
const path = require('path');

module.exports = async () => {
    const seeder = path.join(__dirname, 'seed_reporting_fixtures.php');
    try {
        execFileSync('php', [seeder, 'teardown'], { stdio: 'inherit' });
    } catch (e) {
        // Don't let a teardown failure clobber the suite's exit status.
        console.error('[global-teardown] fixture teardown failed:', e.message);
    }
};
