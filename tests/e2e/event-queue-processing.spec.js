/**
 * Tracking-event QUEUE end-to-end: defer ingestion, then drain it.
 *
 * WHY THIS EXISTS
 * OWA can be told to QUEUE incoming tracking events instead of ingesting them
 * synchronously (setting: queue_incoming_tracking_events). When on, a beacon at
 * log.php is appended to the FILE queue under owa-data/logs/ and NOT written to the
 * fact tables until a separate drain runs (`php cli.php cmd=processEventQueue`).
 * That deferred path is what a busy site turns on to keep log.php cheap, and it is
 * exactly the code the retry-cap fix touched (owa_processEventQueueController). No
 * jest/phpunit test drives it end to end: enqueue over real HTTP, prove nothing is
 * ingested yet, run the real CLI drain, prove the fact row then appears.
 *
 * WHAT IT DOES
 *   1. queue_e2e_helper.php enable-queue  -- persist the queue flag (the beacon runs
 *      in a SEPARATE php -S process, so the flag must be in the stored config).
 *   2. snapshot { queue_depth, fact_rows } for the harness site.
 *   3. fire the REAL built tracker beacon (tracker_harness.html) at log.php.
 *   4. assert the event landed in the FILE QUEUE and NOT in the facts yet.
 *   5. run the real drain: cli.php cmd=processEventQueue queues=incoming_tracking_events.
 *   6. assert the queue drained AND a request fact now exists for the site.
 *
 * SELF-HOST ONLY. It persists a global setting and drains the shared file queue
 * (owa-data/logs/), so it must never touch the live install. It is gated two ways:
 * this spec skips unless OWA_E2E_SELFHOST=1 (set by the self-host runner), and
 * queue_e2e_helper.php independently HARD-REFUSES unless booted against the scratch
 * sentinel DB. The live-server playwright.config.js does not set that env, so the
 * spec skips there.
 */

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

// The site id the tracker harness beacons under (tracker_harness.html). The
// beacon's owa_site_id is stored verbatim on the request fact, so we count facts
// for exactly this id.
const HARNESS_SITE_ID = 'e2e-tracker-harness';

const REPO_ROOT = path.join(__dirname, '..', '..');
const HELPER = path.join(__dirname, 'queue_e2e_helper.php');
const CLI = path.join(REPO_ROOT, 'cli.php');

const HARNESS_HTML = fs.readFileSync(
    path.join(__dirname, 'tracker_harness.html'), 'utf8'
);

function installRoot(baseURL) {
    return baseURL.replace(/index\.php.*$/, '');
}

/** Run a queue_e2e_helper.php subcommand and parse its JSON stdout. */
function helper(...args) {
    const out = execFileSync('php', [HELPER, ...args], { encoding: 'utf8' });
    return JSON.parse(out);
}

/** Run the REAL CLI drain for the incoming file queue and return its raw output. */
function drainQueue() {
    return execFileSync(
        'php',
        [CLI, 'cmd=processEventQueue', 'queues=incoming_tracking_events'],
        { encoding: 'utf8', cwd: REPO_ROOT }
    );
}

// Gate: only meaningful against the self-host scratch install. Skipping (not
// failing) elsewhere keeps `npm run test:e2e` against the live site green while the
// self-host runner (which sets OWA_E2E_SELFHOST=1) exercises it for real.
const SELFHOST = process.env.OWA_E2E_SELFHOST === '1';

test.describe('tracking events queue to a file and ingest on drain @selfhost-only', () => {
    test.skip(!SELFHOST,
        'Queue processing mutates persisted settings + the shared file queue; runs only under the self-host e2e runner (OWA_E2E_SELFHOST=1).');

    test.beforeAll(() => {
        // Turn the incoming-event queue ON (persisted) for the whole describe.
        const res = helper('enable-queue');
        expect(res.queue_incoming_tracking_events, 'helper failed to enable the queue').toBe(true);
    });

    test.afterAll(() => {
        // Always drain whatever we queued and turn the queue back off, so the
        // scratch install is left in its default (synchronous) state and no stray
        // event file lingers for the next spec.
        try { drainQueue(); } catch (e) { /* best effort */ }
        helper('disable-queue');
    });

    test('a beacon queues to the file queue, stays out of the facts, then ingests on drain', async ({ page }) => {
        // --- baseline -------------------------------------------------------
        const before = helper('state', `site=${HARNESS_SITE_ID}`);

        // --- fire the real built tracker beacon -----------------------------
        const beacons = [];
        page.on('request', (req) => {
            if (req.url().includes('log.php')) {
                beacons.push(req.url());
            }
        });

        const root = installRoot(test.info().project.use.baseURL);
        const harness = root + 'tests/e2e/tracker_harness.html'
            + '?base=' + encodeURIComponent(root);
        // Serve the harness doc from disk; the injected tracker + its log.php beacon
        // fall through to the php -S server, same-origin.
        await page.route(harness, (route) =>
            route.fulfill({ contentType: 'text/html', body: HARNESS_HTML })
        );
        await page.goto(harness, { waitUntil: 'load' });

        // The pixel GET is fire-and-forget; wait until the page_request beacon is on
        // the wire so log.php has been hit before we inspect the queue.
        await expect.poll(() => beacons.length, { timeout: 20_000 }).toBeGreaterThan(0);
        const pageview = beacons.find((u) => u.includes('owa_event_type=base.page_request'));
        expect(pageview, 'no base.page_request beacon was sent').toBeTruthy();

        // --- queued, NOT yet ingested ---------------------------------------
        // The beacon runs on the server AFTER the pixel response flushes; poll for
        // it to land in the file queue rather than assuming it's instant.
        await expect
            .poll(() => helper('state', `site=${HARNESS_SITE_ID}`).queue_depth, { timeout: 20_000 })
            .toBeGreaterThan(before.queue_depth);

        const queued = helper('state', `site=${HARNESS_SITE_ID}`);
        expect(queued.fact_rows,
            'with the queue on, the beacon must NOT be ingested into the facts before the drain'
        ).toBe(before.fact_rows);

        // --- drain: the real CLI processor ----------------------------------
        drainQueue();

        // --- drained + ingested ---------------------------------------------
        const after = helper('state', `site=${HARNESS_SITE_ID}`);
        expect(after.queue_depth, 'the file queue should be empty after the drain').toBe(0);
        expect(after.fact_rows,
            'the drained beacon should now be persisted as a request fact'
        ).toBeGreaterThan(before.fact_rows);
    });
});
