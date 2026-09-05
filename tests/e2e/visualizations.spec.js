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

    /**
     * A STEP CAN BE A GOAL EVENT, AND IT MEANS THE SAME THING.
     *
     * A funnel step is a condition; a goal event is a condition somebody named.
     * So naming one in a step has to count exactly what writing its conditions
     * into the step by hand would count -- and the fixture is built so that can
     * be checked rather than asserted: the seeded goal event's condition is
     * page_uri exactly /docs, and the seeded funnel's last step is the path
     * /docs. Two ways of saying one thing.
     *
     * Building the second one HERE rather than seeding it, because that also
     * proves the builder stores a goal-event step in a shape the counting
     * reads -- which is where the last four bugs in this feature were.
     */
    test('a goal event step counts what its conditions count', async ({ page }) => {
        const name = 'E2E Goal Step Funnel ' + Date.now();

        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', name);

        // Two path steps, then a third naming the goal event.
        await page.locator('input[name="stepName[]"]').first().fill('Home');
        await page.locator('input[name="stepPath[]"]').first().fill('/');

        await page.locator('#owa_goalEventFunnel .constraintAddButton').first().click();
        await page.locator('input[name="stepName[]"]').nth(1).fill('Pricing');
        await page.locator('input[name="stepPath[]"]').nth(1).fill('/pricing');

        await page.locator('#owa_goalEventFunnel .constraintRow').nth(1)
            .locator('.constraintAddButton').click();

        const third = page.locator('#owa_goalEventFunnel .constraintRow').nth(2);

        await third.locator('input[name="stepName[]"]').fill('Signed up');
        await third.locator('select[name="stepKind[]"]').selectOption('goal_event');

        // The picker offers this Property's goal events, and the fixture's is
        // one of them.
        const goalPicker = third.locator('select[name="stepGoalEventId[]"]');
        await expect(goalPicker).toBeVisible();
        /*
         * By the id behind the label, not by the label -- selectOption takes a
         * literal label, and the option carries an "(inactive)" suffix when it
         * has one, so a label match is a thing that breaks on a fixture change
         * rather than on a real one.
         */
        const goalValue = await goalPicker.locator('option', { hasText: FIXTURE.goal.name })
            .first().getAttribute('value');

        expect(goalValue, 'the fixture goal event is not offered as a step').toBeTruthy();

        await goalPicker.selectOption(goalValue);

        // The path field is not asked for once the kind is a goal event.
        await expect(third.locator('input[name="stepPath[]"]')).toBeHidden();

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Visualization"]').click(),
        ]);

        await page.waitForSelector('.owa_funnelChart', { timeout: 20_000 });

        const mine = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));

        expect(mine).toHaveLength(3);

        // It computed something, rather than a goal-event step quietly matching
        // nothing -- which is what every wrong version of this looked like.
        expect(mine[2], 'the goal event step counted nobody').toBeGreaterThan(0);

        // --- and it is the SAME funnel as the path version ---------------------
        await gotoAction(page, 'base.visualizations', `&owa_siteId=${FIXTURE.siteId}`);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('table.management tbody tr', { hasText: FIXTURE.funnelVisualization.name })
                .locator('a').first().click(),
        ]);

        await page.waitForSelector('.owa_funnelChart', { timeout: 20_000 });

        const byPath = (await page.locator('.funnelStepCount').allTextContents())
            .map((c) => parseInt(c.trim(), 10));

        expect(mine,
            'naming the goal event counts something different from writing its condition out'
        ).toEqual(byPath);
    });

    /**
     * A goal event the funnel cannot count against is refused AT THE BUILDER.
     *
     * A goal event may test any tracking property; a funnel step is matched
     * against the page, because that is what the funnel's query joins. Told
     * here, the author can choose another; told at render time, they have saved
     * something that refuses to draw every time it is opened.
     *
     * Skipped when the fixture Property has no such goal event -- the fixture's
     * own is on page_uri, which compiles.
     */
    test('a goal event a funnel cannot count is refused when it is chosen', async ({ page }) => {
        await gotoAction(page, 'base.goalEventEdit', `&owa_siteId=${FIXTURE.siteId}`);

        const name = 'E2E Unfunnelable ' + Date.now();

        await page.fill('input[name="name"]', name);

        // A condition on something that is not the page. `medium` is a real
        // tracking property and a real dimension -- it is simply not one the
        // funnel's join can reach.
        const property = page.locator('select[name="conditionProperty[]"]');

        if (await property.locator('option[value="medium"]').count() === 0) {
            test.skip(true, 'this install offers no non-page condition property');
        }

        await property.selectOption('medium');
        await page.fill('input[name="conditionValue[]"]', 'organic-search');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event"]').click(),
        ]);

        // Now try to use it as a funnel step.
        await gotoAction(page, 'base.visualizationEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E Refused Step');

        const row = page.locator('#owa_goalEventFunnel .constraintRow').first();

        await row.locator('input[name="stepName[]"]').fill('Nope');
        await row.locator('select[name="stepKind[]"]').selectOption('goal_event');
        const picker = row.locator('select[name="stepGoalEventId[]"]');
        const value = await picker.locator('option', { hasText: name }).first()
            .getAttribute('value');

        expect(value, 'the goal event just created is not offered as a step').toBeTruthy();

        await picker.selectOption(value);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Visualization"]').click(),
        ]);

        // Back on the form, saying which property is the problem -- not saved
        // and not silently counting the conditions it COULD express, which
        // would report a wider number than the goal event means.
        await expect(page.locator('input[name="name"]')).toHaveValue('E2E Refused Step');
        await expect(page.locator('body')).toContainText('medium');
    });
});
