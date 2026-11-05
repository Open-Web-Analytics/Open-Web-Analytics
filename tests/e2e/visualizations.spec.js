const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * Visualizations: the fork for things that COMPUTE rather than configure.
 *
 * A custom report arranges metrics against dimensions and one of the widget
 * types draws it. A funnel counts ordered stages over the event stream, which
 * no arrangement of metrics and dimensions expresses -- which is exactly why
 * goal-funnel kept a controller when 62 of 64 reports became JSON.
 *
 * They share a table, every screen around it, and one roster, where the Type
 * column tells them apart.
 */

async function gotoAction(page, doName, extra = '') {
    await page.goto(`?owa_do=${doName}${extra}`, { waitUntil: 'networkidle' });
}

/*
 * A step is an event and conditions on it. A new one is a page_view with a
 * page_path condition, so typing a page is typing into its first condition's
 * value. The blank step Add a step copies sits in a <template>, which no
 * locator reaches.
 */
const stepRows = (page) => page.locator('#owa_funnelSteps > .owa_funnelStep');
const pathField = (page, i) => stepRows(page).nth(i).locator('.owa_funnelConditionValue').first();
const pathFields = (page) => page.locator('#owa_funnelSteps > .owa_funnelStep .owa_funnelConditionValue');

test.describe('visualizations', () => {

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('build one, render it, and find it under Custom Reports', async ({ page }) => {
        const name = 'E2E Funnel ' + Date.now();

        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        /*
         * The kind was answered before this screen opened, so it is not asked
         * again -- and it is posted, so an unchanged edit cannot quietly fall
         * back to the default and change what computes the row.
         */
        await expect(page.locator('input[type="hidden"][name="visualizationType"]'))
            .toHaveValue('funnel');

        await page.fill('input[name="name"]', name);
        await pathField(page, 0).fill('/docs');

        await page.click('#owa_funnelAddStep');
        await expect(pathField(page, 1)).toBeFocused();
        await pathField(page, 1).fill('/thanks');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('#owa_visualizationSubmit'),
        ]);

        /*
         * Straight to the visualization, like a saved report goes to the report
         * -- the author's next question is whether it looks right.
         *
         * This is also what caught two real bugs: the definition was stored as
         * the string "Array" because the column holds JSON, and the controller
         * extended ReportController instead of AdminController, so the redirect
         * carried nothing and rendered an empty document. Both looked like a
         * blank page and neither failed a PHP lint.
         */
        expect(page.url()).toContain('reportId=custom-');

        // It computed: both steps drawn, in order, from its own definition,
        // each named for its page.
        await expect(page.locator('body')).toContainText(name);
        await expect(page.locator('body')).toContainText('/docs');
        await expect(page.locator('body')).toContainText('/thanks');

        // Under Custom Reports: the section is current on a funnel too.
        await expect(page.locator('.owa_rail .owa_railItem.is-current')).toHaveText('Custom Reports');
        await expect(
            page.locator('.owa_rail').getByRole('link', { name: 'Visualizations', exact: true })
        ).toHaveCount(0);

        // --- the one roster lists it, as a Funnel -------------------------------
        await gotoAction(page, 'base.customReports', `&owa_siteId=${FIXTURE.siteId}`);

        const row = page.locator('table.owa_customReportRoster tbody tr', { hasText: name });
        await expect(row).toHaveCount(1);
        await expect(row.locator('td.owa_rosterType')).toHaveText('Funnel');

        /*
         * And no edit link in the row. Editing is the pencil on the thing
         * itself, which is where it acts on something the reader is looking at.
         */
        await expect(row.locator('a[href*="visualizationEdit"]')).toHaveCount(0);

        // The old roster's address opens the same list.
        await gotoAction(page, 'base.visualizations', `&owa_siteId=${FIXTURE.siteId}`);
        await expect(
            page.locator('table.owa_customReportRoster tbody tr', { hasText: name })
        ).toHaveCount(1);
    });

    /**
     * WHAT TO MAKE, before a builder opens: + New asks Report or Funnel, in a
     * modal, because the kind decides what the builder then asks for.
     */
    test('the New button asks what to make, in a modal', async ({ page }) => {
        await gotoAction(page, 'base.customReports', `&owa_siteId=${FIXTURE.siteId}`);

        const dialog = page.locator('#customReportNewDialog');

        // Behind the button: it is not on the page until asked for.
        await expect(dialog).toBeHidden();

        await page.locator('.owa_titleActions .owa_newCustomReport').click();
        await expect(dialog).toBeVisible();
        await expect(page.locator('.owa_typeDialogFrame .ui-dialog-title'))
            .toHaveText('What do you want to make?');

        const tiles = dialog.locator('.owa_typeChoice');
        await expect(tiles).toHaveCount(2);
        await expect(tiles.nth(0)).toContainText('Report');
        await expect(tiles.nth(1)).toContainText('Funnel');
        await expect(tiles.nth(0)).toHaveAttribute('href', /do=base\.customReportEdit/);

        /*
         * Still real links. With no JavaScript the modal never opens and New
         * opens the report builder directly, so picking a kind has to be a
         * navigation rather than something only script can do.
         */
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            tiles.nth(1).click(),
        ]);

        expect(page.url()).toContain('visualizationType=funnel');
        await expect(pathField(page, 0)).toBeVisible();
    });

    /**
     * A funnel is nothing but its steps, so one with none describes no path.
     *
     * Stopped in the form now: Save is unavailable until the rules are met, so
     * the button cannot post this. The server still refuses it -- the second
     * half below posts past the button the way a stale tab would -- and keeps
     * what was typed.
     */
    test('a visualization with no steps cannot be saved', async ({ page }) => {
        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E Empty Viz');

        await expect(page.locator('#owa_visualizationSubmit')).toBeDisabled();
        await expect(page.locator('#owa_visualizationBlocker')).toContainText('at least one step');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.evaluate(() => HTMLFormElement.prototype.submit.call(
                document.forms.owa_visualization)),
        ]);

        await expect(page.locator('input[name="name"]')).toHaveValue('E2E Empty Viz');
        await expect(page.locator('.validation_error').filter({ hasText: 'at least one step' }))
            .toHaveCount(1);
    });

    /**
     * A page step's path is a PATH. page_path holds no scheme or host, so a
     * full web address matches nothing and every stage reports zero. Refused
     * rather than silently trimmed.
     */
    test('a step given a full web address is refused', async ({ page }) => {
        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E Bad Step');
        await pathField(page, 0).fill('https://example.test/basket');

        // Caught in the form, on the same rule the save applies.
        await expect(page.locator('#owa_visualizationSubmit')).toBeDisabled();
        await expect(page.locator('#owa_visualizationBlocker'))
            .toContainText('not a full web address');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.evaluate(() => HTMLFormElement.prototype.submit.call(
                document.forms.owa_visualization)),
        ]);

        await expect(page.locator('input[name="name"]')).toHaveValue('E2E Bad Step');
        await expect(page.locator('.validation_error').filter({ hasText: 'not a full URL' }))
            .toHaveCount(1);
    });

    /**
     * AN ERROR ABOUT STEP 2 WAS WRITTEN AND NEVER SHOWN.
     *
     * The template rendered three hardcoded keys and validate() writes one per
     * step number, so a problem with any step but the first refused the save
     * and then appeared nowhere. Driven past the button, because the form now
     * stops this before it is posted; what is under test is the server's
     * answer and where it is shown.
     */
    test('an error about a later step is shown, not swallowed', async ({ page }) => {
        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E Second Step');
        await pathField(page, 0).fill('/one');

        await page.click('#owa_funnelAddStep');
        await pathField(page, 1).fill('https://example.test/two');

        await expect(page.locator('#owa_visualizationSubmit')).toBeDisabled();
        await expect(page.locator('#owa_visualizationBlocker')).toContainText('Step 2');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.evaluate(() => HTMLFormElement.prototype.submit.call(
                document.forms.owa_visualization)),
        ]);

        // The message is on the step it is about, and the work is still there.
        const second = stepRows(page).nth(1);

        await expect(second.locator('.validation_error')).toContainText('Step 2: enter a path');
        await expect(second.locator('.owa_funnelConditionValue').first()).toHaveValue('https://example.test/two');
    });

    /**
     * Save waits for the same rules the server applies, and says which one.
     *
     * The form used to offer Save from the moment it opened, so the first
     * answer to an empty funnel was a round trip.
     */
    test('save waits for a name and a complete step', async ({ page }) => {
        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        // Name and Steps both say they are required, rather than the author
        // finding out on save.
        await expect(page.locator('.owa_builderRequired')).toHaveCount(2);

        await expect(page.locator('#owa_visualizationSubmit')).toBeDisabled();
        await expect(page.locator('#owa_visualizationBlocker')).toContainText('name');

        await page.fill('input[name="name"]', 'E2E Gate ' + Date.now());
        await expect(page.locator('#owa_visualizationBlocker')).toContainText('at least one step');

        await pathField(page, 0).fill('/basket');

        await expect(page.locator('#owa_visualizationSubmit')).toBeEnabled();
        await expect(page.locator('#owa_visualizationBlocker')).toBeHidden();
    });

    /**
     * A STEP IS AN EVENT AND ITS CONDITIONS, written in the step.
     *
     * The fixture funnel's last step is the page /docs. Written here as a
     * page_view whose page path matches the regex ^/docs$, it is the same
     * condition said another way, so the two funnels count the same.
     *
     * Built here rather than seeded, because that also proves the builder
     * stores an event step with its operator in a shape the counting reads.
     */
    test('an event step counts what its conditions count', async ({ page }) => {
        const name = 'E2E Event Step Funnel ' + Date.now();

        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', name);

        // There is no Page/Event switch: every step is an event, a page_view to start.
        await expect(page.locator('select[name="stepKind[]"]')).toHaveCount(0);
        await expect(stepRows(page).first().locator('.owa_funnelStepEvent')).toHaveValue('page_view');

        await pathField(page, 0).fill('/');

        await page.click('#owa_funnelAddStep');
        await pathField(page, 1).fill('/pricing');

        await page.click('#owa_funnelAddStep');

        const third = stepRows(page).nth(2);

        await third.locator('.owa_funnelConditionOperator').first().selectOption('regex');
        await third.locator('.owa_funnelConditionValue').first().fill('^/docs$');

        // Each step's conditions are named by its position, for the save to tell them apart.
        await expect(third.locator('.owa_funnelConditionValue').first())
            .toHaveAttribute('name', /stepConditionValue\[2\]\[\]$/);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('#owa_visualizationSubmit'),
        ]);

        await page.waitForSelector('.owa_funnelChart', { timeout: 20_000 });

        const mine = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));

        expect(mine).toHaveLength(3);
        expect(mine[2], 'the event step counted nobody').toBeGreaterThan(0);

        // The chart says what the step matches.
        await expect(page.locator('.owa_funnelStepColumn').nth(2).locator('.funnelStepPath'))
            .toHaveText('page_view where Page path matches regex ^/docs$');

        // --- and it is the SAME funnel as the page version ---------------------
        await gotoAction(page, 'base.customReports', `&owa_siteId=${FIXTURE.siteId}`);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('table.owa_customReportRoster tbody tr', { hasText: FIXTURE.funnelVisualization.name })
                .locator('td.owa_rosterName a').click(),
        ]);

        await page.waitForSelector('.owa_funnelChart', { timeout: 20_000 });

        const byPage = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));

        expect(mine, 'the regex step counts something different from the page it says').toEqual(byPage);
    });

    /**
     * A condition the save refuses is said on the step it is about, and the
     * step comes back as it was typed. A regex that does not compile would
     * match nothing, for ever; the form does not check it, the save does.
     */
    test('a condition the save refuses is shown on its step', async ({ page }) => {
        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E Refused Step');

        const row = stepRows(page).first();

        await row.locator('.owa_funnelConditionOperator').first().selectOption('regex');
        await row.locator('.owa_funnelConditionValue').first().fill('([');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('#owa_visualizationSubmit'),
        ]);

        await expect(page.locator('input[name="name"]')).toHaveValue('E2E Refused Step');
        await expect(stepRows(page).first().locator('.validation_error'))
            .toContainText('Step 1: the regular expression is not valid.');
        await expect(stepRows(page).first().locator('.owa_funnelConditionOperator').first()).toHaveValue('regex');
        await expect(pathField(page, 0)).toHaveValue('([');
    });

    /**
     * The builder's own screen: no report title, the name is the header with
     * Shared and Cancel / Save beside it, and Cancel goes back to the list.
     */
    test('the funnel builder header holds the name, Shared, Cancel and Save', async ({ page }) => {
        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await expect(page.locator('.owa_reportHead')).toHaveCount(0);

        const head = page.locator('.owa_editorHead');

        await expect(head.locator('label[for="owa_funnelName"]')).toHaveText('Funnel name');
        await expect(head.locator('#owa_funnelShared')).toHaveAttribute('role', 'switch');
        await expect(head.locator('#owa_visualizationSubmit')).toHaveValue('Save funnel');
        expect(await head.locator('#owa_funnelCancel').getAttribute('href')).toContain('customReports');
    });

    /**
     * THE PREVIEW: the funnel counted as it stands, from the steps on the
     * screen, before it is saved -- the same counting the saved funnel does,
     * so the fixture funnel's steps preview as it draws.
     */
    test('the preview counts the steps on the screen, and follows them', async ({ page }) => {
        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        const preview = page.locator('#owa_funnelPreview');

        await expect(preview).toContainText('Add a step');

        const steps = FIXTURE.funnelVisualization.steps;

        for (let i = 0; i < steps.length; i++) {
            if (i > 0) {
                await page.click('#owa_funnelAddStep');
            }
            await pathField(page, i).fill(steps[i].path);
        }

        const rows = preview.locator('.owa_funnelPreviewStep');

        await expect(rows).toHaveCount(steps.length, { timeout: 20_000 });

        // Named for their pages, since no name was given.
        await expect(rows.locator('.owa_funnelPreviewName'))
            .toHaveText(steps.map((s, i) => `${i + 1} ${s.path}`));

        const counts = async () => (await rows.locator('.owa_funnelPreviewCount b').allInnerTexts())
            .map((t) => parseInt(t.replace(/,/g, ''), 10));

        const before = await counts();

        expect(before[0]).toBeGreaterThan(0);
        await expect(rows.nth(1)).toContainText('continued');
        await expect(preview.locator('.owa_funnelPreviewSummary')).toContainText('of sessions that reached /');

        // The first bar is the whole; the rest are against it.
        await expect(rows.first().locator('.owa_funnelPreviewFill')).toHaveAttribute('style', /width: 100(\.0)?%/);

        // A step that matches nothing counts nobody, and the preview follows.
        await pathFields(page).last().fill('/no-such-page');

        await expect.poll(async () => (await counts()).slice(-1)[0], { timeout: 20_000 }).toBe(0);
    });

    /**
     * Steps move by dragging a handle or with the arrow keys, and the saved
     * order is the order on the screen. A step keeps its stored name while it
     * is unchanged, and is named for its page once it is changed.
     */
    test('steps move, and keep their names until they change', async ({ page }) => {
        // Tall enough for all three steps: Playwright scrolls the drop target
        // into view between pressing the handle and releasing it, and a native
        // drag does not survive that scroll.
        await page.setViewportSize({ width: 1280, height: 1400 });

        await gotoAction(page, 'base.customReports', `&owa_siteId=${FIXTURE.siteId}`);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('table.owa_customReportRoster tbody tr', { hasText: FIXTURE.funnelVisualization.name })
                .locator('td.owa_rosterName a').click(),
        ]);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('.owa_reportTitle a[href*="visualizationEdit"]').click(),
        ]);

        // A copy, so the fixture funnel itself is not changed.
        const name = 'E2E Moved Funnel ' + Date.now();
        await page.fill('input[name="name"]', name);
        await page.evaluate(() => {
            document.querySelector('input[name="visualizationId"]').value = '';
        });

        const paths = () => pathFields(page).evaluateAll((els) => els.map((e) => e.value));
        const names = () => page.locator('input[name="stepName[]"]').evaluateAll((els) => els.map((e) => e.value));

        const fixture = FIXTURE.funnelVisualization.steps;

        expect(await paths()).toEqual(fixture.map((s) => s.path));
        expect(await names()).toEqual(fixture.map((s) => s.name));

        // The last step dragged onto the first takes its place...
        const handle = (i) => stepRows(page).nth(i).locator('.owa_builderMove');

        await handle(2).dragTo(stepRows(page).nth(0));
        expect(await paths()).toEqual([fixture[2].path, fixture[0].path, fixture[1].path]);

        // ...and the arrow keys move it back.
        await handle(0).focus();
        await page.keyboard.press('ArrowDown');
        await page.keyboard.press('ArrowDown');
        expect(await paths()).toEqual(fixture.map((s) => s.path));
        await expect(handle(2)).toBeFocused();
        await expect(page.locator('.owa_funnelStepNumber')).toHaveText(['1', '2', '3']);

        // Changing a step drops its stored name.
        await pathField(page, 1).fill('/pricing/plans');
        expect((await names())[1]).toBe('');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('#owa_visualizationSubmit'),
        ]);

        await page.waitForSelector('.owa_funnelChart', { timeout: 20_000 });

        await expect(page.locator('body')).toContainText(fixture[0].name);
        await expect(page.locator('body')).toContainText('/pricing/plans');
        await expect(page.locator('body')).not.toContainText(fixture[1].name);
    });

    /**
     * A FUNNEL CAN CARRY ITS OWN FILTER, which applies to every step and is
     * said on the funnel. Built with the same pills as a widget's filter.
     */
    test("a funnel's filter is saved, counted and stated", async ({ page }) => {
        const name = 'E2E Filtered Funnel ' + Date.now();

        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', name);
        await pathField(page, 0).fill('/');

        await page.click('#owa_funnelAddFilter');

        const chosen = page.locator('#owa_funnelFilterDimension_chosen');

        await chosen.locator('.chosen-single').click();
        await chosen.locator('.chosen-search input').pressSequentially('pagePath');
        await chosen.locator('.chosen-results li.active-result', { hasText: '(pagePath)' }).first().click();
        await page.selectOption('#owa_funnelFilterOperator', { label: 'contains' });
        await page.fill('#owa_funnelFilterValue', '/');

        // Save waits while the filter is half done.
        await expect(page.locator('#owa_visualizationSubmit')).toBeDisabled();
        await expect(page.locator('#owa_visualizationBlocker')).toContainText('Add the filter');

        await page.click('#owa_funnelFilterAdd');

        await expect(page.locator('#owa_funnelFilters .owa_filterPill')).toContainText('contains');
        await expect(page.locator('#owa_funnelConstraints')).toHaveValue('pagePath=@/');
        await expect(page.locator('#owa_visualizationSubmit')).toBeEnabled();

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('#owa_visualizationSubmit'),
        ]);

        await page.waitForSelector('.owa_funnelChart', { timeout: 20_000 });

        await expect(page.locator('.owa_funnelSavedFilter')).toContainText('contains /');

        const counts = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));

        expect(counts[0], 'every session viewed a page, so the filter keeps them').toBeGreaterThan(0);

        // ...and it comes back as a pill.
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('.owa_reportTitle a[href*="visualizationEdit"]').click(),
        ]);

        await expect(page.locator('#owa_funnelFilters .owa_filterPill')).toHaveCount(1);
    });
});
