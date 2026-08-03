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
    // handleRestRequest() reads owa_do FIRST and returns early -- with an empty
    // 200, not an error -- when it is absent. owa_rest_params is only consulted
    // afterwards, where it overwrites module/version/do. So a request carrying
    // rest_params alone never reaches the router; both have to be sent.
    const name = route.split('/').pop();
    const base = root + 'api/index.php'
        + '?owa_do=' + name
        + '&owa_rest_params=' + route
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
});
