// @ts-check
/**
 * globalTeardown for the INSTALL-flow specs (playwright.install.config.js only).
 *
 * Runs even if the install specs failed (Playwright always runs globalTeardown),
 * which is essential here: a run that dies mid-flow leaves the live owa-config.php
 * stashed in a backup and the site DOWN until it's put back. This restores it and
 * drops both scratch databases.
 *
 * It must NOT throw on a routine cleanup hiccup and mask the real test result --
 * BUT a failure to restore the live config is not routine (the site stays down),
 * so `restore` itself exits non-zero in that case and we surface it loudly.
 */
const { execFileSync } = require('child_process');
const path = require('path');

module.exports = async () => {
    const harness = path.join(__dirname, 'install_harness.php');
    try {
        execFileSync('php', [harness, 'restore'], { stdio: 'inherit' });
    } catch (e) {
        // restore() only exits non-zero when it could not put the live config
        // back -- i.e. the site is down. Make that impossible to miss, and try
        // the doctor as a last resort.
        console.error('[install-teardown] restore FAILED -- attempting doctor recovery:', e.message);
        try {
            execFileSync('php', [harness, 'doctor'], { stdio: 'inherit' });
        } catch (e2) {
            console.error('[install-teardown] doctor ALSO failed -- restore owa-config.php MANUALLY:', e2.message);
        }
        throw e;
    }
};
