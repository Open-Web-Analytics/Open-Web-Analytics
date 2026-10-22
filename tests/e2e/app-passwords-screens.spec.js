const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { loginAs, adminLogin } = require('./fixtures');

/**
 * The application password screens in a browser: a user makes one under My
 * Preferences, uses it against the REST API and revokes it; an admin revokes
 * another user's from that user's screen.
 *
 * Requests carry X-Forwarded-Proto: https, as in app-passwords.spec.js: the
 * self-host runner is plain HTTP and passwords need HTTPS by default.
 */

const HELPER = path.join(__dirname, 'app_password_e2e_helper.php');

function helper(...args) {
    return JSON.parse(execFileSync('php', [HELPER, ...args], { encoding: 'utf8' }));
}

const SELFHOST = process.env.OWA_E2E_SELFHOST === '1';

test.describe('application password screens @selfhost-only', () => {

    test.skip(!SELFHOST,
        'Provisions a user and passwords; runs only under the self-host e2e runner (OWA_E2E_SELFHOST=1).');

    let fx;

    test.beforeAll(() => {
        fx = helper('provision');
    });

    test.afterAll(() => {
        helper('cleanup');
    });

    function sitesUrl() {
        const root = test.info().project.use.baseURL.replace(/index\.php.*$/, '');
        return root + 'api/index.php?owa_rest_params=base/v1/sites';
    }

    async function status(request, token) {
        const res = await request.fetch(sitesUrl(), {
            method: 'GET',
            headers: { 'X-Forwarded-Proto': 'https', Authorization: 'Bearer ' + token },
        });
        return res.status();
    }

    async function confirmAndWait(page, locator) {
        await locator.click();
        await expect(page.locator('#owa_confirmDialog')).toBeVisible();
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('.owa_confirmProceed').click(),
        ]);
    }

    test('a user makes a password, uses it, and revokes it', async ({ page, request }) => {
        await loginAs(page, fx.user_id, fx.password);

        await page.goto('?owa_do=base.myAppPasswords', { waitUntil: 'networkidle' });
        await expect(page.locator('.owa_hierarchyNav a', { hasText: 'Application Passwords' })).toBeVisible();

        await page.fill('input[name="name"]', 'e2e screen');
        await page.selectOption('select[name="scope"]', 'full');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('input[value="Add Application Password"]'),
        ]);

        const token = await page.locator('.owa_appPasswordToken').inputValue();
        expect(token).toMatch(/^[0-9A-Za-z]{44}$/);

        const row = page.locator(`tr[data-lookup-id="${token.slice(0, 12)}"]`);
        await expect(row).toContainText('e2e screen');
        await expect(row).toContainText('Read and write');

        expect(await status(request, token)).toBeLessThan(300);

        // The form redirected, so a reload is a GET: no second password, and
        // the token is not shown again.
        expect(page.url()).toContain('do=base.myAppPasswords');
        await page.reload({ waitUntil: 'networkidle' });
        await expect(page.locator('.owa_appPasswordToken')).toHaveCount(0);
        await expect(page.locator('tr[data-lookup-id]', { hasText: 'e2e screen' })).toHaveCount(1);
        expect(await page.content()).not.toContain(token.slice(12));

        // Nor does navigating back to the list.
        await page.goto('?owa_do=base.myAppPasswords', { waitUntil: 'networkidle' });
        await expect(page.locator('.owa_appPasswordToken')).toHaveCount(0);
        expect(await page.content()).not.toContain(token.slice(12));
        await expect(row).toContainText(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/);

        await confirmAndWait(page, row.locator('.owa_appPasswordRevoke'));

        await expect(page.getByText('Application password revoked.')).toBeVisible();
        await expect(row).toHaveCount(0);
        expect(await status(request, token)).toBe(401);
    });

    test('an admin revokes another user\'s password from their user screen', async ({ page, request }) => {
        expect(await status(request, fx.read.token)).toBeLessThan(300);

        await adminLogin(page);
        await page.goto(`?owa_do=base.usersProfile&edit=1&user_id=${encodeURIComponent(fx.user_id)}`,
            { waitUntil: 'networkidle' });

        const row = page.locator(`tr[data-lookup-id="${fx.read.lookup_id}"]`);
        await expect(row).toContainText('e2e read');
        expect(await page.content()).not.toContain(fx.read.token.slice(12));

        await confirmAndWait(page, row.locator('.owa_appPasswordRevoke'));

        await expect(page.getByText('Application password revoked.')).toBeVisible();
        await expect(row).toHaveCount(0);
        await expect(page.locator(`tr[data-lookup-id="${fx.full.lookup_id}"]`)).toHaveCount(1);
        expect(await status(request, fx.read.token)).toBe(401);
    });
});
