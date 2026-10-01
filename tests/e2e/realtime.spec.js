const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * The realtime screen shows an event within seconds of its beacon.
 *
 * A real page view goes through the built tracker and log.php for the fixture
 * site -- the tracker harness, served with the fixture's site id -- and the
 * screen, which reads raw, must then count its visitor and list its page. No
 * cube is built in between, and none is needed: that is the difference
 * between this screen and a report.
 */

function installRoot(baseURL) {
    return baseURL.replace(/index\.php.*$/, '');
}

const HARNESS_HTML = fs.readFileSync(path.join(__dirname, 'tracker_harness.html'), 'utf8')
    .replace("'e2e-tracker-harness'", `'${FIXTURE.siteId}'`);

async function sendPageView(page, root) {
    const harness = root + 'tests/e2e/tracker_harness.html?base=' + encodeURIComponent(root);
    const beacons = [];

    page.on('request', (r) => { if (r.url().includes('log.php')) beacons.push(r.url()); });

    await page.route(harness, (route) => route.fulfill({ contentType: 'text/html', body: HARNESS_HTML }));
    await page.goto(harness, { waitUntil: 'load' });

    await expect.poll(() => beacons.some((u) => /[?&]e_t=page_view/.test(u)), { timeout: 20_000 })
        .toBe(true);

    // Leaving would abort an in-flight beacon under a route (see
    // reference_playwright_route_aborts_unload_beacons); give it time to land.
    await page.waitForTimeout(1500);
    await page.unrouteAll({ behavior: 'wait' });
}

test.describe('the realtime screen', () => {

    test('counts a visitor whose page view just arrived, and lists the page', async ({ page }, testInfo) => {
        const root = installRoot(testInfo.project.use.baseURL);

        await sendPageView(page, root);

        await adminLogin(page);
        await page.goto(`?owa_do=base.report&owa_reportId=realtime&owa_siteId=${FIXTURE.siteId}`,
            { waitUntil: 'networkidle' });

        await expect(page.locator('#owa_realtime')).toBeVisible();

        // No period picker: the window is always the last thirty minutes.
        await expect(page.locator('#owa_timePeriodControl')).toHaveCount(0);

        await expect.poll(async () => Number((await page.locator('.owa_realtimeUsers30').textContent()).replace(/\D/g, '')),
            { timeout: 20_000 }).toBeGreaterThan(0);

        await expect(page.locator('.owa_realtimeTable[data-card="events"] tbody'))
            .toContainText('page_view');
        await expect(page.locator('.owa_realtimeTable[data-card="pages"] tbody tr[title*="tracker_harness.html"]'))
            .toHaveCount(1);

        // Thirty minute bars, the last carrying this minute's visitor.
        await expect(page.locator('.owa_realtimeBar')).toHaveCount(30);
    });

    test('draws the country map from this install, not a map service', async ({ page }, testInfo) => {
        const external = [];

        page.on('request', (r) => {
            const url = new URL(r.url());
            if (url.origin !== new URL(testInfo.project.use.baseURL).origin) {
                external.push(r.url());
            }
        });

        await adminLogin(page);
        await page.goto(`?owa_do=base.report&owa_reportId=realtime&owa_siteId=${FIXTURE.siteId}`,
            { waitUntil: 'networkidle' });

        await expect.poll(() => page.locator('.owa_realtimeMap svg g[data-cc]').count(), { timeout: 20_000 })
            .toBeGreaterThan(150);

        expect(external, 'the screen fetched from another origin').toEqual([]);
    });

    test('opens one visitor’s events from the recent list, and comes back', async ({ page }, testInfo) => {
        const root = installRoot(testInfo.project.use.baseURL);

        await sendPageView(page, root);

        await adminLogin(page);
        await page.goto(`?owa_do=base.report&owa_reportId=realtime&owa_siteId=${FIXTURE.siteId}`,
            { waitUntil: 'networkidle' });

        const first = page.locator('.owa_realtimeRecent li[data-visitor]').first();
        await expect(first).toBeVisible({ timeout: 20_000 });

        const visitor = await first.getAttribute('data-visitor');
        await first.click();

        await expect(page.locator('.owa_realtimeVisitor')).toBeVisible();
        await expect(page.locator('.owa_realtimeVisitorId')).toHaveText(`Visitor ${visitor}`);
        // Newest first, so the unload's user_engagement can lead; the page view is there.
        await expect(page.locator('.owa_realtimeVisitorEvents')).toContainText('page_view');

        await page.locator('.owa_realtimeBack').click();
        await expect(page.locator('.owa_realtimeSummary')).toBeVisible();
    });

    test('is in the report navigation, second after the dashboard', async ({ page }) => {
        await adminLogin(page);
        await page.goto(`?owa_do=base.report&owa_reportId=dashboard&owa_siteId=${FIXTURE.siteId}`,
            { waitUntil: 'networkidle' });

        const labels = (await page.locator('#owa_reportNavPanel a.owa_admin_nav_topmenu_item_text').allTextContents())
            .map((t) => t.trim()).filter(Boolean);

        expect(labels.indexOf('Realtime'), labels.join(' | ')).toBe(labels.indexOf('Dashboard') + 1);
    });
});
