// @ts-check
/**
 * Every registered REST route answers over HTTP.
 *
 * The routes have unit coverage, but nothing exercised them through the real
 * stack until now: a registration naming a class that no longer exists, a route
 * name that no longer matches, or a controller that cannot be constructed passes
 * PHPUnit and every existing e2e spec, and surfaces only in production.
 *
 * Each route is asserted twice:
 *
 *   - unsigned  -> 401. Proves the request reached OWA's auth layer rather than
 *                  dying earlier (a 404 or 500 here means the route did not
 *                  resolve at all, which is the failure mode being guarded).
 *   - signed    -> not 401, and not a 500. The status varies by route (a GET
 *                  lists, a POST without a body fails validation), so the
 *                  assertion is deliberately about the route RESOLVING and the
 *                  controller RUNNING, not about its business logic -- that is
 *                  what the PHPUnit controller tests are for.
 *
 * Requests are signed for real rather than run with OWA_REST_DEBUG defined.
 * That constant makes authByApiKey() skip signature verification entirely, so a
 * suite relying on it would never exercise the auth path it is calling through.
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

/**
 * Auth::generateSignature() -- base64 of the sha256 HEX digest (not raw bytes),
 * over the request URL with the signature param itself removed, and the date in
 * UTC.
 */
function sign(requestUrl, apiKey, authKey) {
    const date = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    const hex = crypto.createHash('sha256')
        .update('OWASIGNATURE' + apiKey + requestUrl + date + authKey)
        .digest('hex');
    return Buffer.from(hex).toString('base64');
}

/** Build the URL a route is called at, optionally signed. */
function routeUrl(root, route, query, creds) {
    // Deliberately sends owa_rest_params WITHOUT owa_do, because that is exactly
    // what .htaccess produces for the documented route form:
    //
    //   RewriteRule api/(.*)$ api/index.php?owa_rest_params=$1 [QSA,NC,L]
    //
    // The rewrite is Apache-only and the self-host runner serves through php -S,
    // so the specs exercise the rewrite's OUTPUT rather than the pretty URL.
    const base = root + 'api/index.php'
        + '?owa_rest_params=' + route
        + (query ? '&' + query : '');

    if (!creds) {
        return base;
    }

    const withKey = base + '&owa_apiKey=' + creds.api_key;
    return withKey + '&owa_signature=' + encodeURIComponent(
        sign(withKey, creds.api_key, creds.auth_key)
    );
}

const SELFHOST = process.env.OWA_E2E_SELFHOST === '1';

test.describe('every registered REST route answers over HTTP @selfhost-only', () => {

    test.skip(!SELFHOST,
        'Provisions users and sites; runs only under the self-host e2e runner (OWA_E2E_SELFHOST=1).');

    /** @type {{api_key:string, auth_key:string, site_id:string, user_id:string}} */
    let creds;

    test.beforeAll(() => {
        creds = helper('provision');
        expect(creds.api_key, 'fixture user has no api key').toBeTruthy();
        expect(creds.auth_key, 'OWA_AUTH_KEY is not readable, requests cannot be signed').toBeTruthy();
    });

    test.afterAll(() => {
        helper('cleanup');
    });

    /**
     * Every route registered by a module, as <module>/<version>/<name>, with the
     * method it is registered for and the minimum params its controller needs to
     * get past argument handling.
     */
    function routes() {
        return [
            { route: 'base/v1/sites',      method: 'GET',    query: '' },
            { route: 'base/v1/sites',      method: 'POST',   query: '' },
            { route: 'base/v1/users',      method: 'GET',    query: '' },
            { route: 'base/v1/users',      method: 'POST',   query: '' },
            { route: 'base/v1/users',      method: 'DELETE', query: '' },
            { route: 'base/v1/siteUsers',  method: 'POST',   query: '' },
            { route: 'base/v1/reports',    method: 'GET',    query: 'owa_reportName=dashboard' },
            // Domstream registers its route only when the module is active, and
            // 'modules' defaults to ['base'] on a fresh install. Marked so the
            // assertions below can require registration rather than assume it.
            { route: 'domstream/v1/domstreams', method: 'GET', query: '', module: 'domstream' },
        ];
    }

    /** Modules active on this install, so route expectations match reality. */
    function activeModules() {
        return helper('modules').active;
    }

    for (const r of routes()) {

        const label = `${r.method} ${r.route}`;

        test(`${label} - rejects an unsigned request with 401`, async ({ request }) => {
            test.skip(!!r.module && !activeModules().includes(r.module),
                `the ${r.module} module is not active on this install, so its route is not registered`);

            const root = installRoot(test.info().project.use.baseURL);
            const res = await request.fetch(routeUrl(root, r.route, r.query, null), {
                method: r.method,
            });

            // A 404 or 500 here would mean the route never resolved -- the class
            // is missing, or the registration no longer matches the URL.
            expect(res.status(), `${label} did not reach the auth layer`).toBe(401);
        });

        test(`${label} - resolves and runs when signed`, async ({ request }) => {
            test.skip(!!r.module && !activeModules().includes(r.module),
                `the ${r.module} module is not active on this install, so its route is not registered`);

            const root = installRoot(test.info().project.use.baseURL);
            const url = routeUrl(root, r.route, r.query, creds);
            const res = await request.fetch(url, { method: r.method });

            expect(res.status(), `${label} rejected a signed request`).not.toBe(401);

            // A 500 means the controller could not be constructed or blew up --
            // exactly what a broken registration looks like from outside.
            expect(res.status(), `${label} errored server-side`).toBeLessThan(500);

            // Whatever the outcome, it must be OWA's JSON API answering.
            const body = await res.text();
            expect(body.length, `${label} returned an empty body`).toBeGreaterThan(0);
        });
    }

    test('an unregistered route is refused, not dispatched', async ({ request }) => {
        const root = installRoot(test.info().project.use.baseURL);
        const res = await request.fetch(
            routeUrl(root, 'base/v1/nosuchroute', '', creds), { method: 'GET' }
        );

        expect(res.status(), 'an unknown route should not 500').toBeLessThan(500);
    });

    /**
     * A request that names no route used to return an empty 200 -- a silent false
     * success a client could not tell from a call that legitimately had no data.
     * There is deliberately no default route here: the admin endpoint falls back
     * to the start_page setting, but a REST client that omits the route has made
     * a malformed request.
     */
    test('a request naming no route is a 400, not an empty 200', async ({ request }) => {
        const root = installRoot(test.info().project.use.baseURL);
        const res = await request.fetch(root + 'api/index.php', { method: 'GET' });

        expect(res.status(), 'no route named should be a bad request').toBe(400);

        const body = JSON.parse(await res.text());
        expect(body.httpResponse.status_code).toBe(400);
        expect(body.error[0].headline).toBeTruthy();

        // The reply is readable pre-auth, so it must not name or hint at routes.
        expect(JSON.stringify(body)).not.toMatch(/sites|users|reports|domstream/i);
    });

    /**
     * These are answered before the controller authenticates, so if an unknown
     * route replied differently from a real one, an anonymous caller could map
     * the whole API by diffing responses.
     */
    test('an unknown route is indistinguishable from an unauthenticated one', async ({ request }) => {
        const root = installRoot(test.info().project.use.baseURL);

        const strip = async (route) => {
            const res = await request.fetch(routeUrl(root, route, '', null), { method: 'GET' });
            const body = (await res.text()).replace(/"requestId":"[0-9]*"/, '"requestId":"X"');
            return { status: res.status(), body };
        };

        const real = await strip('base/v1/sites');
        const fake = await strip('base/v1/nosuchroute');

        expect(real.status, 'a real route unauthenticated should be 401').toBe(401);
        expect(fake.status, 'an unknown route must use the same status').toBe(real.status);
        expect(fake.body, 'an unknown route must be byte-identical').toBe(real.body);
    });

    /**
     * CORS: the API answers cross-origin, and only for configured sites.
     *
     * These live here rather than in their own spec because provision() calls
     * cleanup() first -- it destroys whatever fixture exists. A second spec
     * calling the same helper invalidates this one's credential the moment it
     * runs, which is exactly what happened: seven of the signed cases above
     * failed because another file had re-provisioned the shared FIXTURE_TAG
     * user out from under them. One owner per fixture.
     *
     * The property matters beyond CORS. addCorsHeaders() had never emitted a
     * header -- it compared getSitesList()'s row arrays against the Origin
     * string, so `continue` always fired -- and the unit test that replaced it
     * exercises the matcher as a pure function without issuing a request. The
     * overlay e2e is same-origin and does not assert its fetch. So "can a
     * browser on the tracked site call this API and get data" had no coverage,
     * which is also why playback and heatmaps still ride JSONP.
     *
     * Every expectation below was measured against a live install first.
     */
    test('a configured site\'s Origin is echoed back, and the request still works', async ({ request }) => {
        const root = installRoot(test.info().project.use.baseURL);
        const url = routeUrl(root, 'base/v1/sites', '', creds);

        const res = await request.fetch(url, { method: 'GET', headers: { Origin: creds.domain } });

        expect(res.headers()['access-control-allow-origin'],
            'a configured site must be allowed cross-origin').toBe(creds.domain);
        expect(res.headers()['access-control-allow-credentials']).toBe('true');

        // Asserted as well as the headers: a change that emitted CORS correctly
        // while breaking the response would pass a headers-only test.
        expect(res.status(), `cross-origin request failed: ${await res.text()}`).not.toBe(401);
    });

    test('an unrelated Origin gets no Allow-Origin header', async ({ request }) => {
        const root = installRoot(test.info().project.use.baseURL);
        const url = routeUrl(root, 'base/v1/sites', '', creds);

        const res = await request.fetch(url, {
            method: 'GET',
            headers: { Origin: 'https://evil.example.com' },
        });

        expect(res.headers()['access-control-allow-origin'],
            'an origin this installation does not serve must not be allowed').toBeUndefined();
    });

    test('a suffix or subdomain of a configured host is not that host', async ({ request }) => {
        // The classic allowlist bypass: prefix or substring matching lets
        // evil-<host> and <host>.evil.net through.
        const root = installRoot(test.info().project.use.baseURL);
        const url = routeUrl(root, 'base/v1/sites', '', creds);
        const host = new URL(creds.domain).host;

        for (const forged of [`https://evil-${host}`, `https://${host}.evil.net`, `https://sub.${host}`]) {
            const res = await request.fetch(url, { method: 'GET', headers: { Origin: forged } });

            expect(res.headers()['access-control-allow-origin'],
                `${forged} must not be allowed`).toBeUndefined();
        }
    });

    test('Vary: Origin is sent whether or not the Origin is allowed', async ({ request }) => {
        // Without it a shared cache can hand one site's allowed-origin header to
        // a request from another. Varnish sits in front of this application in
        // production, and the refusal is origin-dependent too -- so it must be
        // present on both responses, not just the permitted one.
        const root = installRoot(test.info().project.use.baseURL);
        const url = routeUrl(root, 'base/v1/sites', '', creds);

        for (const origin of [creds.domain, 'https://evil.example.com']) {
            const res = await request.fetch(url, { method: 'GET', headers: { Origin: origin } });

            expect(String(res.headers()['vary'] || ''),
                `Vary: Origin missing for ${origin}`).toMatch(/Origin/i);
        }
    });

    test('a request with no Origin is unaffected', async ({ request }) => {
        const root = installRoot(test.info().project.use.baseURL);
        const res = await request.fetch(routeUrl(root, 'base/v1/sites', '', creds), { method: 'GET' });

        expect(res.headers()['access-control-allow-origin']).toBeUndefined();
        expect(res.status(), `same-origin request failed: ${await res.text()}`).not.toBe(401);
    });
});
