const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');
const { fieldChooser } = require('./field-chooser.js');

/**
 * Goal event definitions: the screens. They list what exists, so "counts nothing
 * yet" is a state worth asserting.
 */

/** Land on an admin screen by its owa_do action. */
async function gotoAction(page, doName, extra = '') {
    await page.goto(`?owa_do=${doName}${extra}`, { waitUntil: 'networkidle' });
}

/**
 * Pick an event the way a person does: the button after its <select> opens
 * the field chooser, which is searched and the name clicked. The <select> is
 * hidden, so selectOption() on it is not what anyone can do.
 */
async function choose(scope, selectSelector, text) {
    await scope.locator(selectSelector).locator('xpath=following-sibling::button[1]').click();

    const chooser = fieldChooser(scope.page ? scope.page() : scope);

    await chooser.getByRole('combobox').pressSequentially(text);
    await chooser.getByRole('option', { name: text }).first().click();
    await expect(chooser).toHaveCount(0);
}

/**
 * Pick a condition's property the way a person does: its button opens the
 * field chooser, which is searched and the label clicked.
 */
async function chooseProperty(row, label) {
    await row.locator('.owa_goalPropertyButton').click();

    const chooser = fieldChooser(row.page());

    await chooser.getByRole('combobox').pressSequentially(label);
    await chooser.getByRole('option', { name: label, exact: true }).click();
    await expect(chooser).toHaveCount(0);
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

test.describe('goal event definitions', () => {

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('the list, the create, the edit and the delete', async ({ page }) => {
        const name = 'E2E_Signup_' + Date.now();

        await gotoAction(page, 'base.goalEventDefinitions', `&owa_siteId=${FIXTURE.siteId}`);

        // Every hierarchy screen says what it is for.
        await expect(page.locator('.owa_panelIntro')).toBeVisible();

        // --- CREATE ------------------------------------------------------------
        await gotoAction(page, 'base.goalEventDefinitionEdit', `&owa_siteId=${FIXTURE.siteId}`);

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
        await chooseProperty(condition, 'Page path');
        await expect(page.locator('select[name="conditionProperty[]"]')).toHaveValue('page_path');
        await page.selectOption('select[name="conditionOperator[]"]', 'begins');
        await page.fill('input[name="conditionValue[]"]', '/thanks');
        await page.fill('input[name="value"]', '2.50');

        // A new goal event defaults to Active. Defaulting to Inactive means
        // someone saves what they just described and it silently never fires.
        await expect(page.locator('select[name="isActive"]')).toHaveValue('1');

        // A new definition counts once per event unless told otherwise.
        await expect(page.locator('select[name="countMode"]')).toHaveValue('once_per_event');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event Definition"]').click(),
        ]);

        const row = page.locator('table.management tbody tr', { hasText: name });
        await expect(row).toHaveCount(1);

        // The condition shows, and the value survived the trip through cents.
        await expect(row).toContainText('/thanks');
        await expect(row).toContainText('2.50');
        await expect(row).toContainText('Active');
        await expect(row.locator('.owa_goalEventCountMode')).toHaveText('Once per event');

        // --- EDIT --------------------------------------------------------------
        const editHref = await row.locator('a[href*="base.goalEventDefinitionEdit"]').first()
            .getAttribute('href');
        const params = new URL(editHref, page.url()).searchParams;
        const id = params.get('owa_goalEventDefinitionId') || params.get('goalEventDefinitionId');

        expect(id, 'the list must expose the definition id').toBeTruthy();

        await gotoAction(page, 'base.goalEventDefinitionEdit',
            `&owa_siteId=${FIXTURE.siteId}&owa_goalEventDefinitionId=${id}`);

        // It came back carrying what was saved, not a blank form. The name is
        // shown, not offered: its goal events are already stored under it.
        await expect(page.locator('input[name="name"]')).toHaveCount(0);
        await expect(page.locator('form[name="owa_goalEventDefinition"] code', { hasText: name })).toHaveCount(1);
        await expect(page.locator('input[name="conditionValue[]"]')).toHaveValue('/thanks');

        await expect(page.locator('select[name="countMode"]')).toHaveValue('once_per_event');

        await page.fill('input[name="value"]', '3.75');
        await page.selectOption('select[name="countMode"]', 'once_per_session');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event Definition"]').click(),
        ]);

        await expect(
            page.locator('table.management tbody tr', { hasText: name })
        ).toContainText('3.75');
        await expect(
            page.locator('table.management tbody tr', { hasText: name }).locator('.owa_goalEventCountMode')
        ).toHaveText('Once per session');

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
        await gotoAction(page, 'base.goalEventDefinitionEdit',
            `&owa_siteId=${FIXTURE.siteId}&owa_goalEventDefinitionId=${id}`);

        await confirmAndWait(page, page.locator('input[value="Delete Goal Event Definition"]'));

        await expect(
            page.locator('table.management tbody tr', { hasText: name })
        ).toHaveCount(0);
    });

    /** A name is an event name, because each goal event is stored as an event under it. */
    test('a name that is not an event name is refused', async ({ page }) => {
        await gotoAction(page, 'base.goalEventDefinitionEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'Signup completed');
        await page.fill('input[name="conditionValue[]"]', '/thanks');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event Definition"]').click(),
        ]);

        await expect(page.locator('input[name="name"]')).toHaveValue('Signup completed');
        await expect(page.locator('.validation_error', { hasText: 'A name starts with a letter' })).toHaveCount(1);
    });

    /**
     * THE PROPERTIES FOLLOW THE EVENT: choosing "click" offers a click's
     * properties at once, or a goal gets written that can never fire.
     */
    test('choosing the event changes the properties offered', async ({ page }) => {
        await gotoAction(page, 'base.goalEventDefinitionEdit', `&owa_siteId=${FIXTURE.siteId}`);

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
     * A SITE'S OWN EVENT is a trigger too: one its Profiles sent lately, or any
     * other typed beside the picker. It needs no condition, because its name
     * already says what happened.
     */
    test('a goal on an event of the site\'s own needs no condition', async ({ page }) => {
        const stamp = Date.now();
        const name  = 'E2E_Custom_' + stamp;
        const event = 'e2e_signup_' + stamp;

        await gotoAction(page, 'base.goalEventDefinitionEdit', `&owa_siteId=${FIXTURE.siteId}`);

        const custom = page.locator('input.owa_goalCustomTrigger');
        await expect(custom).toBeHidden();

        await choose(page, 'select.owa_goalTrigger', 'Another event');
        await expect(custom).toBeVisible();
        await custom.fill(event);
        await page.fill('input[name="name"]', name);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event Definition"]').click(),
        ]);

        const row = page.locator('table.management tbody tr', { hasText: name });
        await expect(row).toContainText('every ' + event);

        const editHref = await row.locator('a[href*="base.goalEventDefinitionEdit"]').first().getAttribute('href');
        const params = new URL(editHref, page.url()).searchParams;
        const id = params.get('owa_goalEventDefinitionId') || params.get('goalEventDefinitionId');

        // It comes back as a choice of its own, not as "another event".
        await gotoAction(page, 'base.goalEventDefinitionEdit', `&owa_siteId=${FIXTURE.siteId}&owa_goalEventDefinitionId=${id}`);
        await expect(page.locator('select.owa_goalTrigger')).toHaveValue(event);
        await expect(custom).toBeHidden();

        await confirmAndWait(page, page.locator('input[value="Delete Goal Event Definition"]'));
        await expect(page.locator('table.management tbody tr', { hasText: name })).toHaveCount(0);
    });

    /**
     * A property picked on this page and not saved gives way: switching the
     * event moves it to the new event's list, with no warning, because nothing
     * stored would be lost.
     */
    test('an unsaved property the new event does not carry gives way quietly', async ({ page }) => {
        await gotoAction(page, 'base.goalEventDefinitionEdit', `&owa_siteId=${FIXTURE.siteId}`);

        const row = page.locator('.owa_goalCondition').first();

        await chooseProperty(row, 'Source (from the URL)');
        await expect(row.locator('select.owa_goalProperty')).toHaveValue('tagged_source');

        await choose(page, 'select.owa_goalTrigger', 'click');

        await expect(row.locator('select.owa_goalProperty')).not.toHaveValue('tagged_source');
        await expect(row.locator('.owa_goalPropertyButton')).not.toContainText('not carried');
        await expect(row.locator('.owa_goalConditionHelp')).not.toHaveClass(/owa_goalConditionWarning/);
        expect(await offered(row)).not.toContain('tagged_source');
    });

    /**
     * A SAVED condition's property stays when the event changes to one that
     * does not carry it, and says so. Dropping it would rewrite a stored
     * condition to whatever came first in the list.
     */
    test('a saved property the new event does not carry is kept and flagged', async ({ page }) => {
        const name = 'E2E_Tagged_' + Date.now();

        await gotoAction(page, 'base.goalEventDefinitionEdit', `&owa_siteId=${FIXTURE.siteId}`);
        await page.fill('input[name="name"]', name);

        const fresh = page.locator('.owa_goalCondition').first();
        await chooseProperty(fresh, 'Source (from the URL)');
        await fresh.locator('input.constraintValueField').fill('newsletter');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event Definition"]').click(),
        ]);

        const editHref = await page.locator('table.management tbody tr', { hasText: name })
            .locator('a[href*="base.goalEventDefinitionEdit"]').first().getAttribute('href');
        const params = new URL(editHref, page.url()).searchParams;
        const id = params.get('owa_goalEventDefinitionId') || params.get('goalEventDefinitionId');

        await gotoAction(page, 'base.goalEventDefinitionEdit',
            `&owa_siteId=${FIXTURE.siteId}&owa_goalEventDefinitionId=${id}`);

        const row = page.locator('.owa_goalCondition').first();
        await expect(row).toHaveAttribute('data-saved-property', 'tagged_source');

        await choose(page, 'select.owa_goalTrigger', 'click');

        await expect(row.locator('select.owa_goalProperty')).toHaveValue('tagged_source');
        await expect(row.locator('.owa_goalPropertyButton')).toContainText('not carried by click');
        await expect(row.locator('.owa_goalConditionHelp')).toContainText('does not carry this');
        await expect(row.locator('.owa_goalConditionHelp')).toHaveClass(/owa_goalConditionWarning/);

        // Back to an event that carries it: an ordinary condition again.
        await choose(page, 'select.owa_goalTrigger', 'page_view');
        await expect(row.locator('select.owa_goalProperty')).toHaveValue('tagged_source');
        await expect(row.locator('.owa_goalConditionHelp')).not.toHaveClass(/owa_goalConditionWarning/);

        await confirmAndWait(page, page.locator('input[value="Delete Goal Event Definition"]'));
    });

    /**
     * A REFUSED SAVE redraws the form without inventing problems. The redraw
     * came from the save controller, which set no per-event lists, so every
     * property read as "not carried" -- under a valid condition, with the event
     * unchanged. And nothing typed into a new goal event is saved, so nothing on
     * the redrawn form is flagged when the event then changes.
     */
    test('a refused save does not flag a valid property', async ({ page }) => {
        await gotoAction(page, 'base.goalEventDefinitionEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E_Refused_' + Date.now());
        const fresh = page.locator('.owa_goalCondition').first();
        await chooseProperty(fresh, 'Source (from the URL)');

        // No value: refused.
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event Definition"]').click(),
        ]);

        const row = page.locator('.owa_goalCondition').first();
        await expect(row.locator('select.owa_goalProperty')).toHaveValue('tagged_source');
        await expect(row.locator('.owa_goalConditionHelp')).not.toHaveClass(/owa_goalConditionWarning/);
        await expect(row.locator('.owa_goalConditionHelp')).toContainText('source named by the landing URL');
        await expect(row).toHaveAttribute('data-saved-property', '');

        // The lists are there, and the unsaved property gives way on a change.
        await choose(page, 'select.owa_goalTrigger', 'click');
        expect(await offered(row)).toContain('is_outbound');
        await expect(row.locator('select.owa_goalProperty')).not.toHaveValue('tagged_source');
        await expect(row.locator('.owa_goalConditionHelp')).not.toHaveClass(/owa_goalConditionWarning/);
    });

    /**
     * Each property says what it holds: in the field chooser's detail, where
     * it is searched too, and under the row once chosen -- where the button
     * shows only the name. The chooser opens on the groups.
     */
    test('the picker describes what it offers', async ({ page }) => {
        await gotoAction(page, 'base.goalEventDefinitionEdit', `&owa_siteId=${FIXTURE.siteId}`);
        await choose(page, 'select.owa_goalTrigger', 'click');

        const row = page.locator('.owa_goalCondition').first();

        await row.locator('.owa_goalPropertyButton').click();

        const chooser = fieldChooser(page);

        await expect(chooser.getByRole('option', { name: /^Event, / })).toBeVisible();

        // Searching the DESCRIPTION finds it: no label says "other than the page".
        await chooser.getByRole('combobox').pressSequentially('other than the page');

        const hit = chooser.getByRole('option', { name: 'Outbound click', exact: true });

        await expect(chooser.locator('[role="option"][data-name]')).toHaveCount(1);
        await hit.hover();
        await expect(chooser).toContainText('Whether the click went to a host other than the page it was on.');
        await hit.click();

        await expect(row.locator('.owa_goalPropertyButton')).toHaveText('Outbound click');
        await expect(row.locator('.owa_goalConditionHelp'))
            .toHaveText('Whether the click went to a host other than the page it was on.');

        // Every option carries a description and a group.
        const blank = await row.locator('select.owa_goalProperty option')
            .evaluateAll((o) => o.filter((x) => !x.dataset.description || !x.dataset.group).map((x) => x.value));
        expect(blank, 'properties offered with no description or group').toEqual([]);
    });

    /** An added row gets a picker of its own, not a copy bound to the first. */
    test('an added condition has its own picker', async ({ page }) => {
        await gotoAction(page, 'base.goalEventDefinitionEdit', `&owa_siteId=${FIXTURE.siteId}`);
        await choose(page, 'select.owa_goalTrigger', 'click');

        const rows = page.locator('.owa_goalCondition');
        await chooseProperty(rows.first(), 'Outbound click');

        await rows.first().locator('.constraintAddButton').click();
        await expect(rows).toHaveCount(2);
        await expect(rows.nth(1).locator('.owa_goalPropertyButton')).toHaveCount(1);

        await chooseProperty(rows.nth(1), 'Target host');

        await expect(rows.nth(1).locator('select.owa_goalProperty')).toHaveValue('target_host');
        await expect(rows.first().locator('select.owa_goalProperty')).toHaveValue('is_outbound');
        await expect(rows.first().locator('.owa_goalPropertyButton')).toHaveText('Outbound click');
    });

    /**
     * A condition with nothing to compare against counts nothing, and says
     * nothing about it: the goal never fires.
     */
    test('a condition with no value is refused', async ({ page }) => {
        await gotoAction(page, 'base.goalEventDefinitionEdit', `&owa_siteId=${FIXTURE.siteId}`);

        await page.fill('input[name="name"]', 'E2E_No_Condition');
        await page.fill('input[name="conditionValue[]"]', '');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[value="Save Goal Event Definition"]').click(),
        ]);

        // Still on the form, carrying what was typed rather than a blank one.
        await expect(page.locator('input[name="name"]')).toHaveValue('E2E_No_Condition');
    });

});
