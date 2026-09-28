/**
 * Tracker beacon end-to-end characterization.
 *
 * WHY THIS EXISTS
 * The jest tracker tests (BeaconContract*, Tracker, TrackerTransport) run the
 * SOURCE modules under jsdom and stub the network. Nothing proves the actual
 * BUILT bundle -- public/base/dist/owa.tracker.js, the artifact real sites load --
 * boots in a browser and fires a tracking request. That gap is exactly what the
 * Phase 5 asset move + the __webpack_public_path__ pin could silently break: the
 * bundle 404s, or throws on boot, and no jest test would notice. This spec loads
 * the real built tracker the way js_log_tag.php does and asserts it puts beacons
 * on the wire at <baseUrl>log.php.
 *
 * WHAT IT DOES
 * tests/e2e/tracker_harness.html is a synthetic tracked page: it sets owa_baseUrl,
 * queues setSiteId + trackPageView on owa_cmds, and injects the built tracker. We
 * record every request whose URL contains 'log.php' and assert on the namespaced
 * (owa_*) GET params the tracker assembles. A second test drives a click beacon,
 * proving a second event type transports in a real browser too.
 *
 * The harness document is fulfilled from disk (the deny-all .htaccess 403s tests/)
 * but the tracker script + its log.php beacon fall through to the live OWA server.
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

test.describe('the built tracker fires beacons on the wire', () => {
    let pageErrors;
    let beacons;

    test.beforeEach(async ({ page }, testInfo) => {
        pageErrors = [];
        page.on('pageerror', (err) => pageErrors.push(err.message));

        // Record every log.php beacon (url). The pixel GET fires as soon as the
        // tracker drains owa_cmds, possibly after 'load', so attach BEFORE goto
        // and poll. maxRedirects/response status don't matter -- an Image src GET
        // is fire-and-forget; the REQUEST going out is the contract.
        beacons = [];
        page.on('request', (req) => {
            if (req.url().includes('log.php')) {
                beacons.push(req.url());
            }
        });

        const root = installRoot(testInfo.project.use.baseURL);
        const harness = root + 'tests/e2e/tracker_harness.html'
            + '?base=' + encodeURIComponent(root);

        // Serve the harness from disk (deny-all 403s tests/); the injected tracker
        // and its beacon fall through to the live server, same-origin.
        await page.route(harness, (route) =>
            route.fulfill({ contentType: 'text/html', body: HARNESS_HTML })
        );

        await page.goto(harness, { waitUntil: 'load' });
    });

    test('the page_request beacon is sent to log.php with the site id', async ({ page }) => {
        await expect.poll(() => beacons.length, { timeout: 20_000 }).toBeGreaterThan(0);

        const pageview = beacons.find((u) => /[?&]event_type=page_view/.test(u));
        expect(pageview, 'no page_view beacon was sent').toBeTruthy();
        expect(pageview).toContain('/log.php?');
        expect(pageview).toMatch(/[?&]site_id=e2e-tracker-harness/);
        // Session/visitor identity the server needs to attribute the hit.
        expect(pageview).toMatch(/[?&]visitor_id=\d+/);
        expect(pageview).toMatch(/[?&]session_id=\d+/);
    });

    test('a click drives a click beacon with the clicked element + site id', async ({ page }) => {
        // Enable click tracking on the live tracker instance, then click the target.
        // trackClicks() binds the window click handler that assembles the click event.
        await page.waitForFunction(() => typeof window.OWATracker !== 'undefined', null, { timeout: 20_000 });
        await page.evaluate(() => window.OWATracker.trackClicks());

        const before = beacons.length;
        await page.locator('#tracked-btn').click();

        await expect.poll(() => beacons.length, { timeout: 20_000 }).toBeGreaterThan(before);
        const click = beacons.find((u) => /[?&]event_type=click/.test(u));
        expect(click, 'no click beacon was sent').toBeTruthy();
        // The clicked element's identity + the full state pipeline (site/session)
        // must ride the click beacon -- these appear AFTER target_url in the query
        // string, so their presence also proves the beacon wasn't truncated.
        expect(click).toMatch(/[?&]dom_element_id=tracked-btn/);
        // dom_element_tag is stored lower-cased for consistency.
        expect(click).toMatch(/[?&]dom_element_tag=button/);
        expect(click).toMatch(/[?&]site_id=e2e-tracker-harness/);
        expect(click).toMatch(/[?&]click_x=\d+/);
    });

    test("a clicked link whose href has '#' and '&' still sends a complete beacon", async ({ page }) => {
        // Regression for the value-truncation bug, end to end in a real browser.
        // The link's href ('#sec?a=1&b=2') becomes the click's target_url. With
        // raw GET values the '#' made the browser drop the rest of the beacon URL
        // as a fragment, so nothing after target_url reached the server. Now that
        // values are url-encoded, the whole href rides as one token and the params
        // queued after it survive -- which is exactly what we assert.
        await page.waitForFunction(() => typeof window.OWATracker !== 'undefined', null, { timeout: 20_000 });
        await page.evaluate(() => window.OWATracker.trackClicks());

        const before = beacons.length;
        await page.locator('#tracked-link').click();

        await expect.poll(() => beacons.length, { timeout: 20_000 }).toBeGreaterThan(before);
        const click = beacons.slice(before).find((u) => /[?&]event_type=click/.test(u));
        expect(click, 'no click beacon was sent for the fragment link').toBeTruthy();

        // The browser resolves the href to an absolute URL, so target_url ends in
        // the encoded fragment. Its structural chars must be percent-encoded on the
        // wire (%23 %3F %3D %26), and the literal fragment must NOT survive as an
        // actual URL fragment on the beacon.
        expect(click).toContain(encodeURIComponent('#sec?a=1&b=2'));
        expect(click).not.toContain('#sec?a=1&b=2');
        expect(new URL(click).hash, 'beacon URL was truncated at a fragment').toBe('');
        // Params assembled AFTER target_url still made it onto the wire -- the proof
        // the beacon was not truncated at the href's '#'.
        expect(click).toMatch(/[?&]dom_element_id=tracked-link/);
        expect(click).toMatch(/[?&]site_id=e2e-tracker-harness/);
        expect(click).toMatch(/[?&]click_x=\d+/);
    });

    /*
     * A click on markup INSIDE a link is a click on the link, and a middle-click
     * (auxclick, which is how a link is opened in a new tab) is recorded too.
     */
    test('a click inside an outbound link, and a middle-click on it, report the link', async ({ page }) => {
        await page.waitForFunction(() => typeof window.OWATracker !== 'undefined', null, { timeout: 20_000 });
        await page.evaluate(() => {
            window.OWATracker.trackClicks();

            const a = document.createElement('a');
            a.id = 'outbound-link';
            a.href = 'https://elsewhere.example/out';
            a.innerHTML = '<span id="outbound-inner"><b>leave</b></span>';
            document.body.appendChild(a);

            // Stay on the page: after the tracker's own listener, on window.
            window.addEventListener('click', (e) => e.preventDefault(), false);
            window.addEventListener('auxclick', (e) => e.preventDefault(), false);
        });

        const clicks = () => beacons
            .filter((u) => /[?&]event_type=click/.test(u))
            .map((u) => new URL(u).searchParams)
            .filter((q) => q.get('dom_element_id') === 'outbound-link');

        await page.locator('#outbound-inner b').click();
        await expect.poll(() => clicks().length, { timeout: 20_000 }).toBe(1);

        const click = clicks()[0];
        expect(click.get('dom_element_tag')).toBe('a');
        expect(click.get('target_url')).toBe('https://elsewhere.example/out');
        expect(click.get('is_outbound')).toBe('1');

        await page.locator('#outbound-inner b').click({ button: 'middle' });
        await expect.poll(() => clicks().length, { timeout: 20_000 }).toBe(2);
    });

    test('the page_view beacon carries the screen as WIDTHxHEIGHT', async () => {
        await expect.poll(() => beacons.find((u) => /[?&]event_type=page_view/.test(u)),
            { timeout: 20_000 }).toBeTruthy();

        const pageview = new URL(beacons.find((u) => /[?&]event_type=page_view/.test(u)));

        expect(pageview.searchParams.get('screen_resolution')).toMatch(/^[1-9]\d*x[1-9]\d*$/);
    });

    test('the tracker boots without uncaught page errors', async ({ page }) => {
        await expect.poll(() => beacons.length, { timeout: 20_000 }).toBeGreaterThan(0);
        await page.waitForTimeout(300);
        expect(pageErrors, 'tracker bootstrap threw:\n' + pageErrors.join('\n')).toEqual([]);
    });
});

/**
 * Site search in a real browser: the term is decoded the way a search form
 * submits it, so `Red+Shoes` is the term `Red Shoes`.
 */
test.describe('a results URL raises view_search_results', () => {

    test('with the term as the visitor typed it', async ({ page }, testInfo) => {
        const beacons = [];
        page.on('request', (req) => {
            if (req.url().includes('log.php')) {
                beacons.push(req.url());
            }
        });

        const root = installRoot(testInfo.project.use.baseURL);
        const harness = root + 'tests/e2e/tracker_harness.html'
            + '?base=' + encodeURIComponent(root) + '&search=1&q=Red+Shoes';

        await page.route(harness, (route) =>
            route.fulfill({ contentType: 'text/html', body: HARNESS_HTML })
        );

        await page.goto(harness, { waitUntil: 'load' });

        const searches = () => beacons
            .filter((u) => /[?&]event_type=view_search_results/.test(u))
            .map((u) => new URL(u).searchParams);

        await expect.poll(() => searches().length, { timeout: 20_000 }).toBe(1);
        expect(searches()[0].get('search_term')).toBe('Red Shoes');
    });
});

/**
 * Route changes in a real browser: the page view waits for the route to
 * settle, reads the title then, collapses quick pushes into one, and names the
 * previous route as its referrer.
 */
test.describe('route changes become page views once they settle', () => {

    test('one page view, with the settled title and the previous route as referrer', async ({ page }, testInfo) => {
        const beacons = [];
        page.on('request', (req) => {
            if (req.url().includes('log.php')) {
                beacons.push(req.url());
            }
        });

        const root = installRoot(testInfo.project.use.baseURL);
        const harness = root + 'tests/e2e/tracker_harness.html'
            + '?base=' + encodeURIComponent(root) + '&routes=1';

        await page.route(harness, (route) =>
            route.fulfill({ contentType: 'text/html', body: HARNESS_HTML })
        );

        await page.goto(harness, { waitUntil: 'load' });

        const pageViews = () => beacons
            .filter((u) => /[?&]event_type=page_view/.test(u))
            .map((u) => new URL(u).searchParams);

        await expect.poll(() => pageViews().length, { timeout: 20_000 }).toBe(1);

        // A redirect-style double push, and a title the framework sets after it.
        await page.evaluate(() => {
            history.pushState({}, '', location.pathname + location.search + '&screen=redirecting');
            setTimeout(() => history.pushState({}, '', location.pathname
                + location.search.replace('redirecting', 'settings')), 50);
            setTimeout(() => { document.title = 'Settings'; }, 200);
        });

        await expect.poll(() => pageViews().length, { timeout: 10_000 }).toBe(2);
        await page.waitForTimeout(1000);
        expect(pageViews(), 'the two pushes were one route change').toHaveLength(2);

        const route = pageViews()[1];

        expect(route.get('page_location')).toContain('screen=settings');
        expect(route.get('page_title')).toBe('Settings');
        expect(route.get('HTTP_REFERER')).toBe(harness);
    });
});

