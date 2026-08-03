// @ts-check
/**
 * Where a browser lands after being bounced to the login form.
 *
 * The unit tests for this call notAuthenticatedAction() directly and inspect the
 * controller's data array. That leaves the actual chain unexercised -- whether
 * the login form carries 'go' through its own POST, whether the redirect fires,
 * whether the session survives it. Those are what broke in #979, and they only
 * show up when a real browser walks the flow.
 *
 * Two behaviours are pinned here:
 *
 *   - a nonce-guarded action is NOT resumed after login. A nonce is derived from
 *     the current user_id, so one minted while logged out can never verify once
 *     logged in; replaying the request bounced the user back to the login form,
 *     which reads as a rejected password.
 *   - a read-only destination IS still resumed, so the 126 controllers that do
 *     not require a nonce keep their post-login redirect.
 */

const { test, expect } = require('@playwright/test');
const { FIXTURE, logout } = require('./fixtures');

/** Submit the login form that is currently on screen. */
async function submitLoginForm(page) {
    await page.fill('input[name="owa_user_id"]', FIXTURE.adminUserId);
    await page.fill('input[name="owa_password"]', FIXTURE.adminPassword);
    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('input[name="owa_submit_btn"]'),
    ]);
}

const onLoginForm = (page) => page.locator('input[name="owa_password"]').count();

test.describe('post-login redirect destination', () => {

    test.beforeEach(async ({ page }) => {
        await logout(page);
    });

    /**
     * The #979 flow. Landing back on the login form after correct credentials is
     * the exact symptom that made it look like an authentication failure.
     */
    test('a nonce-guarded action is not resumed, and login is not re-prompted', async ({ page }) => {
        await page.goto('?owa_do=base.sitesDelete&owa_siteId=nonexistent', { waitUntil: 'networkidle' });

        expect(await onLoginForm(page), 'a write action should bounce a logged-out user to login')
            .toBeGreaterThan(0);

        await submitLoginForm(page);

        // The failure being guarded: correct credentials, login form again.
        expect(await onLoginForm(page), 'correct credentials must not return the login form')
            .toBe(0);

        // And it must not have replayed the write.
        expect(page.url(), 'a state-changing action must not be resumed after login')
            .not.toContain('sitesDelete');
    });

    /**
     * The action is not resumed, but the screen that offered it is. Navigating
     * from a real page means the browser sends a same-origin Referer, which is
     * the page that would mint a fresh nonce for the authenticated identity.
     *
     * base.updates is the referring page because it renders for a logged-out
     * visitor -- which is the #979 situation exactly, and is why its Apply link
     * carries a nonce minted with no user_id. Starting from a gated screen would
     * bounce to the login form first, and the Referer would be that form.
     */
    test('the screen the action was reached from is resumed instead', async ({ page }) => {
        // Land on a real page first so the click carries a Referer -- a bare
        // goto() has none, and there would be nothing to resume.
        await page.goto('?owa_do=base.updates', { waitUntil: 'networkidle' });

        expect(await onLoginForm(page), 'base.updates should render without a login')
            .toBe(0);

        await page.evaluate(() => {
            const a = document.createElement('a');
            a.href = '?owa_do=base.sitesDelete&owa_siteId=nonexistent';
            a.id = 'go-to-write-action';
            a.textContent = 'delete';   // needs size, or it cannot be clicked
            document.body.appendChild(a);
        });

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('#go-to-write-action').click(),
        ]);

        expect(await onLoginForm(page), 'a write action should bounce a logged-out user to login')
            .toBeGreaterThan(0);

        await submitLoginForm(page);

        expect(await onLoginForm(page), 'correct credentials must not return the login form')
            .toBe(0);

        expect(page.url(), 'the originating screen should be resumed')
            .toContain('base.updates');

        expect(page.url(), 'but never the action itself')
            .not.toContain('sitesDelete');

        // Without this the outcome is ambiguous -- nothing on the page would say
        // whether the action they clicked had been carried out.
        const body = (await page.content()).toLowerCase();
        expect(body, 'the screen should say the action was not carried out')
            .toMatch(/not carried out|try it again/);
    });

    /** Deep-linking to a report while logged out must still land on the report. */
    test('a read-only destination is still resumed after login', async ({ page }) => {
        await page.goto(
            `?owa_do=base.reportDashboard&owa_siteId=${FIXTURE.siteId}&owa_period=last_thirty_days`,
            { waitUntil: 'networkidle' }
        );

        expect(await onLoginForm(page), 'a report should bounce a logged-out user to login')
            .toBeGreaterThan(0);

        await submitLoginForm(page);

        expect(await onLoginForm(page), 'correct credentials must not return the login form')
            .toBe(0);

        expect(page.url(), 'the requested report should be resumed after login')
            .toContain('reportDashboard');
    });

    /**
     * 'go' is read back off the request, so the destination is whatever the URL
     * says unless the server resolves it. Asserted over real HTTP because the
     * unit tests exercise the resolver in isolation.
     */
    test('an offsite destination is not followed after login', async ({ page }) => {
        await page.goto('?owa_do=base.loginForm&owa_go=https://example.com/', { waitUntil: 'networkidle' });

        await submitLoginForm(page);

        const url = page.url();

        expect(url, 'the browser must not be sent to another host').not.toContain('example.com');
        expect(url, 'it should stay on this installation').toContain(
            new URL(test.info().project.use.baseURL).host
        );
    });
});
