const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

/**
 * Real-browser characterization of the OWA WEB INSTALL WIZARD -- the flow a
 * first-time user hits at install.php on a fresh, un-installed checkout.
 *
 * This is the only path that exercises config-file WRITING, the environment
 * checks, and the two nonce'd install POSTs. It drives the actual wizard in
 * headless Chromium end to end:
 *
 *   base.installStart -> base.installCheckEnv -> base.installConfigEntry
 *     -> base.installConfig (writes owa-config.php, validates the DB connection)
 *     -> base.installDefaultsEntry -> base.installBase (schema + admin + site
 *        + install_complete) -> base.installFinish
 *
 * ISOLATION (see install_harness.php + playwright.install.config.js): the harness
 * has already stashed the live owa-config.php out of the way and CREATEd an empty
 * scratch database (SCRATCH_DB_WEB). With no config file present, install.php
 * runs the wizard unauthenticated (the install_schema capability is granted to
 * the "everyone" role). The wizard writes a fresh owa-config.php pointing at the
 * scratch DB and installs into it; nothing here touches the live schema. The
 * config-writing step works because php-fpm runs as the repo owner (ec2-user).
 *
 * Prereq: run under playwright.install.config.js (its globalSetup stashes the
 * config + creates the scratch DBs; globalTeardown drops them + restores the
 * config even on failure). Do NOT run this spec under the normal e2e config.
 */

const HARNESS = path.join(__dirname, 'install_harness.php');

/** Read the harness's constant identifiers (the contract this spec installs to). */
function harnessInfo() {
    const json = execFileSync('php', [HARNESS, 'info'], { encoding: 'utf8' });
    return JSON.parse(json);
}

/** Assert the scratch DB got a working install, straight from SQL (no OWA boot). */
function assertWebInstalled() {
    const json = execFileSync('php', [HARNESS, 'assert-web'], { encoding: 'utf8' });
    return JSON.parse(json);
}

const INFO = harnessInfo();

// The wizard is served by install.php (not index.php), and its step param is
// `do` (NOT owa_do); form FIELDS use the owa_ namespace.
const WIZARD_URL = 'install.php?do=base.installStart';

test.describe('install: web wizard (fresh install into a scratch DB)', () => {

    // The whole wizard is one ordered journey; keep it a single test so a
    // mid-flow failure can't leave a half-installed scratch DB the next test
    // would trip over. (The harness teardown drops the DB regardless.)
    test('completes install.php end to end and marks the install complete', async ({ page, baseURL }) => {
        // install.php lives alongside index.php; derive its origin from baseURL
        // (which points at .../owa/index.php).
        const installBase = new URL('install.php', baseURL).toString();

        // The scratch DB connection details the config form must submit. Pull the
        // live creds (host/user/password/type/port) + the scratch DB name from the
        // harness so the form points at the RIGHT server + scratch schema.
        const creds = JSON.parse(
            execFileSync('php', [HARNESS, 'webform'], { encoding: 'utf8' })
        );

        // --- STEP 1: Start ----------------------------------------------------
        await page.goto(`${installBase}?do=base.installStart`, { waitUntil: 'networkidle' });
        await expect(page.locator('text=Welcome to the Installer')).toBeVisible();

        // --- STEP 2: Env check (follow the "Let's Get Started" link) ----------
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('a', { hasText: "Let's Get Started" }).first().click(),
        ]);
        // A good environment routes straight to the config-entry form (the DB
        // fields). If the env were bad we'd see error rows instead.
        const dbHostField = page.locator('input[name="db_host"]');
        await expect(dbHostField).toBeVisible();

        // --- STEP 3+4: Config entry -> installConfig (writes owa-config.php) ---
        await page.fill('input[name="public_url"]', creds.public_url);
        await page.selectOption('select[name="db_type"]', creds.db_type);
        await page.fill('input[name="db_host"]', creds.db_host);
        await page.fill('input[name="db_port"]', String(creds.db_port));
        await page.fill('input[name="db_name"]', creds.db_name); // the SCRATCH db
        await page.fill('input[name="db_user"]', creds.db_user);
        await page.fill('input[name="db_password"]', creds.db_password);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="save_button"]').click(),
        ]);

        // installConfig validates the connection, writes owa-config.php, and
        // redirects to the defaults-entry form (the admin-user + site fields).
        await expect(page.locator('input[name="user_id"]')).toBeVisible();

        // --- STEP 5+6: Defaults entry -> installBase (schema+admin+site) ------
        // domain must NOT start with http (installBase validation) -- protocol
        // is a separate select.
        await page.selectOption('select[name="protocol"]', INFO.install_site.startsWith('https') ? 'https://' : 'http://');
        await page.fill('input[name="domain"]', INFO.install_site.replace(/^https?:\/\//, ''));

        // The wizard asks for the reporting timezone now, and installBase
        // requires it. It is asked here because the choice is NOT retroactive:
        // yyyymmdd and the date-part columns are derived in this zone and written
        // into every fact row, so changing it later re-buckets new data while
        // history keeps its old day boundaries.
        // Europe/London deliberately, not the America/Los_Angeles default: picking
        // the default would pass whether or not the submitted value is honoured.
        // (UTC is not selectable -- conf/country2Timezones.php lists 285 zones
        // and none of them is UTC, GMT or Etc/UTC.)
        await page.selectOption('select[name="timezone"]', 'Europe/London');
        await expect(page.locator('select[name="timezone"]')).toHaveValue('Europe/London');

        await page.fill('input[name="user_id"]', INFO.install_admin_id);
        await page.fill('input[name="email_address"]', INFO.install_admin_id);
        await page.fill('input[name="password"]', INFO.install_admin_pass);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="save_button"]').click(),
        ]);

        // --- STEP 7: Finish ---------------------------------------------------
        // The finish screen shows the admin credentials + tracker snippet.
        const finishBody = (await page.locator('body').innerText()).toLowerCase();
        expect(finishBody).toMatch(/complete|success|tracking|admin/);

        // --- ASSERT (independent of the finish screen): the scratch DB really
        //     got the schema, the admin user, a site, and install_complete=true.
        const result = assertWebInstalled();
        expect(result.status).toBe('installed');

        // --- GUARD: re-hitting install.php now redirects (isInstallComplete),
        //     rather than re-rendering the wizard -- the install-complete guard.
        const resp = await page.goto(`${installBase}?do=base.installStart`, {
            waitUntil: 'domcontentloaded',
        });
        // Either we were redirected off install.php, or the start form is gone.
        const landedOnInstall = page.url().includes('install.php');
        const startVisible = await page
            .locator('text=Welcome to the Installer')
            .count();
        expect(landedOnInstall && startVisible > 0).toBe(false);
    });
});
