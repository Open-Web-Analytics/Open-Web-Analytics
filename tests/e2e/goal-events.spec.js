const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * Goal events: the screens that replaced the twenty numbered goal slots.
 *
 * The old screens listed twenty rows whether or not anyone had filled them in,
 * because the storage was a fixed-length array and the screen showed the
 * storage. These list what exists -- so "counts nothing yet" is a state worth
 * asserting, being the one the old screens could not represent.
 */

/** Land on an admin screen by its owa_do action. */
async function gotoAction(page, doName, extra = '') {
    await page.goto(`?owa_do=${doName}${extra}`, { waitUntil: 'networkidle' });
}

/**
 * Pick from a chosen widget the way a person does: open it, type, take the
 * first match. The <select> under it is hidden, so selectOption() on it is not
 * what anyone can do.
 */
async function choose(scope, selectSelector, text) {
    const box = scope.locator(selectSelector).locator('xpath=following-sibling::div[contains(@class,"chosen-container")][1]');
    await box.locator('.chosen-single').click();
    await box.locator('.chosen-search input').pressSequentially(text);
    await box.locator('.chosen-results li.active-result').first().click();
}

/** The property names a condition's picker offers, in order. */
async function offered(row) {
    return row.locator('select.owa_goalProperty option').evaluateAll((o) => o.map((x) => x.value));
}

/** Click something destructive and confirm it through the modal. */
async function confirmAndWait(page, locator) {
    await locator.click();
    await expect(page.locator('#owa_confirmDialog')).toBeVisible();

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.locator('.owa_confirmProceed').click(),
    ]);
}

test.describe('goal events', () => {

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('the list, the create, the edit and the delete', async ({ page }) => {
        const name = 'E2E Signup ' + Date.now();
        const renamed = name + ' renamed';

        await gotoAction(page, 'base.goalEvents', `&owa_siteId=${FIXTURE.siteId}`);

        // Every hierarchy screen says what it is for.
        await expect(page.locator('.owa_panelIntro')).toBeVisible();

        // --- CREATE ------------------------------------------------------------
        await gotoAction(page, 'base.goalEventEdit', `&owa_siteId=${FIXTURE.siteId}`);

        // The condition is a constraint row -- the same markup and class names
        // the report builder uses. That is what makes naming a condition look
        // the same wherever it is done.
        //
        // Scoped to the condition list: the FUNNEL rows are constraint rows too,
        // which is the point, so an unscoped locator matches both and resolves
        // to two elements.
        const condition = page.locator('.owa_goalEventCondition li.constraintRow');

        await expect(condition.locator('.constraintDimensionPicker')).toBeVisible();
        await expect(condition.locator('.constraintOperatorPicker')).toBeVisible();

        // Conditions are a LIST: "a purchase over 50 from the pricing page" is
        // two conditions, and one triple could not express it.
        await page.locator('.owa_goalEventCondition .constraintAddButton').first().click();
        await expect(condition).toHaveCount(2);

        await page.locator('.owa_goalEventCondition .constraintRemoveButton').first().click();
        await expect(condition).toHaveCount(1);

        // How several combine is asked once, about the set.
        await expect(page.locator('select[name="conditionMatch"]')).toBeVisible();

        await page.fill('input[name="name"]', name);
        await choose(condition, 'select.owa_goalProperty', 'Page path');
        await expect(page.locator('select[name="conditionProperty[]"]')).toHaveValue('page_path');
        await page.selectOption('select[name="conditionOperator[]"]', 'begins');
        await page.fill('input[name="conditionValue[]"]', '/thanks');
        await page.fill('input[name="value"]', '2.50');

        // A new goal event defaults to Active. Defaulting to Inactive means
        // someone saves what they just described and it silently never fires.
        await expect(page.locator('select[name="isActive"]')).toHaveValue('1');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event"]').click(),
        ]);

        const row = page.locator('table.management tbody tr', { hasText: name });
        await expect(row).toHaveCount(1);

        // The condition shows, and the value survived the trip through cents.
        await expect(row).toContainText('/thanks');
        await expect(row).toContainText('2.50');
        await expect(row).toContainText('Active');

        // --- EDIT --------------------------------------------------------------
        const editHref = await row.locator('a[href*="base.goalEventEdit"]').first()
            .getAttribute('href');
        const params = new URL(editHref, page.url()).searchParams;
        const id = params.get('owa_goalEventId') || params.get('goalEventId');

        expect(id, 'the list must expose the goal event id').toBeTruthy();

        await gotoAction(page, 'base.goalEventEdit',
            `&owa_siteId=${FIXTURE.siteId}&owa_goalEventId=${id}`);

        // It came back carrying what was saved, not a blank form.
        await expect(page.locator('input[name="name"]')).toHaveValue(name);
        await expect(page.locator('input[name="conditionValue[]"]')).toHaveValue('/thanks');

        await page.fill('input[name="name"]', renamed);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event"]').click(),
        ]);

        await expect(
            page.locator('table.management tbody tr', { hasText: renamed })
        ).toHaveCount(1);

        // Edited, not duplicated: the id is carried through the form, so saving
        // twice has to update one row rather than leave two behind.
        //
        // Counted by NAME rather than as every row on the screen -- the seeded
        // fixture goal event is a real row on this Profile, so "one row" would
        // be asserting the fixture away.
        await expect(
            page.locator('table.management tbody tr', { hasText: name })
        ).toHaveCount(1);

        // --- DELETE ------------------------------------------------------------
        await gotoAction(page, 'base.goalEventEdit',
            `&owa_siteId=${FIXTURE.siteId}&owa_goalEventId=${id}`);

        await confirmAndWait(page, page.locator('input[value="Delete Goal Event"]'));

        await expect(
            page.locator('table.management tbody tr', { hasText: renamed })
        ).toHaveCount(0);
    });

    /**
     * THE PROPERTIES FOLLOW THE EVENT. The list was rendered for the saved event
     * and nothing changed it, so choosing "click" still offered a page view's
     * properties until the form was saved and reloaded -- which is how a goal
     * gets written that can never fire.
     */
    test('choosing the event changes the properties offered', async ({ page }) => {
        await gotoAction(page, 'base.goalEventEdit', `&owa_siteId=${FIXTURE.siteId}`);

        const row = page.locator('.owa_goalCondition').first();

        // One sentence: the event opens the condition builder.
        await expect(page.locator('.owa_goalSentence select.owa_goalTrigger')).toHaveCount(1);

        let names = await offered(row);
        expect(names).toContain('tagged_source');
        expect(names).not.toContain('is_outbound');

        await choose(page, 'select.owa_goalTrigger', 'click');
        await expect(page.locator('select.owa_goalTrigger')).toHaveValue('click');

        names = await offered(row);
        expect(names, 'a click carries its target').toContain('is_outbound');
        expect(names, 'and not the landing URL\'s campaign tags').not.toContain('tagged_source');
    });

    /**
     * A property picked on this page and not saved gives way: switching the
     * event moves it to the new event's list, with no warning, because nothing
     * stored would be lost.
     */
    test('an unsaved property the new event does not carry gives way quietly', async ({ page }) => {
        await gotoAction(page, 'base.goalEventEdit', `&owa_siteId=${FIXTURE.siteId}`);

        const row = page.locator('.owa_goalCondition').first();

        await choose(row, 'select.owa_goalProperty', 'Source (from the URL)');
        await expect(row.locator('select.owa_goalProperty')).toHaveValue('tagged_source');

        await choose(page, 'select.owa_goalTrigger', 'click');

        await expect(row.locator('select.owa_goalProperty')).not.toHaveValue('tagged_source');
        await expect(row.locator('.chosen-single')).not.toContainText('not carried');
        await expect(row.locator('.owa_goalConditionHelp')).not.toHaveClass(/owa_goalConditionWarning/);
        expect(await offered(row)).not.toContain('tagged_source');
    });

    /**
     * A SAVED condition's property stays when the event changes to one that
     * does not carry it, and says so. Dropping it would rewrite a stored
     * condition to whatever came first in the list.
     */
    test('a saved property the new event does not carry is kept and flagged', async ({ page }) => {
        const name = 'E2E Tagged ' + Date.now();

        await gotoAction(page, 'base.goalEventEdit', `&owa_siteId=${FIXTURE.siteId}`);
        await page.fill('input[name="name"]', name);

        const fresh = page.locator('.owa_goalCondition').first();
        await choose(fresh, 'select.owa_goalProperty', 'Source (from the URL)');
        await fresh.locator('input.constraintValueField').fill('newsletter');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event"]').click(),
        ]);

        const editHref = await page.locator('table.management tbody tr', { hasText: name })
            .locator('a[href*="base.goalEventEdit"]').first().getAttribute('href');
        const params = new URL(editHref, page.url()).searchParams;
        const id = params.get('owa_goalEventId') || params.get('goalEventId');

        await gotoAction(page, 'base.goalEventEdit',
            `&owa_siteId=${FIXTURE.siteId}&owa_goalEventId=${id}`);

        const row = page.locator('.owa_goalCondition').first();
        await expect(row).toHaveAttribute('data-saved-property', 'tagged_source');

        await choose(page, 'select.owa_goalTrigger', 'click');

        await expect(row.locator('select.owa_goalProperty')).toHaveValue('tagged_source');
        await expect(row.locator('.chosen-single')).toContainText('not carried by click');
        await expect(row.locator('.owa_goalConditionHelp')).toContainText('does not carry this');
        await expect(row.locator('.owa_goalConditionHelp')).toHaveClass(/owa_goalConditionWarning/);

        // Back to an event that carries it: an ordinary condition again.
        await choose(page, 'select.owa_goalTrigger', 'page_view');
        await expect(row.locator('select.owa_goalProperty')).toHaveValue('tagged_source');
        await expect(row.locator('.owa_goalConditionHelp')).not.toHaveClass(/owa_goalConditionWarning/);

        await confirmAndWait(page, page.locator('input[value="Delete Goal Event"]'));
    });

    /**
     * Each property says what it holds: in the list, where it is searched, and
     * under the row once chosen -- where the picker itself shows only the name.
     */
    test('the picker describes what it offers', async ({ page }) => {
        await gotoAction(page, 'base.goalEventEdit', `&owa_siteId=${FIXTURE.siteId}`);
        await choose(page, 'select.owa_goalTrigger', 'click');

        const row = page.locator('.owa_goalCondition').first();
        const box = row.locator('.chosen-container');

        // Open, each item is two lines: the name, then the description.
        await box.locator('.chosen-single').click();
        const first = box.locator('.chosen-results li.active-result').first();
        await expect(first.locator('.owa_goalOptionName')).toHaveCount(1);
        await expect(first.locator('.owa_goalOptionDescription')).not.toHaveText('');

        // Searching the DESCRIPTION finds it: no label says "other than the page".
        // chosen redraws the list as it filters, and the split survives that.
        await box.locator('.chosen-search input').pressSequentially('other than the page');
        const hit = box.locator('.chosen-results li.active-result');
        await expect(hit).toHaveCount(1);
        await expect(hit.locator('.owa_goalOptionName')).toHaveText('Outbound click');
        await expect(hit.locator('.owa_goalOptionDescription em')).toHaveText('other than the page');
        await hit.click();

        await expect(row.locator('.chosen-single')).toHaveText('Outbound click');
        await expect(row.locator('.owa_goalConditionHelp'))
            .toHaveText('Whether the click went to a host other than the page it was on.');

        // Every option carries one.
        const blank = await row.locator('select.owa_goalProperty option')
            .evaluateAll((o) => o.filter((x) => !x.dataset.description).map((x) => x.value));
        expect(blank, 'properties offered with no description').toEqual([]);
    });

    /** An added row gets a picker of its own, not a copy bound to the first. */
    test('an added condition has its own picker', async ({ page }) => {
        await gotoAction(page, 'base.goalEventEdit', `&owa_siteId=${FIXTURE.siteId}`);
        await choose(page, 'select.owa_goalTrigger', 'click');

        const rows = page.locator('.owa_goalCondition');
        await choose(rows.first(), 'select.owa_goalProperty', 'Outbound click');

        await rows.first().locator('.constraintAddButton').click();
        await expect(rows).toHaveCount(2);
        await expect(rows.nth(1).locator('.chosen-container')).toHaveCount(1);

        await choose(rows.nth(1), 'select.owa_goalProperty', 'Target host');

        await expect(rows.nth(1).locator('select.owa_goalProperty')).toHaveValue('target_host');
        await expect(rows.first().locator('select.owa_goalProperty')).toHaveValue('is_outbound');
        await expect(rows.first().locator('.chosen-single')).toHaveText('Outbound click');
    });

    /**
     * A condition with nothing to compare against counts nothing, and says
     * nothing about it. This install had a goal in exactly that state -- a type
     * the evaluator has no case for and no URL -- silently never firing since
     * it was made.
     */
    test('a condition with no value is refused', async ({ page }) => {
        await gotoAction(page, 'base.goalEventEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E No Condition');
        await page.fill('input[name="conditionValue[]"]', '');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event"]').click(),
        ]);

        // Still on the form, carrying what was typed rather than a blank one.
        await expect(page.locator('input[name="name"]')).toHaveValue('E2E No Condition');
    });

});
