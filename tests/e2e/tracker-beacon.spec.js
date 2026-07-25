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
 * the real built tracker the way js_log_tag.tpl does and asserts it puts beacons
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

        const pageview = beacons.find((u) => u.includes('owa_event_type=base.page_request'));
        expect(pageview, 'no base.page_request beacon was sent').toBeTruthy();
        expect(pageview).toContain('/log.php?');
        expect(pageview).toContain('owa_site_id=e2e-tracker-harness');
        // Session/visitor identity the server needs to attribute the hit.
        expect(pageview).toMatch(/owa_visitor_id=\d+/);
        expect(pageview).toMatch(/owa_session_id=\d+/);
    });

    test('a click drives a dom.click beacon with the clicked element + site id', async ({ page }) => {
        // Enable click tracking on the live tracker instance, then click the target.
        // trackClicks() binds the window click handler that assembles dom.click.
        await page.waitForFunction(() => typeof window.OWATracker !== 'undefined', null, { timeout: 20_000 });
        await page.evaluate(() => window.OWATracker.trackClicks());

        const before = beacons.length;
        await page.locator('#tracked-btn').click();

        await expect.poll(() => beacons.length, { timeout: 20_000 }).toBeGreaterThan(before);
        const click = beacons.find((u) => u.includes('owa_event_type=dom.click'));
        expect(click, 'no dom.click beacon was sent').toBeTruthy();
        // The clicked element's identity + the full state pipeline (site/session)
        // must ride the click beacon -- these appear AFTER target_url in the query
        // string, so their presence also proves the beacon wasn't truncated.
        expect(click).toContain('owa_dom_element_id=tracked-btn');
        expect(click).toContain('owa_dom_element_tag=BUTTON');
        expect(click).toContain('owa_site_id=e2e-tracker-harness');
        expect(click).toMatch(/owa_click_x=\d+/);
    });

    test('the tracker boots without uncaught page errors', async ({ page }) => {
        await expect.poll(() => beacons.length, { timeout: 20_000 }).toBeGreaterThan(0);
        await page.waitForTimeout(300);
        expect(pageErrors, 'tracker bootstrap threw:\n' + pageErrors.join('\n')).toEqual([]);
    });
});
