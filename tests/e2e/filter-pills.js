// @ts-check
/**
 * The filter pills (filter_pills.php, OWA.filterPills), driven as a person
 * drives them: by role and label, and by data-ui where those cannot single a
 * part out.
 *
 * The field is picked in the field chooser (field-chooser.js), opened from
 * the form's field button.
 */
const { expect } = require('@playwright/test');
const { pickField, offeredFields, selectCondition } = require('./field-chooser.js');

/** The Filter button and a pill per filter. */
function filterPills(page) {
    return page.locator('[data-ui="filter:pills"]');
}

/** The form that writes one filter. */
function filterForm(page) {
    return page.locator('[data-ui="filter:form"]');
}

/** A pill's button: 'Remove' or 'Change', and words the filter's sentence matches. */
function pillButton(page, action, words) {
    return filterPills(page).getByRole('button', { name: new RegExp(`^${action} the filter ${words}`) });
}

async function openFilterForm(page) {
    if (!(await filterForm(page).isVisible())) {
        await filterPills(page).getByRole('button', { name: 'Filter', exact: true }).click();
    }

    await expect(filterForm(page)).toBeVisible();
}

/**
 * Fill the open form.
 *
 * @param dimension the dimension's or metric's machine name
 * @param condition the condition's words, e.g. 'is' or 'has a value'
 */
async function fillFilter(page, { dimension, condition, value }) {
    const form = filterForm(page);

    if (dimension) {
        await pickField(page, form.locator('[data-ui="filter:dimension"]'), dimension);
    }

    if (condition) {
        await selectCondition(form.getByLabel('Condition', { exact: true }), condition);
    }

    if (value !== undefined) {
        await form.getByLabel('Value', { exact: true }).fill(value);
    }
}

/** The names the field chooser offers, and the keys of the groups it puts them in. */
async function offeredDimensions(page) {
    await openFilterForm(page);

    return offeredFields(page, filterForm(page).locator('[data-ui="filter:dimension"]'));
}

/**
 * Do `act` and wait for the page it loads. A report screen applies its filter
 * by reloading with the constraints string on the URL.
 *
 * @returns the request's URL parameters
 */
async function reloadedBy(page, act) {
    const [request] = await Promise.all([
        page.waitForRequest((r) => r.isNavigationRequest() && r.frame() === page.mainFrame()),
        act(),
    ]);

    await page.waitForURL(request.url(), { waitUntil: 'networkidle' });

    return new URL(request.url()).searchParams;
}

module.exports = {
    filterPills,
    filterForm,
    pillButton,
    openFilterForm,
    fillFilter,
    offeredDimensions,
    reloadedBy,
};
