/**
 * HTTP-level web-access hardening safety net (Phase 5, variant A).
 *
 * The repo root IS the Apache docroot, so today the WHOLE tree is web-served: not
 * just the public entry points and built assets, but PHP source (owa_db.php,
 * classes/*.php), raw templates (*.tpl), owa-config.php (DB credentials), and build
 * metadata (webpack.config.js, package.json, composer.json). This spec pins the
 * intended access policy:
 *
 *   - PUBLIC set  -> must be reachable (200/301/302). The public PHP endpoints, the
 *     built JS/CSS assets (now including the whole tracker family under public/), AND
 *     the two legacy tracker ENTRY points that old embeds hardcode: the previous
 *     modules/base/dist/owa.tracker.js and the ancient
 *     modules/base/js/owa.tracker-combined-min.js. Neither has a physical file anymore --
 *     both are 301-redirected to public/base/dist/owa.tracker.js by .htaccess (the
 *     rewrites fire unconditionally, no -f test), so old embed snippets keep resolving.
 *   - DENIED set  -> must NOT be served as source (expect 403, or 404 if the allow
 *     rule makes it vanish). Source PHP, templates, config, data, build metadata.
 *
 * This is RED before the deny-all .htaccess allowlist lands (the DENIED set returns
 * 200 today) and GREEN after -- write-the-test-first, watch it flip. It is the whole
 * point of the phase, so it guards the policy independently of how it's implemented
 * (Apache .htaccess here; an nginx equivalent would satisfy the same contract).
 *
 * Target: derived from OWA_E2E_BASE_URL (or the default) by stripping the trailing
 * index.php -- i.e. the install ROOT that public_url points at.
 */
const { test, expect } = require('@playwright/test');

// The install root URL (public_url): the base URL with any trailing entry-point
// file removed. e2e base URL is '.../owa/index.php' -> root '.../owa/'.
const BASE_URL =
    process.env.OWA_E2E_BASE_URL || 'https://test.openwebanalytics.com/owa/index.php';
const ROOT = BASE_URL.replace(/\/[^/]*\.php$/, '/').replace(/\/?$/, '/');

// Paths that MUST remain publicly fetchable. A 2xx or a 3xx redirect both count as
// "served" (index.php 302s to the login flow; the legacy tracker path 301s to dist).
const PUBLIC_PATHS = [
    'index.php',
    'log.php',
    'api/index.php',
    'install.php',
    'queue.php',
    'blank.php',
    // Built, intentionally-public assets, ALL now under the public/ asset tree (moved
    // out of the source tree so the deny-all can allow public/** wholesale) -- the
    // canonical tracker, the reporting JS, and the combined CSS.
    'public/base/dist/owa.tracker.js',
    'public/base/dist/owa.reporting-combined-min.js',
    'public/base/css/owa.reporting-css-combined.css',
    // Images under public/ -- both the copied CSS url() deps AND the server-side
    // makeImageLink images (report icons/logos) now resolve here via images_url.
    'public/base/i/funnel_flow.png',
    'public/base/i/user_icon_small.gif',
    // The two legacy tracker ENTRY points stay reachable -- neither has a physical file
    // anymore; .htaccess 301s both to public/base/dist/owa.tracker.js. Old embed codes
    // in the wild point at one or the other, so both must keep resolving (the redirect
    // is the compat, not a duplicate file). The moved tracker pins its own publicPath to
    // public/, so its async chunks are never requested from these old locations.
    'modules/base/dist/owa.tracker.js',
    'modules/base/js/owa.tracker-combined-min.js',
];

// Paths that MUST NOT be served as source once the allowlist is in place. These all
// return 200 today (the exposure this phase closes).
const DENIED_PATHS = [
    'owa-config.php',                                 // DB credentials
    'owa_db.php',                                     // core class source
    'owa_coreAPI.php',                                // core class source
    'modules/base/classes/trackingEventHelpers.php',  // module class source
    'modules/base/templates/report.php',              // raw template
    // Module-tree images are no longer served -- makeImageLink now resolves against
    // public/ (images_url = assets_url), so the source-tree copy must be denied like
    // the rest of modules/. (The public/base/i/ copy above is what's actually served.)
    'modules/base/i/user_icon_small.gif',
    // The tracker's async chunks used to live (and be allowlisted) at modules/base/dist/.
    // The tracker moved to public/ and pins its publicPath there, so it never requests
    // these from the module tree -- the allow was dropped and they must now be denied.
    // (Only modules/base/dist/owa.tracker.js stays reachable there, as a 301 entry point.)
    'modules/base/dist/owa.vendors.js',
    'webpack.config.js',                              // build metadata
    'package.json',                                   // build metadata
    'composer.json',                                  // build metadata
    // Bare directory URLs. The install root is allowed (see the root tests below), but
    // every OTHER directory must stay denied. Most of them carry an inert index.php
    // anti-listing stub, so a naive "allow any directory containing an index.php" root
    // fix would serve these as blank 200s; and a root fix that leaned on mod_dir could
    // surface a mod_autoindex listing here instead (Options Indexes is the distro
    // default for a docroot). Both are guarded by asserting these stay 403/404.
    'modules/',
    'conf/',
];

// The site's WAF/rate-limiter allowlists the "Open Web Analytics" UA token (see
// playwright.config.js) -- send it here too so these bare HTTP probes aren't 403'd
// for the WRONG reason (rate limit) and mistaken for the policy working.
const UA = 'Playwright access-hardening probe Open Web Analytics';

/** GET without following redirects; return the raw status code. */
async function statusOf(request, url) {
    const res = await request.get(url, {
        headers: { 'User-Agent': UA },
        maxRedirects: 0,
        failOnStatusCode: false,
    });
    return res.status();
}

// Tagged @server-config: this suite asserts REAL web-server (.htaccess) deny/allow
// behavior. The self-hosted runner (playwright.selfhost.config.js) serves the repo
// with `php -S`, which serves every file itself and honors no .htaccess, so it
// grep-INVERTS this tag and skips the suite. It only runs against a real Apache/nginx
// (the live-server runner, playwright.config.js). See that config's header.
test.describe('web-access hardening policy @server-config', () => {

    for (const p of PUBLIC_PATHS) {
        test(`public: ${p} is served`, async ({ request }) => {
            const code = await statusOf(request, ROOT + p);

            // This suite is about the web server's deny rules, so "served"
            // means the request reached PHP -- 2xx, a redirect (login flow /
            // legacy-tracker 301), or an application-level rejection. It does
            // NOT mean 2xx: api/index.php answers a request that names no
            // route with a well-formed 400, which is the endpoint working, and
            // asserting `< 400` failed it for doing so.
            expect([403, 404], `${p} should be publicly reachable, not blocked`).not.toContain(code);

            // Reachable but broken is a different failure, and still one.
            expect(code, `${p} should not be a server error`).toBeLessThan(500);
        });
    }

    for (const p of DENIED_PATHS) {
        test(`denied: ${p} is not served as source`, async ({ request }) => {
            const code = await statusOf(request, ROOT + p);
            // Denied = 403 (forbidden) or 404 (allow rule makes it vanish). A 2xx here
            // means the source is being served -- the hole this phase closes.
            expect([403, 404], `${p} should be denied, got ${code}`).toContain(code);
        });
    }

    // The install ROOT itself -- the first URL anyone visits after unpacking, and the one
    // this suite used to miss completely: ROOT was only ever used as a PREFIX for the
    // paths above, never requested on its own. That blind spot is how OWA 1.9.0 shipped
    // a 403 on every fresh install (issue #950) -- authorization runs in the auth phase,
    // but mod_dir does not map a bare directory URL onto DirectoryIndex until the handler
    // phase, so deny-all rejected the request as a DIRECTORY before index.php was ever
    // considered.
    test('public: the install root is served', async ({ request }) => {
        const code = await statusOf(request, ROOT);
        expect(code, 'the install root should be publicly reachable').toBeLessThan(400);
    });

    // The same directory without its trailing slash. Also 403'd before the fix, and for
    // the same reason: mod_dir's canonical "add the slash" 301 is a handler-phase
    // response, so it never ran either.
    test('public: the install root is served without a trailing slash', async ({ request }) => {
        const code = await statusOf(request, ROOT.replace(/\/$/, ''));
        expect(code, 'the slashless install root should be publicly reachable').toBeLessThan(400);
    });

    // The root must resolve to the index.php ENTRY POINT, not to a directory listing.
    // Distinguishes the fix working from mod_autoindex papering over it: index.php with
    // no session redirects into the login flow, so a 2xx listing would fail here.
    test('public: the install root resolves to the index.php entry point', async ({ request }) => {
        const res = await request.get(ROOT, {
            headers: { 'User-Agent': UA },
            maxRedirects: 0,
            failOnStatusCode: false,
        });
        expect(res.status(), 'root should redirect into the app, not render a listing').toBe(302);
        expect(res.headers()['location'] || '', 'root should redirect to an OWA entry point')
            // 'do=', not 'owa_do=': the redirect is built by OWA, and OWA's own
            // URLs are un-namespaced now. The prefixed spelling is still ACCEPTED
            // on the way in, which is why inbound test URLs elsewhere still use it.
            .toMatch(/index\.php\?(owa_)?do=/);
    });
});
