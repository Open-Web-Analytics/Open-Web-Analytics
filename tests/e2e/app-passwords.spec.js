const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

/**
 * Application passwords over real HTTP: the Authorization header reaching
 * PHP, and the REST API answering as the password's user.
 *
 * The self-host runner serves plain HTTP. Application passwords need HTTPS by
 * default, so each request carries X-Forwarded-Proto: https, as a terminating
 * proxy would set it; one test leaves it off.
 */

const HELPER = path.join(__dirname, 'app_password_e2e_helper.php');

function helper(...args) {
    return JSON.parse(execFileSync('php', [HELPER, ...args], { encoding: 'utf8' }));
}

const SELFHOST = process.env.OWA_E2E_SELFHOST === '1';

const HTTPS = { 'X-Forwarded-Proto': 'https' };

test.describe('application passwords authenticate REST requests @selfhost-only', () => {

    test.skip(!SELFHOST,
        'Provisions a user and passwords; runs only under the self-host e2e runner (OWA_E2E_SELFHOST=1).');

    let fx;

    test.beforeAll(() => {
        fx = helper('provision');
        expect(fx.full.token, 'no full password was made').toBeTruthy();
        expect(fx.read.token, 'no read-only password was made').toBeTruthy();
    });

    test.afterAll(() => {
        helper('cleanup');
    });

    function url(route) {
        const root = test.info().project.use.baseURL.replace(/index\.php.*$/, '');
        return root + 'api/index.php?owa_rest_params=' + route;
    }

    function bearer(token) {
        return { ...HTTPS, Authorization: 'Bearer ' + token };
    }

    async function sites(request, headers) {
        return request.fetch(url('base/v1/sites'), { method: 'GET', headers });
    }

    test('a Bearer token reads the API as its user', async ({ request }) => {
        const res = await sites(request, bearer(fx.full.token));

        expect(res.status()).toBeLessThan(300);
        expect(await res.text()).toContain(fx.site_id);

        const used = helper('list').passwords.find((p) => p.lookup_id === fx.full.lookup_id);
        expect(Number(used.last_used_at), 'the use was not recorded').toBeGreaterThan(0);
    });

    test('Basic auth with the user and the token works too', async ({ request }) => {
        const pair = Buffer.from(fx.user_id + ':' + fx.full.token).toString('base64');
        const res = await sites(request, { ...HTTPS, Authorization: 'Basic ' + pair });

        expect(res.status()).toBeLessThan(300);
    });

    test('a wrong token is a 401', async ({ request }) => {
        const wrong = fx.full.token.slice(0, 12) + 'x'.repeat(32);

        expect((await sites(request, bearer(wrong))).status()).toBe(401);
    });

    test('a token in the URL is not accepted', async ({ request }) => {
        const res = await request.fetch(url('base/v1/sites') + '&owa_apiKey=' + fx.full.token,
            { method: 'GET', headers: HTTPS });

        expect(res.status()).toBe(401);
    });

    test('a read-only password reads but cannot write', async ({ request }) => {
        expect((await sites(request, bearer(fx.read.token))).status()).toBeLessThan(300);

        const post = await request.fetch(url('base/v1/sites'),
            { method: 'POST', headers: bearer(fx.read.token) });
        expect(post.status()).toBe(401);
    });

    test('over plain HTTP the password is refused', async ({ request }) => {
        const res = await sites(request, { Authorization: 'Bearer ' + fx.full.token });

        expect(res.status()).toBe(401);
    });

    // Last: it revokes the full password the tests above use.
    test('a revoked password is a 401', async ({ request }) => {
        expect(helper('revoke', fx.full.lookup_id).revoked).toBe(true);

        expect((await sites(request, bearer(fx.full.token))).status()).toBe(401);
        expect((await sites(request, bearer(fx.read.token))).status(), 'revoking one revoked both')
            .toBeLessThan(300);
    });
});
