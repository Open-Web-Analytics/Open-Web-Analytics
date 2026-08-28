// @ts-check
/**
 * The left-hand report navigation: it opens on the group you are in, and marks
 * the report you are looking at.
 *
 * WHAT THIS EXISTS FOR
 *
 * Both broke together, from one cause. navLinkIsCurrent() compares every key of
 * a link's ref, and a report link is {do: base.report, reportId: pages}. The nav
 * was handed `array('do' => $current_action)` to compare against -- ONE key --
 * so `reportId` was never present and no report link was ever current.
 *
 * From there the two symptoms follow. Nothing highlighted, because nothing
 * matched. And the nav rendered collapsed on every page load, because
 * .owa_admin_nav_subgroup is display:none in CSS and the only thing that opens
 * a group is the script looking for .owa_current.
 *
 * It worked before the reports-as-config conversion for the same reason it
 * broke after: a link used to be a single action name, so comparing the action
 * WAS comparing the whole ref.
 *
 * These assert what a reader sees, because that is what was wrong. A unit test
 * can prove the markup carries the class; only a browser can say the group is
 * actually open, since that is CSS plus a script.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, login } = require('./fixtures');

/** A report that lives inside a nav subgroup, and its group. */
const REPORT = { id: 'pages', label: 'Pages', group: 'Content' };

/** A second one in the same group, so "current" can be shown to move. */
const SIBLING = { id: 'entry-pages', label: 'Entry Pages' };

async function openReport(page, reportId) {
    await page.goto(
        `?owa_do=base.report&owa_reportId=${reportId}`
        + `&owa_siteId=${FIXTURE.siteId}&owa_period=last_thirty_days`,
        { waitUntil: 'networkidle' }
    );
    await page.waitForSelector('#owa_reportNavPanel', { timeout: 20_000 });
}

/** The subgroup panel belonging to the nav group with this label. */
function groupPanel(page, label) {
    return page.locator('.owa_admin_nav_topmenu')
        .filter({ has: page.locator('.owa_admin_nav_topmenu_item', { hasText: label }) })
        .locator('.owa_admin_nav_subgroup');
}

test.describe('report navigation', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    /**
     * The symptom that reads as "it keeps collapsing": every page load rendered
     * the whole menu shut, because no group was ever recognised as the one
     * being looked at.
     */
    test('the group holding the current report is open on arrival', async ({ page }) => {
        await openReport(page, REPORT.id);

        await expect(groupPanel(page, REPORT.group)).toBeVisible();
    });

    /**
     * ...and the others are not, so "open" means something. Without this the
     * test above would pass against a nav that simply never collapses.
     */
    test('a group you are not in stays closed', async ({ page }) => {
        await openReport(page, REPORT.id);

        await expect(groupPanel(page, 'Ecommerce')).toBeHidden();
    });

    /** The caret agrees with the panel, rather than pointing the wrong way. */
    test('the open group points its caret down', async ({ page }) => {
        await openReport(page, REPORT.id);

        const caret = page.locator('.owa_admin_nav_topmenu_item.owa_current .owa_admin_nav_topmenu_toggle');

        await expect(caret).toHaveClass(/fa-caret-down/);
        await expect(caret).not.toHaveClass(/fa-caret-right/);
    });

    /** The symptom reported as "no longer highlights the current report". */
    test('the current report is marked, and it is the right one', async ({ page }) => {
        await openReport(page, REPORT.id);

        const current = page.locator('.owa_admin_nav_subgroup_item.owa_current');

        await expect(current).toHaveCount(1);
        await expect(current).toContainText(REPORT.label);
    });

    /**
     * The mark MOVES. A nav that highlighted a hardcoded entry, or every entry,
     * would pass the assertion above.
     */
    test('the mark follows the report being viewed', async ({ page }) => {
        await openReport(page, REPORT.id);
        await expect(page.locator('.owa_admin_nav_subgroup_item.owa_current'))
            .toContainText(REPORT.label);

        await openReport(page, SIBLING.id);

        const current = page.locator('.owa_admin_nav_subgroup_item.owa_current');

        await expect(current).toHaveCount(1);
        await expect(current).toContainText(SIBLING.label);
    });

    /**
     * Exactly one group is current. Comparing the ACTION alone would match
     * every report at once, which is the failure mode the conversion
     * introduced and the opposite of the one that shipped.
     */
    test('only one group is current at a time', async ({ page }) => {
        await openReport(page, REPORT.id);

        await expect(page.locator('.owa_admin_nav_topmenu_item.owa_current')).toHaveCount(1);
    });

    /** The toggle still works by hand, for a group you are not in. */
    test('a closed group can still be opened by clicking it', async ({ page }) => {
        await openReport(page, REPORT.id);

        const ecommerce = page.locator('.owa_admin_nav_topmenu')
            .filter({ has: page.locator('.owa_admin_nav_topmenu_item', { hasText: 'Ecommerce' }) });

        await expect(ecommerce.locator('.owa_admin_nav_subgroup')).toBeHidden();

        await ecommerce.locator('.owa_admin_nav_topmenu_toggle').click();

        await expect(ecommerce.locator('.owa_admin_nav_subgroup')).toBeVisible();
    });
});
