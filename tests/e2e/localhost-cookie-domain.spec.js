/**
 * Localhost / non-FQDN cookie-domain characterization.
 *
 * WHY THIS EXISTS
 * There is a long-standing, repeatedly-reported belief that OWA does not track
 * under `localhost` (or a bare hostname / IP) and "requires a FQDN". The usual
 * theory: the tracker resolves its cookie domain from document.domain and writes
 * state cookies with `; domain=.localhost`, which a browser rejects per RFC 6265
 * (a Domain cookie needs an embedded dot), so the visitor/session cookies never
 * persist.
 *
 * This spec was written to REPRODUCE that failure in a real browser -- the one
 * thing jsdom cannot judge, since its cookie jar is more lenient than a browser's
 * and it reports document.domain as undefined on localhost. The result is the
 * opposite of the theory: modern Chromium ACCEPTS `; domain=.localhost` (and
 * `; domain=.127.0.0.1`), storing them as host-scoped cookies. The tracker's
 * client-side state therefore DOES persist on localhost, and a visitor id stays
 * stable across page loads. So this stands as a characterization/guard test: if a
 * future change (or a stricter browser) ever does break cookie persistence on a
 * single-label host, this test starts failing and tells us.
 *
 * (Server side, settings.php::setCookieDomain deliberately refuses to set a
 * `.localhost` cookie domain -- the `>= 2 dots` FQDN gate -- and falls back to a
 * host-only cookie, which is the correct behavior for localhost. That gate was
 * added in 2011 specifically to stop OWA emitting a broken `.localhost` server
 * cookie. It is protective, not the cause of a break.)
 *
 * WHAT IT DOES
 * localhost_cookie_harness.html boots the built tracker and queues a
 * trackPageView. trackEvent()'s manageState() writes the 'v'/'s' state cookies
 * CLIENT-SIDE before any beacon leaves, so no server is needed: the document and
 * the tracker script are both fulfilled from disk at an http://localhost origin,
 * and we read context.cookies() after the tracker boots.
 */

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

// A real single-label origin. Chromium enforces RFC 6265 cookie-domain rules
// here exactly as it would for a developer running their site on localhost.
const LOCALHOST_ORIGIN = 'http://localhost/';
const HARNESS_URL = LOCALHOST_ORIGIN + 'tests/e2e/localhost_cookie_harness.html';
const TRACKER_URL = LOCALHOST_ORIGIN + 'public/base/dist/owa.tracker.js';

const HARNESS_HTML = fs.readFileSync(
    path.join(__dirname, 'localhost_cookie_harness.html'), 'utf8'
);
const TRACKER_JS = fs.readFileSync(
    path.join(__dirname, '..', '..', 'public', 'base', 'dist', 'owa.tracker.js'), 'utf8'
);

test.describe('tracker state cookies on a single-label host (localhost)', () => {

    test.beforeEach(async ({ context }) => {
        // Fulfill the document and the built tracker from disk so the whole page
        // runs at http://localhost with no OWA server. The log.php beacon has
        // nowhere to go -> empty 200 so the pixel GET doesn't hang; the cookie
        // write happens first and client-side.
        await context.route(HARNESS_URL + '**', (route) =>
            route.fulfill({ contentType: 'text/html', body: HARNESS_HTML })
        );
        await context.route(TRACKER_URL, (route) =>
            route.fulfill({ contentType: 'application/javascript', body: TRACKER_JS })
        );
        await context.route('**/log.php*', (route) =>
            route.fulfill({ status: 200, contentType: 'image/gif', body: '' })
        );
    });

    test('the visitor and session cookies persist on localhost', async ({ context, page }) => {
        await page.goto(HARNESS_URL + '?base=' + encodeURIComponent(LOCALHOST_ORIGIN),
            { waitUntil: 'load' });

        await page.waitForFunction(
            () => typeof window.OWATracker !== 'undefined',
            null, { timeout: 20_000 }
        );

        // The real browser cookie jar must hold the namespaced state cookies. If a
        // browser ever rejected the `.localhost` Domain cookie (the old theory),
        // they'd be absent and this would time out.
        await expect.poll(async () => {
            const cookies = await context.cookies(LOCALHOST_ORIGIN);
            return cookies.map((c) => c.name);
        }, { timeout: 10_000 }).toEqual(
            expect.arrayContaining(['owa_v', 'owa_s'])
        );

        const cookies = await context.cookies(LOCALHOST_ORIGIN);
        const visitor = cookies.find((c) => c.name === 'owa_v');
        const session = cookies.find((c) => c.name === 'owa_s');
        expect(visitor, 'visitor cookie owa_v was not persisted on localhost').toBeTruthy();
        expect(session, 'session cookie owa_s was not persisted on localhost').toBeTruthy();
    });

    test('the visitor id is stable across page loads on localhost', async ({ context, page }) => {
        const beacons = [];
        page.on('request', (req) => {
            if (req.url().includes('log.php')) beacons.push(req.url());
        });

        const url = HARNESS_URL + '?base=' + encodeURIComponent(LOCALHOST_ORIGIN);
        for (let i = 0; i < 2; i++) {
            await page.goto(url, { waitUntil: 'load' });
            await page.waitForFunction(
                () => typeof window.OWATracker !== 'undefined',
                null, { timeout: 20_000 }
            );
            await expect.poll(() => beacons.length).toBeGreaterThan(i);
        }

        const vids = beacons
            .map((u) => (u.match(/owa_visitor_id=(\d+)/) || [])[1])
            .filter(Boolean);
        // Two beacons, both carrying the SAME visitor id -> the cookie written on
        // load 1 was read back on load 2. A returning visitor is recognized, not
        // counted fresh each time.
        expect(vids.length).toBeGreaterThanOrEqual(2);
        expect(vids[vids.length - 1]).toBe(vids[0]);
    });
});
