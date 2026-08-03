// @ts-check
/**
 * A signed-in user with a stale nonce is told the form expired, not to log in.
 *
 * A nonce carries a time window and the user_id it was minted for, so it lapses
 * for perfectly valid sessions -- a form left open too long, or one rendered
 * before signing in as somebody else. Both conditions used to reach the
 * not-authenticated handler, so the user was shown the login form despite having
 * a working session, re-entered credentials that were never the problem, and had
 * no way to tell what had actually happened.
 *
 * Driven through the real form rather than a hand-built URL. The capability
 * check runs BEFORE the nonce check, so a direct GET is refused for lacking
 * privileges and never reaches the code under test -- assertions written that
 * way pass against the capability error page and prove nothing.
 */

const { test, expect } = require('@playwright/test');
const { adminLogin } = require('./fixtures');

const onLoginForm = (page) => page.locator('input[name="owa_password"]').count();

/** Load a real admin form, invalidate its nonce, and submit it. */
async function submitWithStaleNonce(page) {
    await adminLogin(page);
    await page.goto('?owa_do=base.optionsGeneral', { waitUntil: 'networkidle' });

    // createNonceFormField() emits <input type="hidden" name="<ns>nonce">, and
    // ns defaults to 'owa_'.
    const nonce = page.locator('input[name="owa_nonce"]');
    await expect(nonce, 'the options form should carry a nonce').toHaveCount(1);

    // Exactly what an expired or wrong-user nonce looks like to the server.
    await nonce.evaluate((el) => { el.value = 'staleaaaaa'; });

    // The submit control is a button carrying name=owa_action value=<action>;
    // the nonce is a separate hidden field, which is what was just invalidated.
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('button[name="owa_action"][value="base.optionsUpdate"]').click(),
    ]);
}

test.describe('a stale nonce on an authenticated session', () => {

    test('does not ask an authenticated user to log in again', async ({ page }) => {
        await submitWithStaleNonce(page);

        expect(await onLoginForm(page), 'a signed-in user must not be re-prompted for credentials')
            .toBe(0);
    });

    test('explains that the form lapsed rather than blaming the credentials', async ({ page }) => {
        await submitWithStaleNonce(page);

        const body = (await page.content()).toLowerCase();

        expect(body, 'the page should say the form is no longer usable')
            .toMatch(/(no longer valid|expired)/);

        expect(body, 'it must not suggest the password was wrong')
            .not.toMatch(/user name or password did not match/);

        // The capability page is a different refusal; matching it would mean the
        // request never reached the nonce check.
        expect(body, 'this should be the nonce refusal, not the capability one')
            .not.toMatch(/lacks the necessary privileges/);
    });

    /**
     * Telling someone to start over is only actionable with the way back. The
     * form was submitted from base.optionsGeneral, so that is where the link
     * goes -- and loading it mints a nonce that will verify.
     */
    test('offers the way back to the screen the form came from', async ({ page }) => {
        await submitWithStaleNonce(page);

        const back = page.locator('a[href*="base.optionsGeneral"]');

        await expect(back, 'the originating screen should be linked').toHaveCount(1);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            back.first().click(),
        ]);

        // Back on a working form, with a nonce minted for this session.
        await expect(page.locator('input[name="owa_nonce"]')).toHaveCount(1);
        expect(await onLoginForm(page), 'and still signed in').toBe(0);
    });

    /**
     * The nonce exists so a state-changing request is one the user just
     * confirmed. Refusing it must not quietly apply the change anyway.
     */
    test('does not carry out the action', async ({ page }) => {
        await submitWithStaleNonce(page);

        const body = (await page.content()).toLowerCase();

        expect(body, 'a refused action must not report success').not.toMatch(/success/);
    });
});
