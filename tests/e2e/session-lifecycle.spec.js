// @ts-check
/**
 * Session lifecycle: does a session actually LAND?
 *
 * tracker-beacon.spec.js proves beacons leave the browser. Nothing until now
 * proved rows arrive -- that one page view yields one session, that a second
 * page view extends it rather than minting another, and (the case this suite
 * exists for) that a lost FIRST beacon does not strand every later hit.
 *
 * The failure being guarded against: the tracker used to write s.sid / s.last_req
 * to the cookie the moment they were derived, before the request carrying them
 * was transmitted. When that first beacon died -- typically cancelled by the
 * browser when the visitor clicked through while the 1x1 pixel was in flight --
 * the cookie asserted a session the server had never been told about. Every later
 * page then read that sid, sent is_new_session UNSET, and the server took
 * sessionHandlers::logSessionUpdate(), which correctly aborts (on a multi-server
 * setup an update can legitimately precede its create) and requeued the event.
 * Nothing reconciled it, so one lost beacon stranded the whole session.
 *
 * Scenario 4 asserts a dangling fact row as the CORRECT current outcome, not a
 * bug: a click fired after a lost page view reaches only clickHandlers and the
 * dimension handlers, never sessionHandlers, so no session is created and nothing
 * is queued. Fixing that needs the server to be able to establish a session
 * thinly from any event type -- deliberately out of scope here. The test pins the
 * behaviour so a future change to handler registration cannot alter what lands in
 * the fact tables silently.
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const HARNESS_SITE_ID = 'e2e-tracker-harness';
const HELPER = path.join(__dirname, 'session_e2e_helper.php');
const HARNESS_HTML = fs.readFileSync(path.join(__dirname, 'tracker_harness.html'), 'utf8');

function installRoot(baseURL) {
    return baseURL.replace(/index\.php.*$/, '');
}

function helper(...args) {
    return JSON.parse(execFileSync('php', [HELPER, ...args], { encoding: 'utf8' }));
}

/**
 * Serve the harness doc from disk at `url`. The injected tracker and its log.php
 * beacon fall through to the php -S server, same-origin.
 */
async function serveHarness(page, url) {
    await page.route(url, (route) =>
        route.fulfill({ contentType: 'text/html', body: HARNESS_HTML })
    );
}

const SELFHOST = process.env.OWA_E2E_SELFHOST === '1';

test.describe('a session lands, extends, and survives a lost first beacon @selfhost-only', () => {

    test.skip(!SELFHOST,
        'Reads and truncates the fact tables; runs only under the self-host e2e runner (OWA_E2E_SELFHOST=1).');

    test.beforeEach(() => {
        helper('reset', `site=${HARNESS_SITE_ID}`);
    });

    /** Wait until log.php has been hit for the given event type. */
    async function awaitBeacon(beacons, eventType) {
        await expect
            .poll(() => beacons.filter((u) => u.includes(eventType)).length, { timeout: 20_000 })
            .toBeGreaterThan(0);
    }

    /** Poll the DB, since the beacon is answered before the write completes. */
    async function awaitSessions(count) {
        await expect
            .poll(() => helper('session-state', `site=${HARNESS_SITE_ID}`).session_count,
                  { timeout: 20_000 })
            .toBe(count);
        return helper('session-state', `site=${HARNESS_SITE_ID}`);
    }

    test('1 - a single page view yields exactly one session', async ({ page }) => {
        const beacons = [];
        page.on('request', (r) => { if (r.url().includes('log.php')) beacons.push(r.url()); });

        const root = installRoot(test.info().project.use.baseURL);
        const url = root + 'tests/e2e/tracker_harness.html?base=' + encodeURIComponent(root);
        await serveHarness(page, url);
        await page.goto(url, { waitUntil: 'load' });
        await awaitBeacon(beacons, 'owa_event_type=base.page_request');

        const state = await awaitSessions(1);
        expect(state.request_count).toBe(1);
        expect(Number(state.sessions[0].num_pageviews)).toBe(1);
        expect(Number(state.sessions[0].is_bounce)).toBe(1);
        expect(state.queue_depth).toBe(0);
        expect(state.dangling_total).toBe(0);
    });

    test('2 - a second page view extends the session rather than minting another', async ({ page }) => {
        const beacons = [];
        page.on('request', (r) => { if (r.url().includes('log.php')) beacons.push(r.url()); });

        const root = installRoot(test.info().project.use.baseURL);
        const a = root + 'tests/e2e/tracker_harness.html?base=' + encodeURIComponent(root) + '&p=a';
        const b = root + 'tests/e2e/tracker_harness.html?base=' + encodeURIComponent(root) + '&p=b';
        await serveHarness(page, a);
        await serveHarness(page, b);

        await page.goto(a, { waitUntil: 'load' });
        await awaitBeacon(beacons, 'owa_event_type=base.page_request');
        await awaitSessions(1);

        /*
         * logSessionUpdate() only applies an update when the event timestamp is
         * strictly GREATER than the session's stored last_req -- the idempotency
         * guard that makes out-of-order arrivals safe on a multi-server setup.
         * OWA timestamps are whole seconds, so two page views inside the same
         * second are correctly suppressed. Space them.
         */
        await page.waitForTimeout(1100);

        const beforeB = beacons.length;
        await page.goto(b, { waitUntil: 'load' });
        // Wait for B's own beacon to leave before inspecting the DB, otherwise
        // the poll races the request rather than the write.
        await expect.poll(() => beacons.length, { timeout: 20_000 }).toBeGreaterThan(beforeB);

        // Poll num_pageviews, not request_count: the request row lands first and
        // sessionHandlers updates the session on a LATER write.
        await expect.poll(
            () => {
                const st = helper('session-state', `site=${HARNESS_SITE_ID}`);
                // Surface the shape on failure -- a second session here would mean
                // B minted its own rather than continuing A's.
                return { sessions: st.session_count,
                         pageviews: st.sessions[0] ? Number(st.sessions[0].num_pageviews) : 0 };
            },
            { timeout: 20_000 }
        ).toEqual({ sessions: 1, pageviews: 2 });

        const state = helper('session-state', `site=${HARNESS_SITE_ID}`);
        // The regression this guards: if deferral ever discarded sid/last_req on a
        // SUCCESSFUL send, every page would start a new session.
        expect(state.session_count).toBe(1);
        expect(Number(state.sessions[0].num_pageviews)).toBe(2);
        expect(Number(state.sessions[0].is_bounce)).toBe(0);
        expect(state.queue_depth).toBe(0);
        expect(state.dangling_total).toBe(0);

        // The second hit continues the session -- it must NOT re-declare a new one.
        const second = beacons.filter((u) => u.includes('owa_event_type=base.page_request'))[1];
        expect(second).toBeTruthy();
        expect(second).not.toContain('owa_is_new_session');
    });

    test('3 - a lost first page view does not strand the session', async ({ page }) => {
        const beacons = [];
        page.on('request', (r) => { if (r.url().includes('log.php')) beacons.push(r.url()); });

        const root = installRoot(test.info().project.use.baseURL);
        // OWA's default campaignKeys are owa_* , not utm_* (utm_ requires an
        // explicit remap via setCampaignSourceKey and friends).
        const a = root + 'tests/e2e/tracker_harness.html?base=' + encodeURIComponent(root)
                + '&p=a&owa_campaign=spring&owa_source=newsletter&owa_medium=email';
        const b = root + 'tests/e2e/tracker_harness.html?base=' + encodeURIComponent(root) + '&p=b';
        await serveHarness(page, a);
        await serveHarness(page, b);

        /*
         * Force the pixel transport for this scenario.
         *
         * route.abort() kills the request at the NETWORK layer, but
         * navigator.sendBeacon() has already returned true by then -- it reports
         * "the browser accepted this for delivery", not "it arrived". So under
         * sendBeacon an aborted request still commits session identity, and the
         * loss is undetectable client-side. That is a real limitation of the
         * transport, not of this test.
         *
         * The pixel path is the one where failure IS observable (onerror), so it
         * is what this asserts. Removing sendBeacon also mirrors the browsers
         * that fall back to it for real.
         */
        await page.addInitScript(() => {
            try { delete Object.getPrototypeOf(navigator).sendBeacon; } catch (e) { /* ignore */ }
            try { delete navigator.sendBeacon; } catch (e) { /* ignore */ }
            Object.defineProperty(navigator, 'sendBeacon', {
                configurable: true, get() { return undefined; },
            });
        });

        // --- page A: the beacon never reaches the server --------------------
        await page.route('**/log.php*', (route) => route.abort());
        await page.goto(a, { waitUntil: 'load' });
        await page.waitForTimeout(1000);

        let state = helper('session-state', `site=${HARNESS_SITE_ID}`);
        expect(state.session_count, 'page A must leave nothing behind').toBe(0);
        expect(state.request_count).toBe(0);
        expect(state.queue_depth).toBe(0);

        // --- page B: delivery restored --------------------------------------
        await page.unroute('**/log.php*');
        await page.goto(b, { waitUntil: 'load' });
        await awaitBeacon(beacons, 'owa_event_type=base.page_request');

        state = await awaitSessions(1);

        // Only B's view exists -- A's was genuinely lost, and we do not invent it.
        expect(state.request_count).toBe(1);
        // The whole point: no stranded update, so nothing was requeued.
        expect(state.queue_depth, 'a stranded session requeues page_request_logged').toBe(0);
        expect(state.dangling_total).toBe(0);

        // B had to declare a NEW session, because A's identity was never persisted.
        const pageviews = beacons.filter((u) => u.includes('owa_event_type=base.page_request'));
        expect(pageviews[pageviews.length - 1]).toContain('owa_is_new_session');

        // Arrival facts captured on A survive: they are observable only on the
        // landing hit and are unrecoverable if dropped. B's URL carries no utm_*.
        const attribs = state.sessions[0].latest_attributions;
        expect(attribs, 'campaign attribution from page A was lost').toBeTruthy();
        expect(JSON.stringify(attribs)).toContain('spring');
        expect(JSON.stringify(attribs)).toContain('newsletter');
    });

    test('4 - a click after a lost page view still dangles (accepted, pending server-side fix)', async ({ page }) => {
        const beacons = [];
        page.on('request', (r) => { if (r.url().includes('log.php')) beacons.push(r.url()); });

        const root = installRoot(test.info().project.use.baseURL);
        const a = root + 'tests/e2e/tracker_harness.html?base=' + encodeURIComponent(root) + '&p=a';
        await serveHarness(page, a);

        // Same reason as scenario 3: under sendBeacon an aborted request still
        // reports success, so the pixel path is the one where a lost hit is
        // observable at all.
        await page.addInitScript(() => {
            Object.defineProperty(navigator, 'sendBeacon', {
                configurable: true, get() { return undefined; },
            });
        });

        await page.route('**/log.php*', (route) => route.abort());
        await page.goto(a, { waitUntil: 'load' });
        await page.waitForTimeout(500);

        // Delivery comes back, then the visitor clicks WITHOUT leaving the page.
        await page.unroute('**/log.php*');
        await page.waitForFunction(() => typeof window.OWATracker !== 'undefined',
            null, { timeout: 20_000 });
        await page.evaluate(() => window.OWATracker.trackClicks());
        await page.locator('#tracked-btn').click();
        await awaitBeacon(beacons, 'owa_event_type=dom.click');

        await page.waitForTimeout(1500);
        const state = helper('session-state', `site=${HARNESS_SITE_ID}`);

        /*
         * The click carries the in-memory sid and is_new_session, but dom.click is
         * registered to clickHandlers + the dimension handlers only -- never
         * sessionHandlers -- so no session is created and every handler succeeds.
         * The row lands referencing a session that does not exist, and nothing is
         * queued to flag it.
         *
         * This is the CURRENT, accepted outcome. Closing it requires the server to
         * establish a session thinly from any event type, which is a separate
         * change. Asserted here so that altering handler registration or the
         * globals lifecycle cannot change what reaches the fact tables silently.
         */
        expect(state.session_count).toBe(0);
        expect(state.dangling_total).toBeGreaterThan(0);
        expect(state.queue_depth, 'the dangling click is invisible to the queue').toBe(0);
    });
});
