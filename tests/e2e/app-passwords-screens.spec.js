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

        const dialog = page.locator('#owa_appPasswordDialog');

        await page.click('.owa_appPasswordAdd');
        await expect(dialog).toBeVisible();
        await dialog.locator('input[name="name"]').fill('e2e screen');
        await dialog.locator('select[name="scope"]').selectOption('full');
        const [response] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('base.myAppPasswordCreateAjax')),
            page.click('.owa_appPasswordCreate'),
        ]);

        // An internal endpoint: JSON, not sniffable, not cacheable, same origin only.
        expect(response.status()).toBe(201);
        expect(response.request().method()).toBe('POST');
        const headers = response.headers();
        expect(headers['content-type']).toContain('application/json');
        expect(headers['x-content-type-options']).toBe('nosniff');
        expect(headers['cache-control']).toContain('no-store');
        expect(headers['access-control-allow-origin']).toBeUndefined();

        // The token is in the dialog, and the page never left the list.
        const tokenField = dialog.locator('.owa_appPasswordToken');
        await expect(tokenField).toBeVisible();
        const token = await tokenField.inputValue();
        expect(token).toMatch(/^[0-9A-Za-z]{44}$/);
        expect(page.url()).toContain('do=base.myAppPasswords');

        // The row is in the roster as soon as the answer is, behind the dialog.
        const row = page.locator(`tr[data-lookup-id="${token.slice(0, 12)}"]`);
        await expect(row).toContainText('e2e screen');
        await expect(row).toContainText('Read and write');

        await page.click('.owa_appPasswordDone');
        await expect(dialog).toBeHidden();
        await expect(row).toHaveCount(1);
        expect(await page.content()).not.toContain(token.slice(12));

        expect(await status(request, token)).toBeLessThan(300);

        // A reload is the GET it always was: one password, and no token.
        await page.reload({ waitUntil: 'networkidle' });
        await expect(page.locator('tr[data-lookup-id]', { hasText: 'e2e screen' })).toHaveCount(1);
        expect(await page.content()).not.toContain(token.slice(12));
        await expect(row).toContainText(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/);

        await confirmAndWait(page, row.locator('.owa_appPasswordRevoke'));

        await expect(page.getByText('Application password revoked.')).toBeVisible();
        await expect(row).toHaveCount(0);
        expect(await status(request, token)).toBe(401);
    });

    test('the dialog asks for a name, and Escape closes it', async ({ page }) => {
        await loginAs(page, fx.user_id, fx.password);
        await page.goto('?owa_do=base.myAppPasswords', { waitUntil: 'networkidle' });

        const dialog = page.locator('#owa_appPasswordDialog');

        await page.click('.owa_appPasswordAdd');
        await page.click('.owa_appPasswordCreate');
        await expect(dialog.locator('.owa_appPasswordError')).toHaveText('Give the application password a name.');
        await expect(dialog.locator('.owa_appPasswordToken')).toBeHidden();

        await dialog.locator('input[name="name"]').fill('e2e escape');
        await dialog.locator('input[name="name"]').press('Enter');
        await expect(dialog.locator('.owa_appPasswordToken')).toBeVisible();
        const token = await dialog.locator('.owa_appPasswordToken').inputValue();

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();

        const row = page.locator(`tr[data-lookup-id="${token.slice(0, 12)}"]`);
        await expect(row).toContainText('e2e escape');
        await expect(row).toContainText('Read only');

        // The added row revokes like a drawn one: its form carries the page's nonce.
        await confirmAndWait(page, row.locator('.owa_appPasswordRevoke'));
        await expect(page.getByText('Application password revoked.')).toBeVisible();
        await expect(row).toHaveCount(0);
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
