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

/**
 * Add a widget of a given type, through the plus.
 *
 * The type is chosen BEFORE the widget modal opens -- the modal is built for a
 * type, so there has to be one first. That is also why configureWidget() below
 * takes no type: by the time a block exists, its type is settled.
 */
async function addWidget(page, type, opts = {}) {
    await page.click('#addWidget');

    await expect(page.locator('#typeDialog')).toBeVisible();
    await page.locator(`.owa_typeChoice[data-type="${type}"]`).click();

    // Choosing lands straight in the widget modal, on the block just added.
    await expect(page.locator('#widgetDialog')).toBeVisible();

    await fillWidget(page, opts);
}

/**
 * One widget of a given type, and nothing else.
 *
 * A new report starts with one grid block so the canvas is never empty. A test
 * that wants a single widget of some OTHER type adds one and drops that
 * default, rather than asserting past it.
 */
async function onlyWidget(page, type, opts = {}) {
    await addWidget(page, type, opts);

    await page.locator('.owa_builderBlock').first().locator('.owa_builderRemove').click();
    await expect(page.locator('.owa_builderBlock')).toHaveCount(1);
}

/** Open a block's modal, set some fields, and close it with Done. */
async function configureWidget(page, index, opts) {
    await page.locator('.owa_builderBlock').nth(index).locator('.owa_builderEdit').click();
    await expect(page.locator('#widgetDialog')).toBeVisible();

    await fillWidget(page, opts);
}

/** Fill the widget modal that is already open, and close it with Done. */
async function fillWidget(page, opts) {
    if (opts.title !== undefined) { await page.fill('#dlgTitle', opts.title); }
    if (opts.colspan)             { await page.selectOption('#dlgColspan', String(opts.colspan)); }
    if (opts.rowspan)             { await page.selectOption('#dlgRowspan', String(opts.rowspan)); }

    for (const metric of opts.metrics || [])       { await chooseInChosen(page, 'dlgMetrics', metric); }
    for (const dim of opts.dimensions || [])       { await chooseInChosen(page, 'dlgDimensions', dim); }

    // AFTER the dimensions: the row-link destinations are per dimension, so
    // the select is rebuilt every time one changes.
    if (opts.link !== undefined)      { await page.selectOption('#dlgLinkReport', opts.link); }
    if (opts.more !== undefined)      { await page.selectOption('#dlgMoreReport', opts.more); }
    if (opts.moreLabel !== undefined) { await page.fill('#dlgMoreLabel', opts.moreLabel); }

    if (opts.sort !== undefined)  { await page.fill('#dlgSort', opts.sort); }

    await page.locator('.ui-dialog-buttonpane button', { hasText: 'Done' }).click();
    await expect(page.locator('#widgetDialog')).toBeHidden();
}

test.describe('custom reports', () => {

    test.describe('as an author', () => {

        test.beforeEach(async ({ page }) => {
            await loginAs(page, FIXTURE.adminUserId, FIXTURE.adminPassword);
        });

        test('the builder offers the buildable widget types and nothing else', async ({ page }) => {
            await openBuilder(page);

            // Asked at the plus, before the widget modal: the modal is built
            // FOR a type, so there has to be one before it can open.
            await page.click('#addWidget');
            await expect(page.locator('#typeDialog')).toBeVisible();

            const types = await page.locator('.owa_typeChoice')
                .evaluateAll((els) => els.map((e) => e.getAttribute('data-type')));

            // Two table types, deliberately: a full-width explorable grid and a
            // quarter-width card. See CustomReports::FULL_WIDTH_TYPES.
            expect(types.slice().sort()).toEqual(
                ['grid', 'grid-card', 'metric-boxes', 'pie', 'trend', 'trend-card']);

            // Each says what it is for -- "Table" beside "Table card" is a
            // distinction nobody can make from the names alone.
            for (const hint of await page.locator('.owa_typeChoiceHint').allInnerTexts()) {
                expect(hint.trim().length).toBeGreaterThan(10);
            }
        });

        /**
         * ...and the type is settled once the block exists.
         *
         * It used to be a select inside the widget modal, which made that one
         * form have to be every form at once -- and every answer already given
         * had to be re-examined whenever the type changed. A trend has no
         * dimension to pick; a card takes one metric where a table takes four.
         */
        test('the widget modal does not offer the type again', async ({ page }) => {
            await openBuilder(page);

            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();
            await expect(page.locator('#widgetDialog')).toBeVisible();

            await expect(page.locator('#dlgType')).toHaveCount(0);
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

            // The plus asks what KIND now, so each one is a choice as well as
            // a click -- and the chooser is modal, so the next plus is behind
            // its overlay until it closes.
            for (let i = 1; i < 10; i++) {
                await addWidget(page, 'grid');
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

            await onlyWidget(page, 'trend', {
                title: 'Revenue by day',
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

        /*
         * ------------------------------------------------------------------
         * A table's width and its controls are one decision
         * ------------------------------------------------------------------
         *
         * A grid draws a control bar -- a dimension picker and a filter -- and
         * every control on it adds width. Narrowed to a quarter of the row that
         * bar stopped fitting, and .owa_reportGridItem carries overflow-x, so
         * the widget grew a horizontal scrollbar instead.
         *
         * So the type decides both: a grid is full width and explorable, a
         * grid-card is a quarter wide with one metric against one dimension and
         * no controls at all.
         */
        test('a grid has no column span to set, and says why', async ({ page }) => {
            await openBuilder(page);

            // The block a new report starts with is a grid.
            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();
            await expect(page.locator('#widgetDialog')).toBeVisible();

            await expect(page.locator('#dlgColspanField')).toBeHidden();
            await expect(page.locator('#dlgWidthNote')).toContainText('always full width');

            // The row span is still the author's -- only the width is decided.
            await expect(page.locator('#dlgRowspan')).toBeVisible();

            await page.locator('.ui-dialog-buttonpane button', { hasText: 'Cancel' }).click();

            // ...and a card has a width to choose.
            await page.click('#addWidget');
            await page.locator('.owa_typeChoice[data-type="grid-card"]').click();
            await expect(page.locator('#widgetDialog')).toBeVisible();

            await expect(page.locator('#dlgColspanField')).toBeVisible();
            await expect(page.locator('#dlgWidthNote')).toBeHidden();
        });

        /*
         * The test that used to sit here asserted a trend had NO dimension
         * picker at all. It does now: its axis is fixed to a date, but the
         * dimension whose values become its lines is the author's. What
         * remains of that assertion -- that the axis is not a choice, and that
         * time cannot be the breakdown -- is covered by 'a trend offers a
         * breakdown, and refuses time as the thing to break out by'.
         */

        /**
         * A row of totals has nothing to group by either, but for a different
         * reason -- and the sentence says which.
         */
        test('metric boxes ask for no dimension, because they are totals', async ({ page }) => {
            await openBuilder(page);

            await page.click('#addWidget');
            await page.locator('.owa_typeChoice[data-type="metric-boxes"]').click();
            await expect(page.locator('#widgetDialog')).toBeVisible();

            await expect(page.locator('#dlgDimensionsField')).toBeHidden();
            await expect(page.locator('#dlgDimensionNote')).toContainText('totals for the period');
        });

        /**
         * A trend card names its own metrics and has nothing to group by.
         *
         * Both follow from the width. Half a row has no room for a legend of
         * six lines, so it cannot be broken out -- and a report metric set
         * would replace the figures its author chose with three to six whose
         * boxes do not fit that width, so it does not take one.
         *
         * The form has to SAY the second one. "Leave empty to use the report
         * metric set" is the sentence every other multi-metric type carries,
         * and an author who acted on it here would be refused on save by the
         * rule that sentence contradicted.
         */
        test('a trend card names its own metrics and is not broken out', async ({ page }) => {
            await openBuilder(page);

            await page.click('#addWidget');
            await page.locator('.owa_typeChoice[data-type="trend-card"]').click();
            await expect(page.locator('#widgetDialog')).toBeVisible();

            // Several metrics, so the field is plural -- unlike a card or a pie.
            await expect(page.locator('#dlgMetricsLabel')).toHaveText('Metrics');

            // ...and it says the set is not on offer, rather than offering it.
            await expect(page.locator('#dlgMetricsHelp'))
                .toContainText('does not take the report metric set');
            await expect(page.locator('#dlgMetricsHelp')).not.toContainText('Leave empty');

            // The boxes are above the chart, and the form says which.
            await expect(page.locator('#dlgMetricsHelp')).toContainText('above');

            // Nothing to group by: its axis is fixed and it adds none.
            await expect(page.locator('#dlgDimensionsField')).toBeHidden();
        });

        /**
         * ...and it saves and renders, with its boxes above its chart.
         *
         * The end-to-end shape of the type: chosen in the builder, stored,
         * and drawn by the renderer the other side of a round trip.
         */
        test('a trend card renders with its boxes above its chart', async ({ page }) => {
            await openBuilder(page);
            await page.fill('#customReportName', reportName('Carded'));

            // A new report starts with a grid block; this report is one card.
            await onlyWidget(page, 'trend-card', { title: 'Visits', metrics: ['visits'] });

            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            expect(page.url()).toContain('custom-');

            const boxes = page.locator('[id$="-metrics"].owa_trendCardMetrics');
            await expect(boxes).toHaveCount(1);

            // ABOVE: both are blocks in one column, so the order in the
            // document is the whole of what "above" means.
            const boxBox   = await boxes.boundingBox();
            const chartBox = await page.locator('.owa_reportGridItem .owa_areaChart').first().boundingBox();

            expect(boxBox).not.toBeNull();
            expect(chartBox).not.toBeNull();
            expect(boxBox.y).toBeLessThan(chartBox.y);

            // Borderless, which is the type's whole visual difference.
            const border = await boxes.locator('.owa_metricInfobox').first()
                .evaluate(el => getComputedStyle(el).borderTopWidth);

            expect(border).toBe('0px');

            // Half a row by default, without the author choosing a width.
            await expect(page.locator('.owa_reportGridItem.owa_span-6')).toHaveCount(1);
        });

        /**
         * The order an author picks fields in is the order they mean.
         *
         * ORDER IS MEANING in a definition: the first metric is what a trend
         * charts, and the first dimension is what a grid is grouped by. The
         * builder was throwing it away -- narrowMetrics() rebuilds the option
         * list on every change, `$select.val()` answers in OPTION order, and
         * the options were in registry order. So picking visits, then unique
         * visitors, then page views stored `pageViews,uniqueVisitors,visits`:
         * the author's first choice became the last box, and the chart drew the
         * one they picked last.
         *
         * Asserted at three points, because the bug was silent at the first
         * two: the pills the author is looking at, the order the boxes render
         * in, and which one the chart draws.
         *
         * ...and four metrics fit ONE row on a card, which is the other half of
         * why this test builds four. A card is half a row and the metric count
         * is the author's, so the boxes share the width rather than each taking
         * a fixed 150px slice -- at which four became two rows of two.
         */
        test('a card keeps the order its metrics were chosen in, four to a row', async ({ page }) => {
            await openBuilder(page);
            await page.fill('#customReportName', reportName('Ordered'));

            // Deliberately NOT alphabetical: registry order is
            // pageViews < uniqueVisitors < visits, so an alphabetised list is
            // the exact reverse of this and the assertions can tell them apart.
            const CHOSEN = ['visits', 'uniqueVisitors', 'pageViews', 'bounceRate'];

            await onlyWidget(page, 'trend-card', { title: 'Ordered', metrics: CHOSEN });

            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            expect(page.url()).toContain('custom-');

            const boxes = page.locator('.owa_trendCardMetrics .owa_metricInfobox');
            await expect(boxes).toHaveCount(4);

            // The boxes read in the order they were chosen.
            expect(await boxes.evaluateAll(els => els.map(e => e.getAttribute('data-metric'))))
                .toEqual(CHOSEN);

            // ...and the chart draws the FIRST of them, not the last.
            const charted = await boxes.evaluateAll(els => els
                .filter(e => e.classList.contains('owa_metricInfoboxCharted'))
                .map(e => e.getAttribute('data-metric')));

            expect(charted).toEqual(['visits']);

            // One row: every box shares a top edge.
            const tops = await boxes.evaluateAll(els =>
                els.map(e => Math.round(e.getBoundingClientRect().top)));

            expect(new Set(tops).size,
                `four boxes wrapped onto ${new Set(tops).size} rows`).toBe(1);
        });

        /**
         * SAVING MUST NOT STRAND THE AUTHOR.
         *
         * The left-hand navigation is how you leave a report, and it vanished
         * after every save: makeNavigationMenu() returns false without a
         * currentSiteId, and the save redirect carried none, because the
         * builder had been opened without one and its hidden siteId was empty.
         *
         * The report drew its title and all its chrome, so nothing looked
         * broken. There was simply no way off the page except the back button.
         *
         * ReportController::pre() resolves a default site now, which fixes the
         * whole chain in one place -- the builder is a ReportController too, so
         * its hidden field is filled, the save carries it, and the report it
         * redirects to has one.
         */
        test('the navigation survives saving a report', async ({ page }) => {
            // Opened with NO siteId, which is how the nav's own link reaches it
            // and the state the bug needed.
            await page.goto('?owa_do=base.customReportEdit', { waitUntil: 'networkidle' });

            const navLinks = page.locator('#owa_reportNavPanel a');

            // The builder itself is a reporting screen and needs its nav too.
            expect(await navLinks.count(),
                'the builder opened without a navigation').toBeGreaterThan(10);

            // The site it resolved travels with the form, which is what carries
            // it through the save.
            await expect(page.locator('input[name="siteId"]')).not.toHaveValue('');

            await page.fill('#customReportName', reportName('Navigated'));
            await onlyWidget(page, 'trend-card', { title: 'N', metrics: ['visits'] });

            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            // Landed on the report...
            expect(page.url()).toContain('custom-');
            await expect(page.locator('.owa_reportTitle')).toBeVisible();

            // ...and can still get anywhere from it.
            expect(await navLinks.count(),
                'the navigation vanished after saving').toBeGreaterThan(10);
        });

        /**
         * A card draws one metric, so it is offered a METRIC, not a metric set.
         *
         * The report metric set is several metrics, and a card ranks its rows
         * by one -- so calling the field "Metrics" there would be offering a
         * set the widget has no way to draw.
         */
        test('a card asks for one metric, a table for a set', async ({ page }) => {
            await openBuilder(page);

            await page.locator('.owa_builderBlock').first().locator('.owa_builderEdit').click();
            await expect(page.locator('#dlgMetricsLabel')).toHaveText('Metrics');
            await expect(page.locator('#dlgMetricsHelp')).toContainText('report metric set');
            await page.locator('.ui-dialog-buttonpane button', { hasText: 'Cancel' }).click();

            await page.click('#addWidget');
            await page.locator('.owa_typeChoice[data-type="grid-card"]').click();

            await expect(page.locator('#dlgMetricsLabel')).toHaveText('Metric');
            await expect(page.locator('#dlgDimensionsLabel')).toHaveText('Dimension');
            await expect(page.locator('#dlgMetricsHelp')).not.toContainText('report metric set');
        });

        test('a card takes one metric and one dimension and then offers no more',
            async ({ page }) => {

            await openBuilder(page);

            await page.click('#addWidget');
            await page.locator('.owa_typeChoice[data-type="grid-card"]').click();
            await expect(page.locator('#widgetDialog')).toBeVisible();

            await chooseInChosen(page, 'dlgMetrics', 'pageViews');
            await chooseInChosen(page, 'dlgDimensions', 'pagePath');

            await expect(chosenPills(page, 'dlgMetrics')).toHaveCount(1);
            await expect(chosenPills(page, 'dlgDimensions')).toHaveCount(1);

            /*
             * At the cap the pickers offer NOTHING further, rather than
             * offering a second and refusing it on save. Asserted on the
             * options the select holds, because that is what chosen renders its
             * list from -- an option left in place but unusable would still
             * turn up in the search results.
             */
            const spare = async (id) => page.locator(`#${id} option`).evaluateAll(
                (opts) => opts.filter((o) => !o.selected).length);

            expect(await spare('dlgMetrics')).toBe(0);
            expect(await spare('dlgDimensions')).toBe(0);
        });

        /*
         * The test that used to sit here checked that switching a grid to a
         * card trimmed its metrics to one. A widget's type is settled when it
         * is added now, so there is no switch to trim after -- the pickers are
         * capped for the type from the moment the modal opens, which is what
         * 'a card takes one metric and one dimension and then offers no more'
         * covers.
         */

        /*
         * ------------------------------------------------------------------
         * Where a widget can lead
         * ------------------------------------------------------------------
         */

        /**
         * The row-link destinations are per dimension, and derived.
         *
         * A detail report declares the constraint it is read under, so a card
         * grouped by pagePath is offered the reports that read a pagePath and
         * nothing else -- offering "Browser Detail" would build a link to a
         * report that answers 400 for a parameter it never gets.
         */
        test('a card is offered only the reports its dimension can reach', async ({ page }) => {
            await openBuilder(page);

            await page.click('#addWidget');
            await page.locator('.owa_typeChoice[data-type="grid-card"]').click();
            await expect(page.locator('#widgetDialog')).toBeVisible();

            // Nothing to link from yet.
            await expect(page.locator('#dlgLinkField')).toBeHidden();

            await chooseInChosen(page, 'dlgMetrics', 'pageViews');
            await chooseInChosen(page, 'dlgDimensions', 'pagePath');

            await expect(page.locator('#dlgLinkField')).toBeVisible();

            const offered = await page.locator('#dlgLinkReport option')
                .evaluateAll((opts) => opts.map((o) => o.value).filter(Boolean));

            // Both reports that declare they are read under a pagePath.
            expect(offered.sort()).toEqual(['document', 'dom-clicks']);
        });

        /**
         * The link BELOW the widget follows the same rule, minus the constraint.
         *
         * Scoped to the dimension the same way -- a card of top pages leads to
         * the full Top Pages report -- but the destination must read no value,
         * because this link carries none. So the detail reports the row link
         * exists for are exactly the ones this must not offer.
         */
        test('the full-report link is scoped to the dimension too', async ({ page }) => {
            await openBuilder(page);

            await page.click('#addWidget');
            await page.locator('.owa_typeChoice[data-type="grid-card"]').click();
            await expect(page.locator('#widgetDialog')).toBeVisible();

            // Nothing shown yet, so there is no "more of the same thing".
            await expect(page.locator('#dlgMoreField')).toBeHidden();

            await chooseInChosen(page, 'dlgMetrics', 'pageViews');
            await chooseInChosen(page, 'dlgDimensions', 'pagePath');

            await expect(page.locator('#dlgMoreField')).toBeVisible();

            const offered = await page.locator('#dlgMoreReport option')
                .evaluateAll((opts) => opts.map((o) => o.value).filter(Boolean));

            expect(offered).toContain('pages');

            // Reads a pagePath: that is the ROW link's business.
            expect(offered).not.toContain('document');

            // ...and a report about something else is not a fuller version.
            expect(offered).not.toContain('browsers');
        });

        /**
         * Both links, end to end: configured in the modal, stored, and rendered.
         */
        test('a card can link its rows and carry a full-report link', async ({ page }) => {
            const name = reportName('Linked');

            await openBuilder(page);
            await page.fill('#customReportName', name);

            await onlyWidget(page, 'grid-card', {
                title: 'Top pages',
                metrics: ['pageViews'],
                dimensions: ['pagePath'],
                link: 'document',
                more: 'pages',
            });

            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            const url = new URL(page.url());
            url.searchParams.set('period', 'last_thirty_days');
            await page.goto(url.toString(), { waitUntil: 'networkidle' });

            const card = page.locator('.owa_reportGridItem.owa_span-3').first();

            await expect(card.locator('tr.jqgrow').first()).toBeVisible({ timeout: 20_000 });

            // The rows are links into the report that details a page, carrying
            // the row's own value.
            const rowLink = card.locator('tr.jqgrow a').first();

            await expect(rowLink).toBeVisible();

            const href = await rowLink.getAttribute('href');

            expect(href).toContain('reportId=document');
            expect(href).toMatch(/pagePath=/);

            // ...and the value is the row's, not the literal %s.
            expect(href).not.toContain('%s');

            // And the link below the widget, with the wording the shipped
            // summary widgets use.
            const more = card.locator('.owa_moreLinks a');

            await expect(more).toHaveCount(1);
            await expect(more).toContainText('View Full Report');
            expect(await more.getAttribute('href')).toContain('reportId=pages');
        });

        /**
         * A pie draws ONE metric and has to be told which.
         *
         * It was not: the builder wrote chartMetric for a trend and not for a
         * pie, so a pie built here came out with options.pieChart.metric empty
         * and drew nothing at all -- which looks like a site with no data.
         */
        test('a pie built here charts the metric it was given', async ({ page }) => {
            const name = reportName('Pie');

            await openBuilder(page);
            await page.fill('#customReportName', name);

            await onlyWidget(page, 'pie', {
                title: 'Pages',
                metrics: ['pageViews'],
                dimensions: ['pagePath'],
            });

            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            const url = new URL(page.url());
            url.searchParams.set('period', 'last_thirty_days');
            await page.goto(url.toString(), { waitUntil: 'networkidle' });

            // The definition names the metric...
            const charted = await page.evaluate(() => {
                const key = Object.keys(window).find((k) => /^w1$/.test(k));
                return key ? window[key].options.pieChart.metric : null;
            });

            expect(charted).toBe('pageViews');

            // ...and a chart actually drew, which an empty metric would not.
            await expect(page.locator('.owa_reportGridItem canvas').first())
                .toBeVisible({ timeout: 20_000 });
        });

        /*
         * ------------------------------------------------------------------
         * A trend broken out by a dimension
         * ------------------------------------------------------------------
         *
         * A trend charts ONE metric. What varies is the dimension: given one,
         * each of its values becomes a line, and the filled area behind them is
         * their total. Given none, the metric itself is that filled area, which
         * is what every shipped trend still is.
         *
         * The metric list is NOT the series list -- a trend also draws a box
         * per metric under its chart, which is what a report metric set is for,
         * so it carries the set and names which one of it to plot.
         */
        test('a trend offers a breakdown, and refuses time as the thing to break out by',
            async ({ page }) => {

            await openBuilder(page);

            await page.click('#addWidget');
            await page.locator('.owa_typeChoice[data-type="trend"]').click();
            await expect(page.locator('#widgetDialog')).toBeVisible();

            // The axis is settled; what is offered is the breakdown.
            await expect(page.locator('#dlgDimensionsField')).toBeVisible();
            await expect(page.locator('#dlgDimensionsLabel')).toHaveText('Break out by');
            await expect(page.locator('#dlgDimensionsHelp')).toContainText('always over date');

            // Metrics stay PLURAL: the chart draws one, the boxes under it
            // draw all of them.
            await expect(page.locator('#dlgMetricsLabel')).toHaveText('Metrics');

            /*
             * A trend is drawn against time, so time cannot also be what it is
             * broken out by -- visits by month, over months, is not a chart
             * anybody meant to ask for.
             */
            const offered = await page.locator('#dlgDimensions option')
                .evaluateAll((o) => o.map((e) => e.value));

            for (const t of ['date', 'day', 'month', 'year', 'weekofyear']) {
                expect(offered).not.toContain(t);
            }

            // ...and it does offer ordinary ones.
            expect(offered).toContain('medium');
        });

        test('a broken-out trend draws a line per value over a filled total',
            async ({ page }) => {

            const name = reportName('Broken');

            await openBuilder(page);
            await page.fill('#customReportName', name);

            await onlyWidget(page, 'trend', {
                title: 'Visits by medium',
                metrics: ['visits'],
                dimensions: ['medium'],
            });

            // Stored as the axis first, then the breakdown: the chart reads the
            // first as what it plots against and the second as its lines.
            const stored = await page.evaluate(() => {
                document.querySelector('#customReportForm')
                    .dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                return JSON.parse(document.querySelector('#customReportDefinition').value);
            });

            expect(stored.widgets[0].query.dimensions).toBe('date,medium');
            expect(stored.widgets[0].chartMetric).toBe('visits');

            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            const url = new URL(page.url());
            url.searchParams.set('period', 'last_thirty_days');
            await page.goto(url.toString(), { waitUntil: 'networkidle' });

            await expect.poll(async () => page.evaluate(
                () => (window.w1 && window.w1.areaChart) ? window.w1.areaChart.dataseries.length : 0),
                { timeout: 20_000 }).toBeGreaterThan(1);

            const chart = await page.evaluate(() => {
                const ac = window.w1.areaChart;
                return {
                    labels: ac.dataseries.map((s) => s.label),
                    fills: ac.dataseries.map((s) => !!(s.lines && s.lines.fill)),
                    colors: ac.dataseries.map((s) => s.color),
                    firstPoints: ac.dataseries.map((s) => s.data[0][1]),
                    lengths: ac.dataseries.map((s) => s.data.length),
                };
            });

            // The total comes first, and it is the only filled one: it is the
            // shape of the whole thing, and the lines are what it is made of.
            expect(chart.labels[0]).toBe('Total');
            expect(chart.fills[0]).toBe(true);
            expect(chart.fills.slice(1).every((f) => f === false)).toBe(true);

            // The seeded mediums each got a line.
            expect(chart.labels).toContain('direct');
            expect(chart.labels).toContain('organic-search');

            /*
             * A colour each, and the PIE's colours.
             *
             * A report shows traffic sources as a pie and the same sources as
             * lines over time; giving them different palettes makes the reader
             * do work the colour was supposed to save. One shared list, in
             * order, with the total keeping the blue a trend has always been.
             */
            expect(new Set(chart.colors).size).toBe(chart.colors.length);

            const palette = await page.evaluate(() => window.OWA.chartColors);

            expect(chart.colors.slice(1)).toEqual(palette.slice(0, chart.colors.length - 1));

            /*
             * The legend sits at the left edge, with the chart's y-axis labels.
             * Centred, it moved sideways whenever a series was added or a label
             * changed length, so there was no stable place for the eye to
             * return to.
             */
            const legend = await page.evaluate(() => {
                const box = document.querySelector('#w1 > .owa_chartLegend');
                const table = box.querySelector('table');
                return {
                    gap: Math.round(table.getBoundingClientRect().left - box.getBoundingClientRect().left),
                    align: getComputedStyle(box.querySelector('.legendLabel')).textAlign,
                };
            });

            expect(legend.gap).toBeLessThanOrEqual(1);
            expect(legend.align).toBe('left');

            /*
             * The total is the sum of the lines at each point, and every series
             * covers every x -- a value with no rows on a day is a ZERO, not a
             * gap. A gap would make flot join the days either side of it,
             * drawing a line straight over an absence.
             */
            const [total, ...parts] = chart.firstPoints;

            /*
             * The total is the sum of EVERY row, not of the drawn lines. Only
             * the six largest values get a line, so the two agree exactly here
             * -- the fixture seeds three mediums -- and the total would be the
             * larger of them on a dimension with more values than that.
             */
            expect(chart.labels.length - 1).toBeLessThanOrEqual(6);
            expect(total).toBe(parts.reduce((a, b) => a + b, 0));
            expect(new Set(chart.lengths).size).toBe(1);
        });

        /**
         * Day or month is a question about how you want to READ a trend, not
         * about what the report is -- so it is a reader's control, and the
         * stored query is untouched by it.
         *
         * The regrouping happens in SQL. A month's value is not the sum of the
         * days that came back, it is the sum of the days that exist, so this
         * refetches rather than recomputing.
         */
        test('a trend can be switched between day and month', async ({ page }) => {
            const name = reportName('Grain');

            await openBuilder(page);
            await page.fill('#customReportName', name);
            await onlyWidget(page, 'trend', { metrics: ['visits'], dimensions: ['medium'] });
            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            const url = new URL(page.url());
            url.searchParams.set('period', 'last_thirty_days');
            await page.goto(url.toString(), { waitUntil: 'networkidle' });

            const control = page.locator('.owa_chartGranularity');

            await expect(control).toBeVisible({ timeout: 20_000 });
            await expect(control.locator('option')).toHaveCount(2);
            await expect(control).toHaveValue('date');

            await control.selectOption('month');

            /*
             * Waited on the RESULT SET, not on the chart's idea of its axis.
             *
             * changeGranularity() sets xDimension before it fetches, so polling
             * that is polling something already true -- the assertions below
             * would then read the URL of the request still in flight.
             */
            await expect.poll(async () => page.evaluate(
                () => decodeURIComponent(window.w1.resultSet.self)), { timeout: 20_000 })
                .toContain('dimensions=month');

            const after = await page.evaluate(() => ({
                url: window.w1.resultSet.self,
                x: window.w1.areaChart.xDimension,
                timeformat: window.w1.areaChart.flotOptions.xaxis.timeformat,
                firstX: window.w1.areaChart.dataseries[0].data[0][0],
                total: window.w1.areaChart.dataseries[0].data[0][1],
                parts: window.w1.areaChart.dataseries.slice(1).map((s) => s.data[0][1]),
            }));

            expect(after.x).toBe('month');

            // The QUERY changed, and the sort with it -- a sort naming a
            // dimension the query no longer has is one the server cannot
            // resolve.
            expect(decodeURIComponent(after.url)).toContain('dimensions=month,medium');
            expect(decodeURIComponent(after.url)).toContain('sort=month');

            // A month axis labelled with days would repeat the same day number
            // every tick.
            expect(after.timeformat).toBe('%b %Y');

            // The month column stores yyyymm, so a month point is the first of
            // that month rather than the number 1 to 12.
            expect(new Date(after.firstX).getUTCDate()).toBe(1);

            // ...and it is still a real total of its parts.
            expect(after.total).toBe(after.parts.reduce((a, b) => a + b, 0));
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

            await addWidget(page, 'grid');
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
            // A pie, because a grid has no width to set -- it is full width by
            // type. This test is about a span surviving the round trip, so it
            // uses a type that still has one.
            await onlyWidget(page, 'pie', {
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

            // A grid -- the block a new report starts with...
            await configureWidget(page, 0, {
                title: 'Pages',
                metrics: ['pageViews'],
                dimensions: ['pagePath'],
            });

            // ...and a metric-boxes widget, which was the first one noticed.
            await addWidget(page, 'metric-boxes', {
                title: 'Totals',
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
         * The rendered outcome of the type split, measured.
         *
         * A grid is full width and carries its control bar; a card is a quarter
         * of the row and carries none. Asserted on the RENDERED widgets rather
         * than on the definition, because the bug this replaced was entirely a
         * rendering one -- the definition said colspan 6 and the renderer drew
         * a full-width bar inside it.
         */
        test('a grid renders full width with controls, a card renders narrow with none',
            async ({ page }) => {

            const name = reportName('TableAndCard');

            await openBuilder(page);
            await page.fill('#customReportName', name);

            await configureWidget(page, 0, {
                title: 'Pages',
                metrics: ['pageViews'],
                dimensions: ['pagePath'],
            });

            await addWidget(page, 'grid-card', {
                title: 'Top pages',
                metrics: ['pageViews'],
                dimensions: ['pagePath'],
            });

            await page.click('#customReportSubmit');
            await page.waitForLoadState('networkidle');

            const url = new URL(page.url());
            url.searchParams.set('period', 'last_thirty_days');
            await page.goto(url.toString(), { waitUntil: 'networkidle' });

            const grid = page.locator('.owa_reportGridItem.owa_span-12').first();
            const card = page.locator('.owa_reportGridItem.owa_span-3').first();

            await expect(grid).toHaveCount(1);
            await expect(card).toHaveCount(1);

            // Both drew a table: a card is a table, just a small one with no
            // controls -- not a different renderer.
            await expect(grid.locator('tr.jqgrow').first()).toBeVisible({ timeout: 20_000 });
            await expect(card.locator('tr.jqgrow').first()).toBeVisible({ timeout: 20_000 });

            // The bar is on the grid and NOT on the card. This is the whole
            // point of the split.
            await expect(grid.locator('.explorerTopControls')).toHaveCount(1);
            await expect(card.locator('.explorerTopControls')).toHaveCount(0);

            /*
             * And the card does not scroll sideways, which is the symptom that
             * started this. Compared against its own client width rather than a
             * fixed number, so it holds at any viewport.
             */
            const overflow = await card.evaluate(
                (el) => el.scrollWidth - el.clientWidth);

            expect(overflow).toBeLessThanOrEqual(1);

            // ...and it really is narrower than the grid, so span-3 is doing
            // something rather than merely being in the class list.
            const gridBox = await grid.boundingBox();
            const cardBox = await card.boundingBox();

            expect(cardBox.width).toBeLessThan(gridBox.width / 2);
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

            await onlyWidget(page, 'metric-boxes', {
                title: 'Totals',
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

        /**
         * Editing is a thing you do TO the report, so it sits ON the title --
         * an icon, immediately after the words naming what it edits.
         *
         * It was a command bar of its own between the title and the first
         * widget: a second header, above the first widget, saying one thing.
         */
        test('a custom report offers an edit icon on its title', async ({ page }) => {
            await buildOne(page, 'Commanded');

            const edit = page.locator('.owa_reportTitle a[href*="customReportEdit"]');

            await expect(edit).toHaveCount(1);

            // An ICON: a glyph, and no text of its own. The label survives for
            // anyone who cannot see the glyph.
            await expect(edit.locator('i.fa-pencil-alt')).toHaveCount(1);
            await expect(edit).toHaveText('');
            await expect(edit).toHaveAttribute('aria-label', 'Edit report');
            await expect(edit).toHaveAttribute('title', 'Edit report');

            // On the title's line, not floated off to the right past the period
            // picker -- an icon with nothing beside it edits something unstated.
            const title = await page.locator('.owa_reportTitle').boundingBox();
            const icon  = await edit.boundingBox();

            expect(icon.y).toBeGreaterThanOrEqual(title.y - 2);
            expect(icon.y + icon.height).toBeLessThanOrEqual(title.y + title.height + 2);

            // And the command bar it replaces is gone.
            await expect(page.locator('.owa_reportCommands')).toHaveCount(0);

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
