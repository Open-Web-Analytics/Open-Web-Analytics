// @ts-check
/**
 * The metric and dimension chooser (owa.fieldchooser.js), driven as a person
 * drives it: open it from its button, search, click the name. It is appended
 * to the page's body, so it is found from the page, not from the form that
 * opened it.
 *
 * A row's machine name is its data-name, the way a <select>'s option holds
 * its value: the name a person reads is the label, which the specs do not
 * know.
 */
const { expect } = require('@playwright/test');

/** The open chooser. */
function fieldChooser(page) {
    return page.locator('[data-ui="field:chooser"]');
}

/** Pick the field called `name` in the chooser `trigger` opens. */
async function pickField(page, trigger, name) {
    await trigger.click();

    const chooser = fieldChooser(page);

    await expect(chooser).toBeVisible();
    await chooser.getByRole('combobox').pressSequentially(name);
    await chooser.locator(`[role="option"][data-name="${name}"]`).click();
    await expect(chooser).toHaveCount(0);
}

/**
 * Every field the chooser `trigger` opens offers, group by group, and the
 * groups' keys (a dimension's family, a metric's group).
 */
async function offeredFields(page, trigger) {
    await trigger.click();

    const chooser = fieldChooser(page);
    const keys = await chooser.locator('[role="option"][data-key^="g:"]')
        .evaluateAll((els) => els.map((e) => e.getAttribute('data-key').slice(2)));
    const names = [];

    for (const key of keys) {
        await chooser.locator(`[data-key="g:${key}"]`).click();
        names.push(...await chooser.locator('[role="option"][data-name]')
            .evaluateAll((els) => els.map((e) => e.getAttribute('data-name'))));
        await chooser.getByRole('button', { name: 'Back to all groups' }).click();
    }

    await page.keyboard.press('Escape');
    await expect(chooser).toHaveCount(0);

    return { names, groups: keys.map((k) => k.split('|')[1]) };
}

/** Choose the condition whose words are `words`, in any case: 'is', 'has a value'. */
async function selectCondition(select, words) {
    const value = await select.evaluate((s, want) => {
        const o = Array.from(s.options).find((opt) => opt.textContent.trim().toLowerCase() === want.toLowerCase());
        return o ? o.value : null;
    }, words);

    expect(value, `no condition "${words}"`).not.toBeNull();
    await select.selectOption(value);
}

module.exports = { fieldChooser, pickField, offeredFields, selectCondition };
