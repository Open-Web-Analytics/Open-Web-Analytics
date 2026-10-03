const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

/**
 * The web install wizard again, this time choosing "Let OWA create a new
 * database for this install".
 *
 * Runs after install-web (playwright.install.config.js), whose wizard run left
 * an owa-config.php pointing at the web scratch database; `unconfig` removes
 * it so install.php runs the wizard again. The database this run creates is
 * named owa_org_<organization id> by the wizard, not by the harness, so the
 * spec records the name the page shows before submitting: the harness only
 * drops (and only asserts on) names recorded that way, and teardown drops them
 * even when the run fails.
 */

const HARNESS = path.join(__dirname, 'install_harness.php');

function harness(...args) {
    return JSON.parse(execFileSync('php', [HARNESS, ...args], { encoding: 'utf8' }));
}

const INFO = harness('info');

test.describe('install: web wizard creating its own database', () => {

    test('creates owa_org_<id>, installs into it, and names the Organization by it', async ({ page, baseURL }) => {
        const installBase = new URL('install.php', baseURL).toString();
        const creds = harness('webform');

        expect(harness('unconfig').status).toBe('unconfigured');

        await page.goto(`${installBase}?do=base.installStart`, { waitUntil: 'networkidle' });
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('a', { hasText: 'Get started' }).first().click(),
        ]);
        await expect(page.locator('input[name="db_host"]')).toBeVisible();

        // "Use an existing database" is the default; the name of a database OWA
        // creates is shown, not asked for.
        await expect(page.locator('input[name="db_mode"][value="existing"]')).toBeChecked();
        await expect(page.locator('input[name="db_create_name"][type="text"]')).toHaveCount(0);
        await expect(page.locator('select[name="db_type"] option[value="mysql"]')).toHaveText('MySQL / MariaDB');

        await page.locator('input[name="db_mode"][value="create"]').check();
        const name = (await page.locator('input[name="db_create_name"]').inputValue()).trim();
        expect(name).toMatch(/^owa_org_[1-9][0-9]{0,18}$/);
        await expect(page.locator('code', { hasText: name })).toBeVisible();
        expect(harness('record-created', name).status).toBe('recorded');

        await page.fill('input[name="public_url"]', creds.public_url);
        await page.selectOption('select[name="db_type"]', creds.db_type);
        await page.fill('input[name="db_host"]', creds.db_host);
        await page.fill('input[name="db_port"]', String(creds.db_port));
        await page.fill('input[name="db_user"]', creds.db_user);
        await page.fill('input[name="db_password"]', creds.db_password);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="save_button"]').click(),
        ]);

        // The defaults step names the server and the database it connected to.
        // The site field: the login form, where a wrong redirect lands, has a
        // user_id field too.
        await expect(page.locator('input[name="domain"]')).toBeVisible();
        const connected = await page.locator('body').innerText();
        expect(connected).toMatch(/Connected to (MySQL|MariaDB) \d+\.\d+/);
        expect(connected).toContain(name);

        await page.fill('input[name="domain"]', INFO.install_site);
        await page.selectOption('select[name="timezone"]', 'Europe/London');
        await page.fill('input[name="user_id"]', INFO.install_admin_id);
        await page.fill('input[name="email_address"]', INFO.install_admin_id);
        await page.fill('input[name="password"]', INFO.install_admin_pass);
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[name="save_button"]').click(),
        ]);

        const result = harness('assert-created', name);
        expect(result.status).toBe('installed');
        expect(result.organization_named_by_db).toBe(true);
        // The tables' own character set, not utf8mb4: a table created without
        // naming one inherits this.
        expect(result.charset).toMatch(/^utf8(mb3)?$/);
    });
});
