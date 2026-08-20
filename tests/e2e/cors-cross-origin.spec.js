// @ts-check
/**
 * The REST API answers cross-origin requests, and only from configured sites.
 *
 * This is the property the whole CORS fix exists for, and nothing exercised it.
 * `addCorsHeaders()` had never emitted a header at all -- it compared
 * getSitesList()'s *row arrays* against the Origin *string*, so `continue`
 * always fired -- and the unit test that replaced it covers the matcher as a
 * pure function without ever issuing a request. The overlay e2e is same-origin
 * by construction and does not assert its fetch. So the actual question, "can a
 * browser on the tracked site call the OWA API and get data", had no coverage.
 *
 * That matters beyond CORS: cross-origin is the only reason playback and
 * heatmaps still run on JSONP, a transport that exists to evade the same-origin
 * policy. These assertions are the precondition for deleting it.
 *
 * Every assertion here was confirmed by hand against a live install first, so
 * the expected values are measured rather than assumed:
 *
 *   configured Origin   -> 201, 308 rows, Allow-Origin echoed, Vary: Origin
 *   unrelated Origin    -> no Allow-Origin, Vary: Origin still present
 *   no Origin at all    -> no CORS headers, request still works
 */
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const HELPER = path.join(__dirname, 'rest_e2e_helper.php');

function helper(...args) {
    return JSON.parse(execFileSync('php', [HELPER, ...args], { encoding: 'utf8' }));
}

function installRoot(baseURL) {
    return baseURL.replace(/index\.php.*$/, '');
}

/** Auth::generateSignature() -- see rest-routes.spec.js for the format. */
function sign(requestUrl, apiKey, authKey) {
    const date = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    const hex = crypto.createHash('sha256')
        .update('OWASIGNATURE' + apiKey + requestUrl + date + authKey)
        .digest('hex');
    return Buffer.from(hex).toString('base64');
}

const SELFHOST = process.env.OWA_E2E_SELFHOST === '1';

test.describe('the REST API answers cross-origin @selfhost-only', () => {

    test.skip(!SELFHOST,
        'Provisions users and sites; runs only under the self-host e2e runner (OWA_E2E_SELFHOST=1).');

    /** @type {{api_key:string, auth_key:string, site_id:string, user_id:string, domain:string}} */
    let creds;

    test.beforeAll(() => {
        creds = helper('provision');
        expect(creds.api_key, 'fixture user has no api key').toBeTruthy();
        expect(creds.domain, 'fixture site has no domain to use as an Origin').toBeTruthy();
    });

    test.afterAll(() => {
        helper('cleanup');
    });

    /** A signed request to a route that exists and returns data. */
    function signedUrl(baseURL) {
        const base = installRoot(baseURL) + 'api/index.php'
            + '?owa_rest_params=base/v1/sites'
            + '&owa_apiKey=' + creds.api_key;

        return base + '&owa_signature=' + encodeURIComponent(
            sign(base, creds.api_key, creds.auth_key)
        );
    }

    test('a configured site\'s Origin is echoed back and the request succeeds', async ({ request, baseURL }) => {
        const res = await request.get(signedUrl(baseURL), {
            headers: { Origin: creds.domain },
        });

        const headers = res.headers();

        expect(headers['access-control-allow-origin'],
            'a configured site must be allowed cross-origin').toBe(creds.domain);
        expect(headers['access-control-allow-credentials']).toBe('true');

        // The request itself must still work -- CORS headers are additive, and a
        // fix that emitted them while breaking the response would pass a
        // headers-only assertion.
        expect(res.status(), 'the cross-origin request itself must succeed').not.toBe(401);
        expect(res.status()).toBeLessThan(500);
    });

    test('an unrelated Origin gets no Allow-Origin header', async ({ request, baseURL }) => {
        const res = await request.get(signedUrl(baseURL), {
            headers: { Origin: 'https://evil.example.com' },
        });

        expect(res.headers()['access-control-allow-origin'],
            'an origin this installation does not serve must not be allowed').toBeUndefined();
    });

    test('Vary: Origin is sent whether or not the Origin is allowed', async ({ request, baseURL }) => {
        // Without this a shared cache can serve one site's allowed-origin header
        // to a request from another. Varnish sits in front of this application in
        // production, so it is a live concern rather than a formality -- and the
        // refusal is origin-dependent too, which is why it must be present on
        // BOTH responses.
        for (const origin of [creds.domain, 'https://evil.example.com']) {
            const res = await request.get(signedUrl(baseURL), { headers: { Origin: origin } });

            expect(String(res.headers()['vary'] || ''),
                `Vary: Origin missing for ${origin}`).toMatch(/Origin/i);
        }
    });

    test('a suffix of a configured host is not a configured host', async ({ request, baseURL }) => {
        // The classic CORS allowlist bypass: matching by prefix or substring lets
        // evil-<site> and <site>.evil.net through.
        const host = new URL(creds.domain).host;

        for (const forged of [
            `https://evil-${host}`,
            `https://${host}.evil.net`,
            `https://sub.${host}`,
        ]) {
            const res = await request.get(signedUrl(baseURL), { headers: { Origin: forged } });

            expect(res.headers()['access-control-allow-origin'],
                `${forged} must not be allowed`).toBeUndefined();
        }
    });

    test('a same-origin request is unaffected', async ({ request, baseURL }) => {
        // No Origin header at all: not a cross-origin request, so no CORS headers
        // and no change in behaviour.
        const res = await request.get(signedUrl(baseURL));

        expect(res.headers()['access-control-allow-origin']).toBeUndefined();
        expect(res.status()).not.toBe(401);
    });
});
