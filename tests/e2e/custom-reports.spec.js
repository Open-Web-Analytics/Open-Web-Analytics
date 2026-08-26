// @ts-check
/**
 * Custom reports: build one, save it, open it, and find it on the roster.
 *
 * WHAT ONLY THIS SUITE CAN CHECK
 *
 * The access rules. `view_reports` is listed in
 * capabilitiesThatRequireSiteAccess, so passing it needs a REAL user with a
 * real grant on the site being looked at. A unit test can give a user a role
 * but not a grant, so the interesting cases -- an analyst opening a report an
 * admin built, and an analyst being refused the builder -- can only be driven
 * here, where the fixture users are real.
 *
 * The other half is the builder itself: it assembles the report definition in
 * the browser and posts it as one field, so nothing server-side ever sees the
 * form's shape. If the assembly is wrong, every server test still passes.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, login, loginAs } = require('./fixtures');

/** A name unique to this run, so reruns do not collide on the roster. */
const reportName = (label) => `E2E ${label} ${Date.now()}`;

/**
 * The builder, opened ON THE FIXTURE SITE.
 *
 * The site matters: makeLink carries the current request's params, so the site
 * the author is looking at travels into the form, into the save, and into the
 * URL the author lands on -- which is the URL they then share. Building on a
 * site the recipient cannot see produces a link that only works for its author.
 */
async function openBuilder(page) {
    await page.goto(`?owa_do=base.customReportEdit&owa_siteId=${FIXTURE.siteId}`,
        { waitUntil: 'networkidle' });
    await page.waitForSelector('#customReportForm', { timeout: 20_000 });
}

async function openRoster(page) {
    await page.goto('?owa_do=base.customReports', { waitUntil: 'networkidle' });
}

/**
 * Build and save a report with one grid widget.
 * @returns {Promise<string>} the name it was saved under
 */
async function buildOne(page, label) {
    const name = reportName(label);

    await openBuilder(page);
    await page.fill('#customReportName', name);

    // One block is drawn for a new report, so there is always something to
    // configure without pressing the plus first.
    await configureWidget(page, 0, { metrics: ['pageViews'], dimensions: ['pagePath'] });

    await page.click('#customReportSubmit');
    await page.waitForLoadState('networkidle');

    return name;
}

/**
 * Pick a value in a Chosen control, by driving the control.
 *
 * Chosen hides the native <select> and renders its own field, so
 * page.selectOption() cannot reach it -- and a test that set the hidden select
 * directly would pass with the visible control broken, which is the only part
 * an author touches.
 *
 * So: open it, type to filter, click the result. That is the interaction, and
 * it also proves the filtering works.
 */
async function chooseInChosen(page, selectId, name) {
    const container = page.locator(`#${selectId}_chosen`);

    await container.click();

    const search = container.locator('.chosen-choices input, .chosen-search input').first();

    /*
     * pressSequentially, NOT fill.
     *
     * Chosen filters on key events. fill() sets the value and fires `input`,
     * which Chosen does not listen for -- so the list stayed unfiltered and
     * clicking "the first result" picked whatever came first alphabetically.
     * Typing "pageViews" selected "actions", and every assertion here counted
     * pills rather than reading them, so the suite went green on it.
     */
    await search.pressSequentially(name);

    const result = page.locator(`#${selectId}_chosen .chosen-results li.active-result`).first();

    await expect(result).toBeVisible();
    await expect(result).toContainText(name, { timeout: 5_000 });

    await result.click();

    // ...and it is the one that ended up selected. Without this the helper can
    // click something and report success for a different choice.
    await expect(page.locator(`#${selectId} option[value="${name}"]`))
        .toHaveJSProperty('selected', true);
}

/** The pills currently shown by a Chosen control. */
function chosenPills(page, selectId) {
    return page.locator(`#${selectId}_chosen .search-choice`);
}

/** Open a block's modal, set some fields, and close it with Done. */
async function configureWidget(page, index, opts) {
    await page.locator('.owa_builderBlock').nth(index).locator('.owa_builderEdit').click();
    await expect(page.locator('#widgetDialog')).toBeVisible();

    if (opts.title !== undefined) { await page.fill('#dlgTitle', opts.title); }
    if (opts.type)                { await page.selectOption('#dlgType', opts.type); }
    if (opts.colspan)             { await page.selectOption('#dlgColspan', String(opts.colspan)); }
    if (opts.rowspan)             { await page.selectOption('#dlgRowspan', String(opts.rowspan)); }

    for (const metric of opts.metrics || [])       { await chooseInChosen(page, 'dlgMetrics', metric); }
    for (const dim of opts.dimensions || [])       { await chooseInChosen(page, 'dlgDimensions', dim); }

    if (opts.sort !== undefined)  { await page.fill('#dlgSort', opts.sort); }

    await page.locator('.ui-dialog-buttonpane button', { hasText: 'Done' }).click();
    await expect(page.locator('#widgetDialog')).toBeHidden();
}

test.describe('custom reports', () => {

    test.describe('as an author', () => {

        test.beforeEach(async ({ page }) => {
            await loginAs(page, FIXTURE.adminUserId, FIXTURE.adminPassword);
        });

        test('the builder offers the four widget types and nothing else', async ({ page }) => {
            await openBuilder(page);

            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();
            await expect(page.locator('#widgetDialog')).toBeVisible();

            const types = await page.locator('#dlgType option')
                .evaluateAll((opts) => opts.map((o) => o.getAttribute('value')));

            expect(types.sort()).toEqual(['grid', 'metric-boxes', 'pie', 'trend']);
        });

        /**
         * The choices come from the reporting registry, not from a list in the
         * template -- so a name the author picks is one the validator will
         * accept. An empty picker would make every save fail for a reason the
         * author could not see.
         */
        test('the pickers are populated from the registry', async ({ page }) => {
            await openBuilder(page);

            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();
            await expect(page.locator('#widgetDialog')).toBeVisible();

            expect(await page.locator('#dlgMetrics option').count()).toBeGreaterThan(10);
            expect(await page.locator('#dlgDimensions option').count()).toBeGreaterThan(10);

            // Named options, not just a count: the value is what reaches the
            // definition, so it has to be the registry NAME.
            await expect(page.locator('#dlgMetrics option[value="pageViews"]')).toHaveCount(1);
            await expect(page.locator('#dlgDimensions option[value="pagePath"]')).toHaveCount(1);
        });

        /**
         * The pickers are the searchable pill control -- the same one the
         * grid's secondary dimension picker uses -- and not a raw multi-select.
         *
         * Asserted on the control chosen BUILDS, because that is the part an
         * author sees. The native select is still there underneath, hidden, and
         * checking it would pass with the visible control broken.
         *
         * The width matters enough to assert: chosen-js sizes itself at
         * enhancement time, and inside a hidden dialog that measurement is zero
         * -- the pickers enhance to a couple of pixels and cannot be used.
         */
        test('the metric picker is a searchable pill control, not a multi-select', async ({ page }) => {
            await openBuilder(page);

            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();

            const chosen = page.locator('#dlgMetrics_chosen');

            await expect(chosen).toBeVisible();
            await expect(page.locator('#dlgMetrics')).toBeHidden();

            const box = await chosen.boundingBox();

            expect(box.width).toBeGreaterThan(100);
        });

        /** Typing filters the list, and choosing turns the value into a pill. */
        test('choosing a metric turns it into a pill that can be removed', async ({ page }) => {
            await openBuilder(page);

            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();

            await expect(chosenPills(page, 'dlgMetrics')).toHaveCount(0);

            await chooseInChosen(page, 'dlgMetrics', 'pageViews');
            await expect(chosenPills(page, 'dlgMetrics')).toHaveCount(1);

            // Several pills in the one field, which is the point of it.
            await chooseInChosen(page, 'dlgMetrics', 'visits');
            await expect(chosenPills(page, 'dlgMetrics')).toHaveCount(2);

            // Each pill carries its own remove.
            await chosenPills(page, 'dlgMetrics').first().locator('.search-choice-close').click();
            await expect(chosenPills(page, 'dlgMetrics')).toHaveCount(1);
        });

        /** The report metric set is the same control. */
        test('the report metric set is a pill control too', async ({ page }) => {
            await openBuilder(page);

            await expect(page.locator('#reportMetricSet_chosen')).toBeVisible();

            await chooseInChosen(page, 'reportMetricSet', 'visits');

            await expect(chosenPills(page, 'reportMetricSet')).toHaveCount(1);
        });

        test('a report can be built, saved, and opens showing its own name', async ({ page }) => {
            const name = await buildOne(page, 'Built');

            // Saving lands on the report itself -- the author's next question
            // is always whether it looks right.
            await expect(page.locator('body')).toContainText(name);

            expect(page.url()).toContain('custom-');
        });

        /**
         * The roster's own header: a count beside the title, and New on the
         * title's line rather than in a bar of its own below it.
         */
        test('the roster titles itself with a count and a New button', async ({ page }) => {
            await buildOne(page, 'Header');
            await openRoster(page);

            const count = page.locator('.owa_titleCount');

            await expect(count).toHaveCount(1);
            await expect(count).toHaveText(/^[0-9]+$/);

            const button = page.locator('.owa_titleActions a', { hasText: 'New Custom Report' });

            await expect(button).toHaveCount(1);
            await expect(button.locator('i.fa')).toHaveCount(1);

            // On the title's LINE, not under it: same vertical centre.
            const titleBox  = await page.locator('.owa_reportTitle').boundingBox();
            const buttonBox = await button.boundingBox();

            expect(Math.abs((titleBox.y + titleBox.height / 2)
                - (buttonBox.y + buttonBox.height / 2))).toBeLessThan(25);
        });

        /**
         * The roster is a list of reports, not a report of a time range, so it
         * offers neither a period nor Live View -- controls that would change
         * nothing on it.
         */
        test('the roster has no date picker and no live view', async ({ page }) => {
            await openRoster(page);

            await expect(page.locator('#owa_timePeriodControl')).toHaveCount(0);
            await expect(page.locator('#liveViewSwitch')).toHaveCount(0);

            // ...but it IS inside the reporting UI, so the nav is there.
            await expect(page.locator('#owa_reportNavPanel')).toHaveCount(1);
        });

        /** Columns sort, and the active one says which way. */
        test('the roster sorts by a column when its heading is clicked', async ({ page }) => {
            await buildOne(page, 'SortA');
            await openRoster(page);

            await page.locator('th a', { hasText: 'Report' }).click();
            await page.waitForLoadState('networkidle');

            const sorted = page.locator('th.owa_sorted');

            await expect(sorted).toHaveCount(1);
            await expect(sorted).toContainText('Report');
            await expect(sorted.locator('.owa_sortIndicator')).toHaveCount(1);

            // Clicking the active column reverses it rather than doing nothing.
            const first = await sorted.locator('.owa_sortIndicator').getAttribute('class');

            await page.locator('th a', { hasText: 'Report' }).click();
            await page.waitForLoadState('networkidle');

            const second = await page.locator('th.owa_sorted .owa_sortIndicator').getAttribute('class');

            expect(second).not.toBe(first);
        });

        test('the saved report appears on the roster with its author', async ({ page }) => {
            const name = await buildOne(page, 'Rostered');

            await openRoster(page);

            const row = page.locator('table.management tr').filter({ hasText: name });

            await expect(row).toHaveCount(1);
            await expect(row).toContainText(FIXTURE.adminUserId);
        });

        /** The roster link is the shareable URL, and it must actually open. */
        test('the roster links to the report', async ({ page }) => {
            const name = await buildOne(page, 'Linked');

            await openRoster(page);

            await page.locator('table.management a').filter({ hasText: name }).click();
            await page.waitForLoadState('networkidle');

            expect(page.url()).toContain('custom-');
            await expect(page.locator('body')).toContainText(name);
        });

        /**
         * A custom report gets the SAME chrome as every other report, because
         * it renders through the same dispatcher rather than supplying its own.
         */
        test('a custom report has the site filter and the date picker', async ({ page }) => {
            await buildOne(page, 'Chrome');

            await expect(page.locator('#owa_reportPeriodLabelContainer')).toHaveCount(1);
            await expect(page.locator('#owa_reportSiteFilter')).toHaveCount(1);
        });

        /**
         * The cap is enforced in the browser too, so an author is stopped at
         * ten rather than told at save time that the eleventh was too many.
         */
        test('the plus adds blocks, and stops at ten', async ({ page }) => {
            await openBuilder(page);

            await expect(page.locator('.owa_builderBlock')).toHaveCount(1);

            for (let i = 1; i < 10; i++) {
                await page.click('#addWidget');
            }

            await expect(page.locator('.owa_builderBlock')).toHaveCount(10);

            // The plus is GONE at the cap, not merely disabled: a control that
            // is present and does nothing reads as broken.
            await expect(page.locator('#addWidget')).toHaveCount(0);
        });

        /**
         * The builder's CSS is actually LOADED, and its blocks are painted.
         *
         * THE BUG THIS EXISTS FOR
         *
         * The builder's rules went into owa.report.css, and the builder is an
         * OPTIONS page -- View/Options.php loads owa.admin.css and nothing
         * else, so owa.report.css never reached it. Every structural test in
         * this file still passed: the markup was correct and none of it was
         * painted, which is what "I don't see the changes" turned out to mean.
         *
         * So this asks the browser for a COMPUTED style rather than for a
         * class. A class assertion cannot tell a loaded stylesheet from a
         * missing one, which is exactly the distinction that was wrong.
         */
        test('the builder is actually styled', async ({ page }) => {
            await openBuilder(page);

            const canvas = page.locator('#customReportCanvas');

            // The canvas is a CSS grid. Unstyled it would be a plain block.
            await expect(canvas).toHaveCSS('display', 'grid');

            const block = page.locator('.owa_builderBlock').first();

            // A styled block has the border and background the rules give it;
            // an unstyled div has neither.
            await expect(block).toHaveCSS('border-top-style', 'solid');

            const bg = await block.evaluate((el) => getComputedStyle(el).backgroundColor);

            expect(bg).not.toBe('rgba(0, 0, 0, 0)');
        });

        /**
         * ...and the modal is a real, styled dialog.
         *
         * It needs jQuery UI's stylesheet, which report pages load and options
         * pages do not -- the builder was an options screen at first, so the
         * dialog SCRIPT was present and the CSS was not, and it opened as an
         * unstyled block in the middle of the document.
         */
        test('the widget modal is a styled dialog', async ({ page }) => {
            await openBuilder(page);

            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();

            const dialog = page.locator('.owa_widgetDialogFrame').first();

            await expect(dialog).toBeVisible();

            // The title bar and buttons only exist once jQuery UI has built it.
            await expect(dialog.locator('.ui-dialog-titlebar')).toBeVisible();
            await expect(dialog.locator('.ui-dialog-buttonpane')).toBeVisible();

            // Our own styling, not the bare theme: a shadow lifts it off the
            // page, asserted as a computed value because a class name would
            // pass with the stylesheet missing.
            const shadow = await dialog.evaluate((el) => getComputedStyle(el).boxShadow);

            expect(shadow).not.toBe('none');
        });

        /**
         * The page behind is dimmed, so the dialog reads as the only thing to
         * answer. jQuery UI draws the overlay for modal:true, but the bundled
         * theme leaves it so faint the page looks live underneath.
         */
        test('the modal dims the page behind it', async ({ page }) => {
            await openBuilder(page);

            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();

            const overlay = page.locator('.ui-widget-overlay');

            await expect(overlay).toBeVisible();

            const opacity = await overlay.evaluate((el) => parseFloat(getComputedStyle(el).opacity));

            expect(opacity).toBeGreaterThan(0.2);

            // ...and it covers the viewport rather than sitting in the flow.
            await expect(overlay).toHaveCSS('position', 'fixed');
        });

        /**
         * The pickers only offer what can be asked for alongside what is
         * already chosen. Clicks and visits are counted in different fact
         * tables, so no query returns both -- offering it would invite a
         * selection that the save then refuses.
         */
        test('the metric picker stops offering incompatible metrics', async ({ page }) => {
            await openBuilder(page);
            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();

            // Both are on offer to begin with.
            await expect(page.locator('#dlgMetrics option[value="visits"]')).toHaveCount(1);
            await expect(page.locator('#dlgMetrics option[value="domClicks"]')).toHaveCount(1);

            await chooseInChosen(page, 'dlgMetrics', 'visits');

            // ...and once visits is chosen, clicks is gone.
            await expect(page.locator('#dlgMetrics option[value="domClicks"]')).toHaveCount(0);
            await expect(page.locator('#dlgMetrics option[value="uniqueVisitors"]')).toHaveCount(1);
        });

        /** The same narrowing reaches dimensions, and the caps are enforced. */
        test('the pickers stop at four', async ({ page }) => {
            await openBuilder(page);
            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();

            for (const m of ['visits', 'uniqueVisitors', 'pageViews', 'uniquePageViews']) {
                await chooseInChosen(page, 'dlgMetrics', m);
            }

            await expect(chosenPills(page, 'dlgMetrics')).toHaveCount(4);

            // Nothing further is offered, rather than offered and then refused
            // on save.
            const left = await page.locator('#dlgMetrics option').evaluateAll(
                (opts) => opts.filter((o) => !o.selected).length);

            expect(left).toBe(0);
        });

        /**
         * A block carries a default name, so a new report is never a row of
         * unlabelled boxes.
         */
        test('a new block has a default name and a type', async ({ page }) => {
            await openBuilder(page);

            const block = page.locator('.owa_builderBlock').first();

            await expect(block.locator('.owa_builderBlockName')).toHaveText('Widget 1');
            await expect(block.locator('.owa_builderBlockType')).toHaveText('Table');
        });

        /**
         * The modal's spans reach the block, which is the point of showing them
         * there: the canvas is the layout, so a span the author sets has to be
         * visible on it before they save.
         */
        test('the span set in the modal is shown on the block', async ({ page }) => {
            await openBuilder(page);

            await configureWidget(page, 0, {
                title: 'Revenue by day',
                type: 'trend',
                colspan: 4,
                rowspan: 2,
                metrics: ['pageViews'],
            });

            const block = page.locator('.owa_builderBlock').first();

            await expect(block.locator('.owa_builderBlockName')).toHaveText('Revenue by day');
            await expect(block.locator('.owa_builderBlockType')).toHaveText('Trend chart');
            await expect(block.locator('.owa_builderBlockSpan')).toHaveText('4 × 2');

            // ...and the block is physically narrower than a full-width one,
            // which is the claim the canvas is making.
            await expect(block).toHaveClass(/owa_builderSpan-4/);
        });

        /** Cancel leaves the widget as it was. */
        test('cancelling the modal changes nothing', async ({ page }) => {
            await openBuilder(page);

            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();
            await page.fill('#dlgTitle', 'Should not stick');
            await page.locator('.ui-dialog-buttonpane button', { hasText: 'Cancel' }).click();

            await expect(page.locator('.owa_builderBlockName').first()).toHaveText('Widget 1');
        });

        /** A block can be removed, and the last one leaves a fresh block. */
        test('removing blocks never empties the canvas', async ({ page }) => {
            await openBuilder(page);

            await page.click('#addWidget');
            await expect(page.locator('.owa_builderBlock')).toHaveCount(2);

            await page.locator('.owa_builderBlock').first().locator('.owa_builderRemove').click();
            await expect(page.locator('.owa_builderBlock')).toHaveCount(1);

            // Removing the last leaves a fresh one: a report with no widgets
            // cannot be saved, so an empty canvas is a dead end.
            await page.locator('.owa_builderBlock').first().locator('.owa_builderRemove').click();
            await expect(page.locator('.owa_builderBlock')).toHaveCount(1);
        });

        /**
         * The span survives the round trip through the definition -- it is
         * stored, and the report is actually drawn with it.
         */
        test('a span is saved and comes back on reopening the builder', async ({ page }) => {
            const name = reportName('Spanned');

            await openBuilder(page);
            await page.fill('#customReportName', name);
            await configureWidget(page, 0, {
                colspan: 3, rowspan: 2,
                metrics: ['pageViews'], dimensions: ['pagePath'],
            });
            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            // The rendered report honours it.
            await expect(page.locator('.owa_reportGridItem.owa_span-3').first()).toHaveCount(1);

            await openRoster(page);
            await page.locator('table.management tr').filter({ hasText: name })
                .locator('a', { hasText: 'Edit' }).click();
            await page.waitForSelector('#customReportCanvas');

            await expect(page.locator('.owa_builderBlockSpan').first()).toHaveText('3 × 2');
        });

        /**
         * A custom report's widgets actually LOAD.
         *
         * THE BUG THIS EXISTS FOR
         *
         * A custom report declaring no report-level metrics inherited the
         * SITE's metric sets, which put the renderer into multi-set mode. In
         * that mode widgets deliberately do not load themselves -- they are
         * registered with the tab machinery, which loads whichever tab is
         * active. A custom report therefore rendered a tab per site metric set,
         * showing the same widgets in each, and fetched nothing into any of
         * them: containers, charts and boxes all empty.
         *
         * The site's metric sets are a property of the SITE, and a shipped
         * report with no metrics of its own opts into them. A custom report is
         * the other kind by construction -- its author chose every widget and
         * what each measures -- so it renders once.
         *
         * Asserted on RENDERED DATA rather than on markup: the containers were
         * always there, and that is exactly what made this invisible to every
         * structural test.
         */
        test('every widget on a custom report renders data', async ({ page }) => {
            const name = reportName('Loads');

            await openBuilder(page);
            await page.fill('#customReportName', name);

            // A grid...
            await configureWidget(page, 0, {
                title: 'Pages',
                type: 'grid',
                metrics: ['pageViews'],
                dimensions: ['pagePath'],
            });

            // ...and a metric-boxes widget, which was the first one noticed.
            await page.click('#addWidget');
            await configureWidget(page, 1, {
                title: 'Totals',
                type: 'metric-boxes',
                metrics: ['visits'],
            });

            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            await expect(page.locator('body')).toContainText(name);

            /*
             * Re-opened over a period the fixture covers. Saving lands on the
             * report with the installation's default period, which is correct
             * -- the builder runs no query and so has no period of its own --
             * but it is not necessarily one the seeded pageviews fall in, and
             * this test is about whether the widgets FETCH, not about which
             * dates they fetch.
             */
            const url = new URL(page.url());
            url.searchParams.set('period', 'last_thirty_days');
            await page.goto(url.toString(), { waitUntil: 'networkidle' });

            // No tabs: a custom report is not rendered per site metric set.
            await expect(page.locator('#report-tabs')).toHaveCount(0);

            // The grid fetched rows, and the boxes drew.
            await expect(page.locator('tr.jqgrow').first()).toBeVisible({ timeout: 20_000 });
            await expect(page.locator('.owa_metricInfobox').first()).toBeVisible({ timeout: 20_000 });
        });

        /**
         * Metric boxes cascade left to right, not down the page.
         *
         * They used to stack: .owa_metricInfobox carries width:100% with
         * float:left, and a full-width float takes a row each -- so a
         * four-metric widget was a column of four boxes. Asserted on measured
         * POSITIONS, because the markup was always the same; only the layout
         * was wrong.
         */
        test('metric boxes lay out across the widget, not down it', async ({ page }) => {
            const name = reportName('Boxes');

            await openBuilder(page);
            await page.fill('#customReportName', name);

            await configureWidget(page, 0, {
                title: 'Totals',
                type: 'metric-boxes',
                metrics: ['visits', 'uniqueVisitors', 'pageViews'],
            });

            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            const url = new URL(page.url());
            url.searchParams.set('period', 'last_thirty_days');
            await page.goto(url.toString(), { waitUntil: 'networkidle' });

            const boxes = page.locator('.owa_metricInfobox');

            await expect(boxes.first()).toBeVisible({ timeout: 20_000 });
            await expect(boxes).toHaveCount(3);

            const first  = await boxes.nth(0).boundingBox();
            const second = await boxes.nth(1).boundingBox();
            const third  = await boxes.nth(2).boundingBox();

            // Side by side: each starts to the right of the one before...
            expect(second.x).toBeGreaterThan(first.x);
            expect(third.x).toBeGreaterThan(second.x);

            // ...and on the same row, which is what stacking would break.
            expect(Math.abs(second.y - first.y)).toBeLessThan(10);
            expect(Math.abs(third.y - first.y)).toBeLessThan(10);
        });

        /** The command bar, for the things you can do TO the report. */
        test('a custom report offers an edit link above its widgets', async ({ page }) => {
            await buildOne(page, 'Commanded');

            const edit = page.locator('.owa_reportCommands a[href*="customReportEdit"]');

            await expect(edit).toHaveCount(1);
            await expect(edit.locator('i.fa')).toHaveCount(1);

            await edit.click();
            await page.waitForSelector('#customReportForm', { timeout: 20_000 });
        });

        /**
         * A definition naming something that does not resolve is refused, and
         * the author is told which name -- with what they typed still in the
         * form, because a redirect would hand back an empty one.
         */
        test('an unresolvable name is refused by name, and the work is kept', async ({ page }) => {
            const name = reportName('Refused');

            await openBuilder(page);
            await page.fill('#customReportName', name);

            // Reach past the pickers: a sort is free text, which is the field
            // an author can put an unresolvable name into.
            await configureWidget(page, 0, {
                metrics: ['pageViews'],
                sort: 'notARealMetric-',
            });

            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            await expect(page.locator('.notice')).toContainText('notARealMetric');

            await expect(page.locator('#customReportName')).toHaveValue(name);
        });
    });

    test.describe('as a reader who cannot author', () => {

        test.beforeEach(async ({ page }) => {
            await login(page);   // the analyst fixture user
        });

        /**
         * edit_reports is admin-only by default, and an analyst does not have
         * it. This is the case the unit tests cannot reach: the analyst is a
         * REAL user with a REAL site grant, so being refused here is about
         * edit_reports and not about site access.
         */
        test('the builder is refused', async ({ page }) => {
            await page.goto('?owa_do=base.customReportEdit', { waitUntil: 'networkidle' });

            await expect(page.locator('#customReportForm')).toHaveCount(0);
        });

        test('the roster opens, without the authoring controls', async ({ page }) => {
            await openRoster(page);

            // The page is theirs to read -- asserted on the roster's own table
            // or its empty-state text, not on a string the NAV also contains.
            await expect(page.locator('table.management, .owa_reportSectionContent').first())
                .toBeVisible();

            // ...but there is nothing on it to author with. The New button
            // lives on the title's line now, offered only to an author.
            await expect(page.locator('.owa_titleActions')).toHaveCount(0);
        });
    });

    /**
     * The whole point of "shareable by url": a report built by one person opens
     * for another, who could not have built it themselves.
     *
     * The second reader gets their own CONTEXT rather than the first one's with
     * the cookies cleared. Clearing cookies mid-test leaves the page on a
     * document that belongs to the previous session, and the next request goes
     * out from it; a fresh context is how Playwright models "a different person
     * at a different computer", which is what is being tested.
     */
    test('a report built by an admin opens for an analyst who was sent the link',
        async ({ page, browser }) => {

        await loginAs(page, FIXTURE.adminUserId, FIXTURE.adminPassword);

        const name = await buildOne(page, 'Shared');

        // The URL the author lands on IS the share link, and it carries the
        // site -- view_reports is only ever satisfied against a particular one.
        const url = page.url();

        expect(url).toContain('siteId');

        const reader = await browser.newContext();
        const theirs = await reader.newPage();

        try {
            await login(theirs);
            await theirs.goto(url, { waitUntil: 'networkidle' });

            await expect(theirs.locator('body')).toContainText(name);
        } finally {
            await reader.close();
        }
    });

    /**
     * ...and the roster still filters by ownership, so the analyst does not see
     * it LISTED. Sharing is by link; the roster is what you made.
     */
    test('a shared report is not listed on the roster of someone who did not make it',
        async ({ page, browser }) => {

        await loginAs(page, FIXTURE.adminUserId, FIXTURE.adminPassword);

        const name = await buildOne(page, 'NotListed');

        const reader = await browser.newContext();
        const theirs = await reader.newPage();

        try {
            await login(theirs);
            await openRoster(theirs);

            await expect(theirs.locator('table.management tr').filter({ hasText: name }))
                .toHaveCount(0);
        } finally {
            await reader.close();
        }
    });
});
