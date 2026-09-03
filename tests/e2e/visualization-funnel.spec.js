// @ts-check
/**
 * The funnel visualization computes, and this asserts what it computed.
 *
 * A funnel counts ordered stages over the event stream: a stage counts only
 * those who reached it AFTER the stage before, which no arrangement of metrics
 * and dimensions expresses. That is why it kept a controller when 62 of 64
 * reports became JSON, and why it is a visualization rather than a report.
 *
 * It used to read its stages from a goal's funnel configuration and append one
 * more from the goal's own goal_url -- a stage the report BUILT rather than
 * read, which is exactly the one a key rename silently emptied (the constructor
 * kept saying `url` after the steps started saying `path`, so the last bar
 * constrained on nothing and reported zero). The stages come from the
 * visualization's own definition now, so there is no built stage left to get
 * wrong; the counting assertions below are kept because what they protect --
 * that no stage is constrained on something that matches nothing -- is
 * unchanged.
 *
 * So this asserts CONTENT, not that a page rendered: the stage names, their
 * paths, and a non-zero count on every one.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, login } = require('./fixtures');

const VIZ = FIXTURE.funnelVisualization;

/*
 * The seeded visualization's id, found by NAME on its own roster.
 *
 * The seeder mints the id, so it cannot be written down in the fixture file.
 * Looked up once per worker rather than per test: it does not change within a
 * run, and every test here opens the same row.
 */
let vizId = null;

async function visualizationId(page) {

    if (vizId) {
        return vizId;
    }

    await page.goto(`?owa_do=base.visualizations&owa_siteId=${FIXTURE.siteId}`,
        { waitUntil: 'networkidle' });

    const href = await page
        .locator('table.management tbody tr', { hasText: VIZ.name })
        .locator('a[href*="reportId=custom-"]').first()
        .getAttribute('href');

    /*
     * `reportId`, and `owa_reportId` as the fallback.
     *
     * Report links are built with makeLink(), which writes OWA's own URLs in
     * the app namespace -- empty on this install, so the roster's link says
     * `reportId=` with no prefix. Reading only the prefixed name returned null,
     * every test then asked for `owa_reportId=null`, and the funnel that could
     * not be found looked exactly like a funnel that failed to render.
     */
    const params = new URL(href, page.url()).searchParams;

    vizId = params.get('reportId') || params.get('owa_reportId');

    expect(vizId, 'the roster must link to the visualization by id').toBeTruthy();

    return vizId;
}

/** The funnel, optionally with a counting scope or a segment on the URL. */
async function openFunnel(page, extra = '') {
    const id = await visualizationId(page);

    await page.goto(
        `?owa_do=base.report&owa_reportId=${encodeURIComponent(id)}`
        + `&owa_siteId=${FIXTURE.siteId}`
        + `&owa_period=last_thirty_days`
        + extra,
        { waitUntil: 'networkidle' }
    );
    await page.waitForSelector('.owa_funnelChart', { timeout: 20_000 });
}

/** The funnel drawn with a given counting scope. */
async function openFunnelAs(page, scope) {
    await openFunnel(page, `&owa_funnelScope=${scope}`);
}

test.describe('visualization: funnel', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('draws a stage for every step, in the order they were written', async ({ page }) => {
        await openFunnel(page);

        // Assert per stage, not against the bare class: the name has to land on
        // the stage at its own step number, and a locator matching all three is
        // both strict-mode ambiguous and a weaker claim.
        //
        // ORDER is the claim. orderBy() with no direction defaults to DESC
        // here, which drew the steps last-first -- a funnel that reported the
        // destination as its entry point and still looked plausible.
        for (const [i, step] of VIZ.steps.entries()) {
            const stage = page.locator('.owa_funnelStepColumn').nth(i);

            await expect(stage.locator('.funnelStepName')).toContainText(step.name);
            await expect(stage.locator('.funnelStepPath')).toHaveText(step.path);
        }
    });

    /**
     * Every stage comes from the definition now -- there is no stage the report
     * builds rather than reads. So the count is exact, and an extra bar means
     * something is inventing one again.
     */
    test('draws exactly the steps it was given', async ({ page }) => {
        await openFunnel(page);

        await expect(page.locator('.owa_funnelStepColumn')).toHaveCount(VIZ.steps.length);
    });

    test('every stage counts visitors, so none constrained on nothing', async ({ page }) => {
        await openFunnel(page);

        const counts = await page.locator('.funnelStepCount').allTextContents();

        expect(counts.length).toBe(VIZ.steps.length);
        expect(counts.every((c) => /\d+\s*visitors/.test(c))).toBe(true);

        // The fixture walks one visitor through every step in order, so every
        // stage MUST count somebody. Zero means a stage is constrained on a
        // path that matches nothing, or the ordering dropped someone who really
        // did go through.
        expect(counts.some((c) => /^0\s*visitors/.test(c.trim()))).toBe(false);
    });

    /**
     * The funnel is ORDERED: a step counts only those who reached it after the
     * step before. So a stage can never out-count the one in front of it, and a
     * fixture visitor who saw a later page without entering the funnel must not
     * appear in that later stage.
     */
    test('no stage out-counts the stage before it', async ({ page }) => {
        await openFunnel(page);

        const counts = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));

        for (let i = 1; i < counts.length; i++) {
            expect(counts[i]).toBeLessThanOrEqual(counts[i - 1]);
        }
    });

    test('the control bar is for LOOKING, and editing is on the title', async ({ page }) => {
        await openFunnel(page);

        // What the bar offers: how you are looking at it.
        await expect(page.locator('#funnelScopeSwitch')).toBeVisible();
        await expect(page.locator('#funnelFilter')).toBeVisible();

        /*
         * And what it does NOT offer. Changing what the funnel IS is a
         * different kind of act from changing how it is drawn, so it is the
         * pencil beside the title -- the same control, in the same place, that
         * every custom report gets. It was a text link in this bar.
         */
        await expect(
            page.locator('.owa_reportControls a[href*="visualizationEdit"]'),
            'the edit link is back in the control bar'
        ).toHaveCount(0);

        await expect(
            page.locator('.owa_reportTitle a.owa_titleActionMark[href*="visualizationEdit"]')
        ).toHaveCount(1);
    });

    /**
     * Visits and visitors are different questions, and the fixture answers them
     * differently: one visitor completes the funnel across several visits, so
     * counting visits gives more entrants and a lower conversion rate.
     */
    test('the counting scope changes what is counted', async ({ page }) => {
        await openFunnelAs(page, 'visitor');
        const byVisitor = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));
        await expect(page.locator('.visitorCountLabel').first()).toHaveText('visitors');

        await openFunnelAs(page, 'session');
        const bySession = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));
        await expect(page.locator('.visitorCountLabel').first()).toHaveText('visits');

        expect(bySession[0]).toBeGreaterThan(byVisitor[0]);
    });

    /**
     * The segment filter: which people the funnel is drawn for.
     *
     * It offers what the reporting stack offers, so a name it lists is a name
     * the segment will actually accept -- a hand-written list would offer names
     * the query then refuses.
     */
    test('the control bar offers a filter, behind its own toggle', async ({ page }) => {
        await openFunnel(page);

        await expect(page.locator('#funnelFilter .constraintPickerContainer')).toBeVisible();

        /*
         * The builder is a DIALOG, so it is no longer inside #funnelFilter --
         * jQuery UI lifts it to `.owa`. It is addressed by the id it takes from
         * the element it filters; see the builder_id comment in
         * owa.resultSetExplorer.js.
         */
        const builder = page.locator('#owa_filterBuilder-funnelFilter');

        // Behind a toggle: it starts closed.
        await expect(builder).toBeHidden();

        await page.locator('#funnelFilter .toggle-button').click();
        await expect(builder).toBeVisible();
    });

    test('the filter offers dimensions the segment accepts', async ({ page }) => {
        await openFunnel(page);

        await page.locator('#funnelFilter .toggle-button').click();

        // Opening the builder already offers one empty constraint row, so this
        // does not add another -- clicking "add" would give two pickers and
        // every assertion below would count double.
        const options = page.locator(
            '#owa_filterBuilder-funnelFilter .constraintDimensionPicker option');

        // A real list, not an empty picker.
        expect(await options.count()).toBeGreaterThan(10);

        // medium is what the segment is most obviously useful for, and it is one
        // the funnel's outer query can resolve.
        expect(await page.locator(
            '#owa_filterBuilder-funnelFilter .constraintDimensionPicker option[value="medium"]').count())
            .toBeGreaterThan(0);
    });

    /**
     * The controls sit on one line.
     *
     * Measured, not asserted from the markup: the scope switch borrows the Live
     * View control's markup and the filter borrows the grid's constraint
     * builder, so each arrives with its own styling and its own idea of whether
     * it is inline. Checking the classes are present would pass while they sat
     * on three different lines.
     */
    test('the control bar keeps its controls on one line', async ({ page }) => {
        await openFunnel(page);

        const boxes = {};

        for (const [name, selector] of Object.entries({
            scope:  '#funnelScopeSwitch .buttons',
            filter: '#funnelFilter .toggle-button',
        })) {
            const box = await page.locator(selector).first().boundingBox();
            expect(box, `${name} control has no box`).not.toBeNull();
            boxes[name] = box.y + box.height / 2;
        }

        const centres = Object.values(boxes);
        const spread = Math.max(...centres) - Math.min(...centres);

        // One line, allowing for controls of slightly different heights.
        expect(spread, `controls are on different lines: ${JSON.stringify(boxes)}`)
            .toBeLessThan(20);
    });

    /**
     * Opening the filter must not move the controls beside it.
     *
     * It was a panel that hung below the bar; it is a dialog now, which cannot
     * move the bar at all. The assertion is kept rather than deleted because
     * what it protects is unchanged -- opening a filter must not reflow the
     * report behind it -- and a dialog is one way of being right about that,
     * not a reason to stop checking.
     */
    test('opening the filter does not move the rest of the bar', async ({ page }) => {
        await openFunnel(page);

        const before = await page.locator('#funnelScopeSwitch .buttons').boundingBox();

        await page.locator('#funnelFilter .toggle-button').click();
        await expect(page.locator('#owa_filterBuilder-funnelFilter')).toBeVisible();

        const after = await page.locator('#funnelScopeSwitch .buttons').boundingBox();

        expect(Math.abs(after.y - before.y)).toBeLessThan(3);
    });

    /**
     * The segment picks PEOPLE, so two groups are deliberately not offered.
     *
     * site: the report is already scoped to one, and the site filter in the
     * report chrome is where that is chosen.
     *
     * time: the period already bounds the funnel, and a date in the SEGMENT
     * reads like "the funnel on that day" while actually meaning "everyone
     * active that day, counted across the whole period".
     */
    test('the filter does not offer site or time dimensions', async ({ page }) => {
        await openFunnel(page);

        await page.locator('#funnelFilter .toggle-button').click();

        const picker = '#owa_filterBuilder-funnelFilter .constraintDimensionPicker';

        // The groups themselves are gone, not just individual names.
        await expect(page.locator(`${picker} optgroup[label="site"]`)).toHaveCount(0);
        await expect(page.locator(`${picker} optgroup[label="time"]`)).toHaveCount(0);

        for (const name of ['siteId', 'siteDomain', 'siteName', 'date', 'day', 'month', 'year']) {
            await expect(page.locator(`${picker} option[value="${name}"]`)).toHaveCount(0);
        }

        // The groups that remain are still there -- this must not empty the picker.
        expect(await page.locator(`${picker} option[value="medium"]`).count()).toBeGreaterThan(0);
        expect(await page.locator(`${picker} option`).count()).toBeGreaterThan(10);
    });

    /**
     * A segment selects the PEOPLE and the funnel is then counted over all of
     * their pages, so a segment matching nobody empties the funnel rather than
     * leaving it unsegmented.
     */
    test('a segment that matches nobody empties the funnel', async ({ page }) => {
        await openFunnel(page,
            '&owa_constraints=' + encodeURIComponent('medium==no-such-medium'));

        const counts = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));

        expect(counts.every((n) => n === 0)).toBe(true);
    });

    test('a refused segment says so rather than showing an unsegmented funnel', async ({ page }) => {
        const id = await visualizationId(page);

        await page.goto(
            `?owa_do=base.report&owa_reportId=${encodeURIComponent(id)}`
            + `&owa_siteId=${FIXTURE.siteId}`
            + `&owa_period=last_thirty_days`
            + `&owa_constraints=` + encodeURIComponent('bogusDimension==x'),
            { waitUntil: 'networkidle' }
        );

        await expect(page.locator('.notice')).toContainText('not a dimension or a metric');
    });

    /** The steps table is the standard grid, drawn from the computed rows. */
    test('the steps table renders a row per stage', async ({ page }) => {
        await openFunnel(page);

        // jqGrid draws its data rows as tr.jqgrow inside a .ui-jqgrid wrapper.
        await expect(page.locator('#funnel-steps-grid .ui-jqgrid')).toBeVisible();

        const rows = page.locator('#funnel-steps-grid tr.jqgrow');

        await expect(rows).toHaveCount(VIZ.steps.length);

        // The rows carry the computed numbers, not just a shell.
        await expect(rows.first()).toContainText(VIZ.steps[0].path);

        // Computed rows, so the grid must not offer controls that would
        // re-query a result set URL that does not exist.
        await expect(page.locator('#funnel-steps-grid .constraintPicker')).toHaveCount(0);
    });
});
