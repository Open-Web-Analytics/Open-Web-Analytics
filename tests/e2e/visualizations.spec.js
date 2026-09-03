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
 * They share a table and every screen around it, because ownership, access
 * control, editable titles and delete are identical. They are listed separately
 * because the controls are not.
 */

async function gotoAction(page, doName, extra = '') {
    await page.goto(`?owa_do=${doName}${extra}`, { waitUntil: 'networkidle' });
}

test.describe('visualizations', () => {

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('build one, render it, and find it in its own nav group', async ({ page }) => {
        const name = 'E2E Funnel ' + Date.now();

        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        /*
         * The kind was answered before this screen opened, so it is STATED
         * here, not asked again -- and it is posted, so an unchanged edit
         * cannot quietly fall back to the default and change what computes the
         * row.
         */
        await expect(page.locator('.owa_statedValue')).toContainText('Funnel');
        await expect(page.locator('input[type="hidden"][name="visualizationType"]'))
            .toHaveValue('funnel');

        await page.fill('input[name="name"]', name);
        await page.locator('input[name="stepName[]"]').first().fill('Docs');
        await page.locator('input[name="stepPath[]"]').first().fill('/docs');

        // Steps are a repeatable list, sharing the report builder's row markup.
        await page.locator('#owa_goalEventFunnel .constraintAddButton').first().click();
        await page.locator('input[name="stepName[]"]').nth(1).fill('Thanks');
        await page.locator('input[name="stepPath[]"]').nth(1).fill('/thanks');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Visualization"]').click(),
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

        // It computed: both steps drawn, in order, from its own definition.
        await expect(page.locator('body')).toContainText(name);
        await expect(page.locator('body')).toContainText('Docs');
        await expect(page.locator('body')).toContainText('Thanks');

        // Its own nav group, NOT under Custom Reports. The group is a topmenu
        // item whose subgroup holds the links.
        const vizGroup = page.locator('.owa_admin_nav_topmenu', { hasText: 'Visualizations' });
        await expect(vizGroup).toHaveCount(1);
        await expect(vizGroup).toContainText(name);

        // And it is a DIFFERENT group from Custom Reports, which is the whole
        // point of the separation.
        await expect(
            page.locator('.owa_admin_nav_topmenu', { hasText: 'Custom Reports' })
        ).toHaveCount(1);

        // --- the roster lists it, and nothing else does ------------------------
        await gotoAction(page, 'base.visualizations', `&owa_siteId=${FIXTURE.siteId}`);

        const row = page.locator('table.management tbody tr', { hasText: name });
        await expect(row).toHaveCount(1);

        /*
         * And no edit link in the row. Editing is the pencil on the thing
         * itself, which is where it acts on something the reader is looking at;
         * a second way in from the roster made every row carry two links to two
         * screens and an empty cell for anyone who may not edit.
         */
        await expect(row.locator('a[href*="visualizationEdit"]')).toHaveCount(0);

        await gotoAction(page, 'base.customReports', `&owa_siteId=${FIXTURE.siteId}`);
        await expect(
            page.locator('table.management tbody tr', { hasText: name }),
            'a visualization appeared in the Custom Reports roster'
        ).toHaveCount(0);
    });

    /**
     * WHICH KIND, before the builder opens.
     *
     * The same question in the same shape as the widget builder's type chooser,
     * because the kind decides what the builder then asks for. There is only a
     * funnel today and it is still asked: a single hardcoded kind would not
     * need naming, and asking is what makes the second one cost a row in
     * VISUALIZATION_TYPES rather than a new screen.
     */
    test('the New button asks which kind, in a modal', async ({ page }) => {
        await gotoAction(page, 'base.visualizations', `&owa_siteId=${FIXTURE.siteId}`);

        const dialog = page.locator('#visualizationTypeDialog');

        // Behind the button: it is not on the page until asked for.
        await expect(dialog).toBeHidden();

        await page.locator('.owa_newVisualization').first().click();
        await expect(dialog).toBeVisible();

        // One kind, and it is the funnel.
        const tiles = dialog.locator('.owa_typeChoice');
        await expect(tiles).toHaveCount(1);
        await expect(tiles.first()).toContainText('Funnel');

        /*
         * Still a real link. With no JavaScript the modal never opens and the
         * New button opens the builder directly, so picking a kind has to be a
         * navigation rather than something only script can do.
         */
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            tiles.first().click(),
        ]);

        expect(page.url()).toContain('visualizationType=funnel');
        await expect(page.locator('input[name="stepName[]"]').first()).toBeVisible();
    });

    /** A funnel is nothing but its steps, so one with none describes no path. */
    test('a visualization with no steps is refused', async ({ page }) => {
        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E Empty Viz');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Visualization"]').click(),
        ]);

        await expect(page.locator('input[name="name"]')).toHaveValue('E2E Empty Viz');
    });

    /**
     * A step is a PATH. The counting matches on the path alone, so a full web
     * address matches nothing and every stage reports zero. Refused rather than
     * silently trimmed.
     */
    test('a step given a full web address is refused', async ({ page }) => {
        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E Bad Step');
        await page.locator('input[name="stepName[]"]').first().fill('Basket');
        await page.locator('input[name="stepPath[]"]').first().fill('https://example.test/basket');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Visualization"]').click(),
        ]);

        await expect(page.locator('input[name="name"]')).toHaveValue('E2E Bad Step');
    });
});
