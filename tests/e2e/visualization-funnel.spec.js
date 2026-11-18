// @ts-check
/**
 * The funnel visualization computes, and this asserts what it computed.
 *
 * A funnel counts ordered stages over the event stream: a stage counts only
 * those who reached it AFTER the stage before, which no arrangement of metrics
 * and dimensions expresses. That is why it has a controller of its own, and
 * why it is a visualization rather than a report. The stages come from the
 * visualization's own definition, and the counting assertions below protect
 * that no stage is constrained on something that matches nothing.
 *
 * So this asserts CONTENT, not that a page rendered: the stage names, their
 * paths, and a non-zero count on every one.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, login } = require('./fixtures');
const {
    filterPills, filterForm, pillButton, openFilterForm, fillFilter, offeredDimensions, reloadedBy,
} = require('./filter-pills');

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

    await page.goto(`?owa_do=base.customReports&owa_siteId=${FIXTURE.siteId}`,
        { waitUntil: 'networkidle' });

    const href = await page
        .locator('table.owa_customReportRoster tbody tr', { hasText: VIZ.name })
        .locator('a[href*="reportId=custom-"]').first()
        .getAttribute('href');

    /*
     * `reportId`, and `owa_reportId` as the fallback.
     *
     * Report links are built with makeLink(), which writes OWA's own URLs in
     * the app namespace -- empty on this install, so the roster's link says
     * `reportId=` with no prefix.
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

/** Each stage's count, in step order. */
async function stepCounts(page) {
    return (await page.locator('.funnelStepCount').allTextContents()).map((c) => parseInt(c.trim(), 10));
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

        // Of those who entered, the share who reached the last step.
        await expect(page.locator('.owa_funnelHeadline')).toContainText('Completion Rate');
        await expect(page.locator('.owa_funnelHeadline')).not.toContainText('conversion');
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

    test('every stage counts users, so none constrained on nothing', async ({ page }) => {
        await openFunnel(page);

        const counts = await page.locator('.funnelStepCount').allTextContents();

        expect(counts.length).toBe(VIZ.steps.length);
        expect(counts.every((c) => /\d+\s*users/.test(c))).toBe(true);

        // The fixture walks one visitor through every step in order, so every
        // stage MUST count somebody. Zero means a stage is constrained on a
        // path that matches nothing, or the ordering dropped someone who really
        // did go through.
        expect(counts.some((c) => /^0\s*users/.test(c.trim()))).toBe(false);
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

    /** The counting scope is a pair of radios; choosing one reloads the funnel counted that way. */
    test('choosing Visits or Visitors counts the funnel that way', async ({ page }) => {
        await openFunnelAs(page, 'visitor');

        const scope = page.getByRole('radiogroup', { name: 'Counting:' });

        await expect(scope.getByRole('radio', { name: 'Visitors' })).toBeChecked();

        const visits = await reloadedBy(page, () => scope.getByText('Visits', { exact: true }).click());

        expect(visits.get('owa_funnelScope')).toBe('session');
        await expect(scope.getByRole('radio', { name: 'Visits' })).toBeChecked();

        const visitors = await reloadedBy(page, () => scope.getByText('Visitors', { exact: true }).click());

        expect(visitors.get('owa_funnelScope')).toBe('visitor');
        await expect(scope.getByRole('radio', { name: 'Visitors' })).toBeChecked();
    });

    test('the control bar is for LOOKING, and editing is on the title', async ({ page }) => {
        await openFunnel(page);

        // What the bar offers: how you are looking at it.
        await expect(page.locator('#funnelScopeSwitch')).toBeVisible();
        await expect(page.getByRole('group', { name: 'Filters' })).toBeVisible();

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
     * counting visits gives more entrants and a lower completion rate.
     */
    test('the counting scope changes what is counted', async ({ page }) => {
        await openFunnelAs(page, 'visitor');
        const byVisitor = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));
        await expect(page.locator('.visitorCountLabel').first()).toHaveText('users');

        await openFunnelAs(page, 'session');
        const bySession = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));
        await expect(page.locator('.visitorCountLabel').first()).toHaveText('sessions');

        expect(bySession[0]).toBeGreaterThan(byVisitor[0]);
    });

    /**
     * The segment filter: which people the funnel is drawn for. Filter pills,
     * behind a Filter button; the form starts shut.
     */
    test('the control bar offers a filter, behind its own button', async ({ page }) => {
        await openFunnel(page);

        const button = filterPills(page).getByRole('button', { name: 'Filter', exact: true });

        await expect(button).toHaveAttribute('aria-expanded', 'false');
        await expect(filterForm(page)).toBeHidden();

        await button.click();

        await expect(filterForm(page)).toBeVisible();
        await expect(button).toHaveAttribute('aria-expanded', 'true');
    });

    /**
     * It offers what the reporting stack offers, so a name it lists is a name
     * the segment will actually accept -- a hand-written list would offer names
     * the query then refuses. Metrics too: a metric condition picks the people
     * by their totals.
     */
    test('the filter offers dimensions and metrics the segment accepts', async ({ page }) => {
        await openFunnel(page);

        const { names } = await offeredDimensions(page);

        expect(names.length).toBeGreaterThan(10);
        expect(names).toContain('sessionMedium');
        expect(names).toContain('pageViews');
    });

    /**
     * The segment picks PEOPLE, so the time group is deliberately not offered:
     * the period already bounds the funnel, and a date in the SEGMENT reads like
     * "the funnel on that day" while actually meaning "everyone active that day,
     * counted across the whole period".
     *
     * The site group IS offered. A funnel is of a Property, so "the people seen
     * on this Profile" is a segment worth asking for.
     */
    test('the filter offers the Profile but not time dimensions', async ({ page }) => {
        await openFunnel(page);

        const { names, groups } = await offeredDimensions(page);

        expect(groups).not.toContain('time');
        expect(groups.length).toBeGreaterThan(1);

        for (const name of ['date', 'day', 'month', 'year']) {
            expect(names).not.toContain(name);
        }

        expect(names).toContain('siteId');
        expect(names).toContain('sessionMedium');
    });

    /**
     * A filter added in the pills reloads the funnel with it as the segment,
     * keeping the counting scope, and its X takes it off again.
     */
    test('a filter added with the pills segments the funnel, and its X removes it', async ({ page }) => {
        await openFunnelAs(page, 'session');

        const all = await stepCounts(page);

        expect(all[0]).toBeGreaterThan(1);

        await openFilterForm(page);
        await fillFilter(page, { dimension: 'sessionMedium', condition: 'is', value: 'organic' });

        const added = await reloadedBy(page,
            () => filterForm(page).getByRole('button', { name: 'Add filter' }).click());

        expect(added.get('owa_constraints')).toBe('sessionMedium==organic');
        expect(added.get('owa_funnelScope')).toBe('session');

        const organic = await stepCounts(page);

        expect(organic[0]).toBeGreaterThan(0);
        expect(organic[0]).toBeLessThan(all[0]);

        const removed = await reloadedBy(page, () => pillButton(page, 'Remove', 'Medium is organic').click());

        expect(removed.get('owa_constraints')).toBe('');
        expect(await stepCounts(page)).toEqual(all);
        await expect(pillButton(page, 'Remove', '')).toHaveCount(0);
    });

    /**
     * A filter from the URL comes back as a pill, which can be changed in
     * place; a second condition adds to the first. A metric condition picks
     * sessions by their totals.
     */
    test('a pill is changed in place, and a second condition narrows it further', async ({ page }) => {
        await openFunnel(page, '&owa_funnelScope=session&owa_constraints='
            + encodeURIComponent('sessionMedium==organic'));

        await pillButton(page, 'Change', 'Medium is organic').click();

        const form = filterForm(page);

        await expect(form.getByLabel('Dimension', { exact: true })).toHaveValue('sessionMedium');
        await expect(form.getByLabel('Condition', { exact: true })).toHaveValue('==');
        await expect(form.getByLabel('Value', { exact: true })).toHaveValue('organic');

        await fillFilter(page, { condition: 'is not' });

        const changed = await reloadedBy(page, () => form.getByRole('button', { name: 'Update filter' }).click());

        expect(changed.get('owa_constraints')).toBe('sessionMedium!=organic');

        const notOrganic = await stepCounts(page);

        expect(notOrganic[0]).toBeGreaterThan(0);

        await openFilterForm(page);
        await fillFilter(page, { dimension: 'pageViews', condition: 'greater than', value: '2' });

        const both = await reloadedBy(page, () => form.getByRole('button', { name: 'Add filter' }).click());

        expect(both.get('owa_constraints')).toBe('sessionMedium!=organic,pageViews>2');

        // The fixture's direct visit that walks the whole funnel has three page views.
        const longer = await stepCounts(page);

        expect(longer[0]).toBeGreaterThan(0);
        expect(longer[0]).toBeLessThanOrEqual(notOrganic[0]);
        await expect(pillButton(page, 'Remove', '')).toHaveCount(2);
    });

    /**
     * The two empty tests take no value, and split the sessions between them:
     * every session either has a medium or has none.
     */
    test('the empty tests need no value and split the sessions', async ({ page }) => {
        await openFunnelAs(page, 'session');

        const all = await stepCounts(page);
        const counts = {};

        for (const [condition, constraint] of [['has a value', 'sessionMedium!=(not set)'],
                                               ['is not set', 'sessionMedium==(not set)']]) {
            await openFunnelAs(page, 'session');
            await openFilterForm(page);
            await fillFilter(page, { dimension: 'sessionMedium', condition });

            await expect(filterForm(page).getByLabel('Value', { exact: true })).toBeHidden();

            const params = await reloadedBy(page,
                () => filterForm(page).getByRole('button', { name: 'Add filter' }).click());

            expect(params.get('owa_constraints')).toBe(constraint);
            counts[condition] = (await stepCounts(page))[0];
        }

        expect(counts['has a value'] + counts['is not set']).toBe(all[0]);
    });

    /**
     * The controls sit on one line.
     *
     * Measured, not asserted from the markup: the scope switch borrows the Live
     * View control's markup and the filter is the editors' pills, so each
     * arrives with its own styling and its own idea of whether it is inline.
     */
    test('the control bar keeps its controls on one line', async ({ page }) => {
        await openFunnel(page);

        const boxes = {};

        for (const [name, control] of Object.entries({
            scope:  page.locator('#funnelScopeSwitch .buttons'),
            filter: filterPills(page).getByRole('button', { name: 'Filter', exact: true }),
        })) {
            const box = await control.first().boundingBox();
            expect(box, `${name} control has no box`).not.toBeNull();
            boxes[name] = box.y + box.height / 2;
        }

        const centres = Object.values(boxes);
        const spread = Math.max(...centres) - Math.min(...centres);

        // One line, allowing for controls of slightly different heights.
        expect(spread, `controls are on different lines: ${JSON.stringify(boxes)}`)
            .toBeLessThan(20);
    });

    /** Opening the filter's form must not move the controls beside it. */
    test('opening the filter does not move the rest of the bar', async ({ page }) => {
        await openFunnel(page);

        const before = await page.locator('#funnelScopeSwitch .buttons').boundingBox();

        await openFilterForm(page);

        const after = await page.locator('#funnelScopeSwitch .buttons').boundingBox();

        expect(Math.abs(after.y - before.y)).toBeLessThan(3);
        expect(Math.abs(after.x - before.x)).toBeLessThan(3);
    });

    /*
     * chosen-js 1.x sizes its container from the <select>'s width when it is
     * enhanced, which is 0 inside a hidden form. The pills give it an explicit
     * width.
     */
    test("the filter's dimension list opens at a usable width", async ({ page }) => {
        await openFunnel(page);
        await openFilterForm(page);

        const box = filterForm(page).locator('[data-ui="filter:dimension"]');
        const width = (await box.boundingBox())?.width ?? 0;

        expect(width).toBeGreaterThanOrEqual(150);

        await box.click();
        await expect(box.getByRole('textbox')).toBeVisible();
    });

    test('chosen widgets are actually STYLED (stylesheet matches the markup)', async ({ page }) => {
        // chosen-js 1.8.7's JS emits .chosen-* markup, so the served CSS must carry
        // .chosen-* rules or the widget renders as a bare unstyled list. So assert the
        // stylesheet actually takes effect: the sprite-driven dropdown arrow paints a
        // background image. On the funnel, whose filter's dimension list is a chosen
        // widget.
        await openFunnel(page);

        const arrowBg = await page.evaluate(() => {
            const b = document.querySelector('.chosen-container .chosen-single div b');
            return b ? getComputedStyle(b).backgroundImage : null;
        });
        expect(arrowBg).not.toBeNull();
        expect(arrowBg).toContain('chosen-sprite');
    });

    /**
     * A segment selects the PEOPLE and the funnel is then counted over all of
     * their pages, so a segment matching nobody empties the funnel rather than
     * leaving it unsegmented.
     */
    test('a segment that matches nobody empties the funnel', async ({ page }) => {
        await openFunnel(page,
            '&owa_constraints=' + encodeURIComponent('sessionMedium==no-such-medium'));

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

        // The grid draws its data rows as tr.owa_gridRow.
        await expect(page.locator('#funnel-steps-grid .owa_gridTable')).toBeVisible();

        const rows = page.locator('#funnel-steps-grid tr.owa_gridRow');

        await expect(rows).toHaveCount(VIZ.steps.length);

        // The rows carry the computed numbers, not just a shell.
        await expect(rows.first()).toContainText(VIZ.steps[0].path);

        // Computed rows, so the grid must not offer controls that would
        // re-query a result set URL that does not exist.
        await expect(page.locator('#funnel-steps-grid').getByRole('button', { name: /filter/i })).toHaveCount(0);
    });
});
