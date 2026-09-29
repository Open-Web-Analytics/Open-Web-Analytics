// @ts-check
/**
 * A recording LANDS: the recorder compiled into the tracker bundle sends its
 * chunks through log.php, and they are stored and played back.
 *
 * Unit tests cover the recorder, the chunk's validation and the store, each
 * on its own. None of them sends a chunk through log.php, which admits only
 * registered properties -- so a chunk could be refused there while every one
 * of them passed. This drives the real recorder in a browser and reads what
 * the server stored.
 *
 * Key presses are recorded as THAT a key was pressed in a field, never which
 * key, so the typed text must appear nowhere in what was stored.
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

const HARNESS_SITE_ID = 'e2e-tracker-harness';
const HELPER = path.join(__dirname, 'session_e2e_helper.php');
const HARNESS_HTML = fs.readFileSync(path.join(__dirname, 'tracker_harness.html'), 'utf8');
const TYPED = 'hunter';

function installRoot(baseURL) {
    return baseURL.replace(/index\.php.*$/, '');
}

function helper(...args) {
    return JSON.parse(execFileSync('php', [HELPER, ...args], { encoding: 'utf8' }));
}

const SELFHOST = process.env.OWA_E2E_SELFHOST === '1';

test.describe('a recording lands @selfhost-only', () => {

    test.skip(!SELFHOST,
        'Reads and truncates the recording tables; runs only under the self-host e2e runner (OWA_E2E_SELFHOST=1).');

    test.beforeEach(() => {
        helper('reset', `site=${HARNESS_SITE_ID}`);
    });

    test('pointer, clicks and key presses are stored and played back, keys are not', async ({ page }) => {
        const root = installRoot(test.info().project.use.baseURL);
        const url = root + 'tests/e2e/tracker_harness.html?base=' + encodeURIComponent(root) + '&record=1';

        const beacons = [];
        page.on('request', (r) => { if (r.url().includes('log.php')) beacons.push(r.url()); });

        await page.route(url, (route) => route.fulfill({ contentType: 'text/html', body: HARNESS_HTML }));
        await page.goto(url, { waitUntil: 'load' });

        // The tracker is running once its page view has left.
        await expect.poll(() => beacons.filter((u) => /[?&]event_type=page_view/.test(u)).length,
            { timeout: 20_000 }).toBeGreaterThan(0);

        for (const [x, y] of [[40, 40], [120, 80], [200, 160]]) {
            await page.mouse.move(x, y);
            await page.waitForTimeout(150);
        }

        await page.click('#tracked-btn');
        await page.click('#typed-field');
        await page.keyboard.type(TYPED, { delay: 30 });

        // Waited for on the PAGE, through the recorder's periodic flush
        // (domstreamFlushInterval, 3s). Asserting it after leaving made the test
        // depend on the pagehide flush's beacon alone, which did not arrive in
        // two CI runs of nine -- a question about unload delivery, not about
        // whether a recording lands.
        await expect.poll(() => {
            const recs = helper('recordings', `site=${HARNESS_SITE_ID}`).recordings;
            return recs.length ? recs[0].chunks.reduce((n, c) => n + Number(c.keypress_count), 0) : 0;
        }, { timeout: 20_000 }).toBe(TYPED.length);

        await page.goto('about:blank');

        const { recordings } = helper('recordings', `site=${HARNESS_SITE_ID}`);

        expect(recordings, 'one page load is one recording').toHaveLength(1);

        const [recording] = recordings;
        const clicks = recording.chunks.reduce((n, c) => n + Number(c.click_count), 0);

        expect(clicks, 'the button and the field were clicked').toBe(2);
        expect(Number(recording.chunks[0].page_view_seq), 'linked to its page view').toBeGreaterThan(0);
        expect(Number(recording.chunks[0].viewport_w)).toBeGreaterThan(0);

        const types = recording.samples.map((s) => s[1]);
        expect(types).toContain('m');
        expect(types).toContain('c');

        const keys = recording.samples.filter((s) => s[1] === 'k');
        expect(keys).toHaveLength(TYPED.length);

        for (const k of keys) {
            expect(k.slice(2), 'a key press names its field and nothing else')
                .toEqual(['input', 'typed-field', 'typed-field']);
        }

        expect(JSON.stringify(recording.samples)).not.toContain(TYPED.slice(0, 3));
    });
});
