// @ts-check
/**
 * The domstreams report: a recording is a recording, not a row.
 *
 * WHAT THIS EXISTS FOR
 *
 * The tracker flushes its event queue on a timer, so one DOM recording is
 * stored as however many rows it took to hold it, all sharing a
 * domstream_guid, and each carrying the CUMULATIVE elapsed seconds at the
 * moment it was flushed. The list groups them back together.
 *
 * The previous query grouped and then selected `duration` as a BARE column.
 * sql_mode is '' on every connection, so ONLY_FULL_GROUP_BY is off and MySQL
 * answered with an arbitrary row's value instead of refusing -- a twenty-minute
 * recording could report as the ninety seconds its first chunk covered. The
 * fixture's first recording is three chunks of 12, 95 and 40 seconds precisely
 * so every wrong answer is a different number from the right one.
 *
 * WHAT IS ASSERTED
 *
 * Content, not that a page rendered: one row per recording, the aggregates each
 * row carries, a Play link that points at the recorded page, and a segment
 * filter that actually narrows the list.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, login } = require('./fixtures');

const DS = FIXTURE.domstreams;

/** The report, optionally with a segment applied. */
async function openDomstreams(page, constraints) {
    await page.goto(
        `?owa_do=base.report&owa_reportId=domstreams`
        + `&owa_siteId=${FIXTURE.siteId}`
        + `&owa_period=last_thirty_days`
        + (constraints ? `&owa_constraints=${encodeURIComponent(constraints)}` : ''),
        { waitUntil: 'networkidle' }
    );
}

/** The grid's data rows, once it has drawn. */
function rows(page) {
    return page.locator('#domstreams-grid tr.jqgrow');
}

/**
 * The row for one recording, found by the page it was recorded on.
 *
 * Matched on the WHOLE cell, not as a substring. One of the fixture's
 * recordings is on `/`, whose URL is a prefix of the other's `/pricing` -- a
 * substring match finds both, and the filter tests below would then pass with
 * the filter doing nothing.
 */
function rowFor(page, path) {
    const url = FIXTURE.siteDomain + path;
    const exact = new RegExp('^\\s*' + url.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*$');

    return rows(page).filter({ has: page.locator('td').filter({ hasText: exact }) });
}

test.describe('reporting: domstreams', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('lists one row per recording, not one per stored chunk', async ({ page }) => {
        await openDomstreams(page);
        await page.waitForSelector('#domstreams-grid tr.jqgrow', { timeout: 20_000 });

        // Four rows are stored: three chunks of one recording and one of the
        // other. Two recordings is the whole point of grouping.
        await expect(rows(page)).toHaveCount(DS.recordings);
    });

    test('a multi-chunk recording reports its whole duration', async ({ page }) => {
        await openDomstreams(page);
        await page.waitForSelector('#domstreams-grid tr.jqgrow', { timeout: 20_000 });

        const row = rowFor(page, DS.a.page);

        await expect(row).toHaveCount(1);

        // 0:01:35 -- the largest chunk. Its neighbours would be 0:00:12 (first
        // written) and 0:02:27 (summed), so this cannot pass by accident.
        await expect(row).toContainText(DS.a.durationLabel);
        await expect(row).not.toContainText('0:00:12');
        await expect(row).not.toContainText('0:02:27');
    });

    test('a recording reports how many chunks and how much was recorded', async ({ page }) => {
        await openDomstreams(page);
        await page.waitForSelector('#domstreams-grid tr.jqgrow', { timeout: 20_000 });

        const cells = rowFor(page, DS.a.page).locator('td');

        await expect(cells.filter({ hasText: new RegExp(`^${DS.a.segments}$`) })).toHaveCount(1);
        await expect(rowFor(page, DS.a.page)).toContainText(DS.a.sizeLabel);
    });

    test('a single-chunk recording is listed too', async ({ page }) => {
        await openDomstreams(page);
        await page.waitForSelector('#domstreams-grid tr.jqgrow', { timeout: 20_000 });

        await expect(rowFor(page, DS.b.page)).toContainText(DS.b.durationLabel);
    });

    /**
     * The player opens the RECORDED page with its parameters on the fragment.
     * That is how the overlay reaches the tracker running on that page.
     */
    test('each recording has a play link pointing at the recorded page', async ({ page }) => {
        await openDomstreams(page);
        await page.waitForSelector('#domstreams-grid tr.jqgrow', { timeout: 20_000 });

        const play = rowFor(page, DS.a.page).locator('a.play');

        await expect(play).toHaveCount(1);

        const href = await play.getAttribute('href');

        expect(href).toContain(FIXTURE.siteDomain + DS.a.page);
        expect(href).toContain('#owa_overlay.');

        // The window is sized to the viewport the recording was made in,
        // because the replay positions events against that geometry.
        await expect(play).toHaveAttribute('data-width', /^[0-9]+$/);
        await expect(play).toHaveAttribute('data-height', /^[0-9]+$/);
    });

    test.describe('the segment filter', () => {

        test('narrows the list to the visits it selects', async ({ page }) => {
            await openDomstreams(page, `medium==${DS.a.medium}`);
            await page.waitForSelector('#domstreams-grid tr.jqgrow', { timeout: 20_000 });

            await expect(rows(page)).toHaveCount(1);
            await expect(rowFor(page, DS.a.page)).toHaveCount(1);
            await expect(rowFor(page, DS.b.page)).toHaveCount(0);
        });

        /**
         * The other medium selects the other recording. Asserted because a
         * filter that always returned the first row would pass the test above.
         */
        test('a different segment selects a different recording', async ({ page }) => {
            await openDomstreams(page, `medium==${DS.b.medium}`);
            await page.waitForSelector('#domstreams-grid tr.jqgrow', { timeout: 20_000 });

            await expect(rows(page)).toHaveCount(1);
            await expect(rowFor(page, DS.b.page)).toHaveCount(1);
        });

        /**
         * A refused constraint SAYS SO. An empty list with no explanation reads
         * as "nothing was recorded", which is a different claim.
         */
        test('says so when the constraint names nothing real', async ({ page }) => {
            await openDomstreams(page, 'notADimension==x');

            await expect(page.locator('.notice')).toContainText('notADimension');
            await expect(rows(page)).toHaveCount(0);
        });

        /**
         * The picker offers what the reporting stack offers, MINUS site and
         * time: the report is already scoped to one site, and a date in the
         * segment picks everyone active that day and then lists their
         * recordings across the whole period.
         */
        test('offers no site or time dimensions', async ({ page }) => {
            await openDomstreams(page);
            await page.waitForSelector('#domstreamFilter', { timeout: 20_000 });

            /*
             * The options live in the filter DIALOG, not in #domstreamFilter:
             * the builder is a jQuery-UI dialog now, and jQuery UI lifts it to
             * `.owa`. It keeps an id naming the element it filters -- see
             * builder_id in owa.resultSetExplorer.js -- which is how one filter
             * is told from another now that they are all siblings.
             */
            const names = await page.locator('#owa_filterBuilder-domstreamFilter option')
                .evaluateAll((opts) => opts.map((o) => o.getAttribute('value')));

            for (const excluded of ['siteId', 'siteDomain', 'siteName', 'date', 'day', 'month', 'year']) {
                expect(names).not.toContain(excluded);
            }

            // ...and it is not passing because the picker is empty.
            expect(names).toContain('medium');
        });
    });

    /**
     * The grid's own explorer controls are off: a secondary dimension and its
     * Filter both re-query the result set's URL, and these rows came from a
     * query this report ran itself, so those controls would offer choices that
     * cannot do anything.
     */
    test('the grid offers no explorer controls of its own', async ({ page }) => {
        await openDomstreams(page);
        await page.waitForSelector('#domstreams-grid tr.jqgrow', { timeout: 20_000 });

        await expect(page.locator('#domstreams-grid .explorerTopControls')).toHaveCount(0);
    });
});
