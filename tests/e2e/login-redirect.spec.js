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
    await page.fill('input[name="user_id"]', FIXTURE.adminUserId);
    await page.fill('input[name="password"]', FIXTURE.adminPassword);
    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.click('input[name="submit_btn"]'),
    ]);
}

const onLoginForm = (page) => page.locator('input[name="password"]').count();

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

    /** Deep-linking to a report while logged out must still land on the report. */
    test('a read-only destination is still resumed after login', async ({ page }) => {
        await page.goto(
            `?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}&owa_period=last_thirty_days`,
            { waitUntil: 'networkidle' }
        );

        expect(await onLoginForm(page), 'a report should bounce a logged-out user to login')
            .toBeGreaterThan(0);

        await submitLoginForm(page);

        expect(await onLoginForm(page), 'correct credentials must not return the login form')
            .toBe(0);

        // Read the parameter rather than the string: reports are addressed as
        // base.report + a reportId now, and a substring test for 'reportId='
        // would also match 'owa_reportId=' -- which happens to be right here,
        // but only by accident. Parse it and the assertion says what it means.
        const resumed = new URL(page.url()).searchParams;

        expect(resumed.get('owa_reportId') ?? resumed.get('reportId'),
            'the requested report should be resumed after login')
            .toBe('dashboard');

        expect(resumed.get('owa_do') ?? resumed.get('do'),
            'and it should be resumed through the report dispatcher')
            .toBe('base.report');
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
