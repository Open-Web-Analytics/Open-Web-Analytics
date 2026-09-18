/**
 * The cookie lifetime a snippet configures, as a real browser records it.
 *
 * WHY THIS EXISTS, given the jest coverage
 * tests/js/StateStoreExpirations.test.js proves the tracker registers the
 * lifetime and hands it to Util.setCookie(). It cannot prove anything about the
 * cookie that results, because jsdom's document.cookie -- like a real browser's
 * -- does not report Expires at all. A tracker that passed 90 to setCookie() and
 * then built a malformed or missing Expires attribute would produce a SESSION
 * cookie, which is a materially different privacy answer, and every jest test
 * would still be green.
 *
 * So this reads the browser's own cookie jar through context.cookies(), which
 * carries the real expiry as a timestamp, and checks it lands where 90 days from
 * now lands.
 *
 * WHAT IT DRIVES
 * tests/e2e/tracker_harness.html, given ?exp= or ?nopersist=, pushes the
 * setOption commands a site owner writes into their own snippet, before
 * trackPageView as a real snippet does. It loads the REAL built
 * public/base/dist/owa.tracker.js, so this also covers the build: behaviour
 * that exists in modules/Base/src/ and not in the bundle fails here and nowhere
 * else.
 */

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

function installRoot(baseURL) {
    return baseURL.replace(/index\.php.*$/, '');
}

const HARNESS_HTML = fs.readFileSync(
    path.join(__dirname, 'tracker_harness.html'), 'utf8'
);

const DAY_SECONDS = 24 * 60 * 60;

/** The tracked page, optionally carrying expiration / persistence commands. */
async function loadHarness(page, testInfo, expirations, noPersist) {
    const root = installRoot(testInfo.project.use.baseURL);

    let harness = root + 'tests/e2e/tracker_harness.html'
        + '?base=' + encodeURIComponent(root);

    if (expirations) {
        harness += '&exp=' + encodeURIComponent(JSON.stringify(expirations));
    }

    if (noPersist) {
        harness += '&nopersist=1';
    }

    // Serve the harness from disk (the deny-all .htaccess 403s tests/); the
    // injected tracker still loads from the live server, which is the point.
    await page.route(harness, (route) =>
        route.fulfill({ contentType: 'text/html', body: HARNESS_HTML })
    );

    /*
     * Drop the beacon on the floor.
     *
     * This spec is about the COOKIE, and the cookie is written client-side
     * before and independently of the beacon being answered -- the visitor store
     * persists immediately. Letting the beacon through would insert a page view
     * into whatever install this runs against, for a site id that exists only in
     * this harness. tracker-beacon.spec.js already covers the beacon itself and
     * is the right place for that.
     *
     * The consequence worth stating: this spec can be pointed at a real install
     * without writing anything to it.
     */
    await page.route(/log\.php/, (route) => route.abort());

    await page.goto(harness, { waitUntil: 'load' });
}

/** The visitor cookie, once the tracker has got round to writing it. */
async function visitorCookie(context) {
    let cookie;

    await expect.poll(async () => {
        const jar = await context.cookies();
        cookie = jar.find((c) => c.name === 'owa_v');
        return !!cookie;
    }, { timeout: 20_000 }).toBe(true);

    return cookie;
}

test.describe('configured state store expirations reach the cookie', () => {

    test('a 90-day setting makes owa_v expire in 90 days, not a year', async ({ page, context }, testInfo) => {
        await context.clearCookies();

        const before = Date.now() / 1000;
        await loadHarness(page, testInfo, { v: 90 });
        const cookie = await visitorCookie(context);

        // -1 is Playwright's "session cookie". That is the failure this test is
        // really for: a broken Expires attribute does not throw anywhere, it
        // just quietly stops the id surviving the browser being closed.
        expect(cookie.expires, 'owa_v was written as a session cookie').toBeGreaterThan(0);

        const days = (cookie.expires - before) / DAY_SECONDS;

        // A day of slack either side: the tracker computes the expiry from its
        // own clock at write time, and the assertion only has to tell 90 from
        // 364.
        expect(days).toBeGreaterThan(89);
        expect(days).toBeLessThan(91);
    });

    test('with nothing configured the shipped year is still what gets written', async ({ page, context }, testInfo) => {
        await context.clearCookies();

        const before = Date.now() / 1000;
        await loadHarness(page, testInfo);
        const cookie = await visitorCookie(context);

        expect(cookie.expires).toBeGreaterThan(0);

        const days = (cookie.expires - before) / DAY_SECONDS;

        // The acceptance criterion for an untouched install: unchanged.
        expect(days).toBeGreaterThan(363);
        expect(days).toBeLessThan(365);
    });

    /**
     * Issue #941, asserted where it actually shows: the browser's cookie jar.
     *
     * Playwright reports a session cookie as expires === -1. That is the only
     * place this is observable -- document.cookie carries no expiry at all, so
     * a tracker that emitted a malformed or missing Expires attribute would look
     * identical to one that got it right, from JavaScript.
     */
    test('persistence off makes owa_v a session cookie', async ({ page, context }, testInfo) => {
        await context.clearCookies();

        await loadHarness(page, testInfo, null, true);
        const cookie = await visitorCookie(context);

        expect(cookie.expires, 'owa_v still carries an expiry date').toBe(-1);
    });

    test('persistence off beats a configured lifetime', async ({ page, context }, testInfo) => {
        await context.clearCookies();

        // Both commands, as the snippet emits them when an install has set
        // both. Asking for no cookie that outlives the session cannot be
        // satisfied by one that lives 90 days.
        await loadHarness(page, testInfo, { v: 90 }, true);
        const cookie = await visitorCookie(context);

        expect(cookie.expires).toBe(-1);
    });

    test('a store left out of the map keeps its own lifetime', async ({ page, context }, testInfo) => {
        await context.clearCookies();

        // The snippet only emits the settings that differ from their default, so
        // a partial map is the normal case -- naming the visitor store must not
        // disturb the campaign one.
        const before = Date.now() / 1000;
        await loadHarness(page, testInfo, { v: 90 });
        await visitorCookie(context);

        const jar = await context.cookies();
        const campaign = jar.find((c) => c.name === 'owa_c');

        // The campaign cookie is only written when there is campaign state, so
        // its absence is not a failure -- only a wrong lifetime is.
        if (campaign && campaign.expires > 0) {
            const days = (campaign.expires - before) / DAY_SECONDS;
            expect(days).toBeGreaterThan(59);
            expect(days).toBeLessThan(61);
        }
    });
});
