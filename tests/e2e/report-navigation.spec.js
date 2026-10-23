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
 * a group is the script looking for a current item.
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
const { FIXTURE, login, logout, adminLogin } = require('./fixtures');

/** The reporting UI's selected colour, #1874CD: the selected tab, the Live View switch. */
const REPORT_BLUE = 'rgb(24, 116, 205)';

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

    /**
     * The chevron agrees with the panel: it says the group is open, and it is
     * on the right of the group's name.
     */
    test('the open group says so, with a chevron right of its name', async ({ page }) => {
        await openReport(page, REPORT.id);

        const item = page.locator('.owa_admin_nav_topmenu_item.has-current');
        const toggle = item.locator('.owa_admin_nav_topmenu_toggle');

        await expect(item).toHaveClass(/is-open/);
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(toggle.locator('i')).toHaveClass(/fa-chevron-down/);

        const name = await item.locator('.owa_admin_nav_topmenu_item_text').boundingBox();
        const chevron = await toggle.boundingBox();

        expect(chevron.x).toBeGreaterThan(name.x + name.width);
    });

    /** The symptom reported as "no longer highlights the current report". */
    test('the current report is marked, and it is the right one', async ({ page }) => {
        await openReport(page, REPORT.id);

        const current = page.locator('.owa_admin_nav_subgroup_item.is-current');

        await expect(current).toHaveCount(1);
        await expect(current).toContainText(REPORT.label);
    });

    /**
     * The mark MOVES. A nav that highlighted a hardcoded entry, or every entry,
     * would pass the assertion above.
     */
    test('the mark follows the report being viewed', async ({ page }) => {
        await openReport(page, REPORT.id);
        await expect(page.locator('.owa_admin_nav_subgroup_item.is-current'))
            .toContainText(REPORT.label);

        await openReport(page, SIBLING.id);

        const current = page.locator('.owa_admin_nav_subgroup_item.is-current');

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

        await expect(page.locator('.owa_admin_nav_topmenu_item.has-current')).toHaveCount(1);
    });

    /**
     * The current report is marked as the settings nav marks the current
     * screen, a 3px rule on its left and a bold label, but the rule and the
     * label are the reporting blue rather than the settings nav's orange and grey. Measured against the settings
     * nav itself, so the two cannot drift apart.
     */
    test('the current report is marked as the settings nav marks its screen, in blue', async ({ page }) => {
        const mark = (el) => {
            const box = getComputedStyle(el);
            const link = getComputedStyle(el.querySelector('a'));
            return {
                rule: `${box.borderLeftWidth} ${box.borderLeftStyle} ${box.borderLeftColor}`,
                weight: link.fontWeight,
                colour: link.color,
            };
        };

        // Settings screens are the admin's.
        await logout(page);
        await adminLogin(page);

        await page.goto('?owa_do=base.optionsGeneral', { waitUntil: 'networkidle' });
        const settings = await page.locator('.owa_hierarchyNav li.is-current').evaluate(mark);

        await openReport(page, REPORT.id);
        const report = await page.locator('.owa_admin_nav_subgroup_item.is-current').evaluate(mark);

        expect(report.rule).toBe(`3px solid ${REPORT_BLUE}`);
        expect(report.rule.replace(REPORT_BLUE, '')).toBe(settings.rule.replace(/rgb\([^)]*\)/, ''));
        expect(report.weight).toBe(settings.weight);
        expect(report.colour).toBe(REPORT_BLUE);
    });

    /**
     * Only the report is marked. The group holding it is open, with no rule of
     * its own: the old grey fill was drawn on both, so two lines read as current.
     */
    test('only the line for the report you are on carries the mark', async ({ page }) => {
        await openReport(page, REPORT.id);

        const ruled = await page.locator('#owa_reportNavPanel *').evaluateAll((els, accent) =>
            els.filter((el) => getComputedStyle(el).borderLeftColor === accent
                && getComputedStyle(el).borderLeftWidth !== '0px').map((el) => el.textContent.trim()),
        REPORT_BLUE);

        expect(ruled).toEqual([REPORT.label]);
        await expect(page.locator('#owa_reportNavPanel .is-current')).toHaveCount(1);
    });

    /**
     * A group's line is marked by its icon in the blue, not the rule: for
     * the group's own report (Content's overview), which also opens it, and
     * for the group holding the report you are on.
     */
    test("a group's line is marked by its icon", async ({ page }) => {
        const icon = (item) => item.locator('.owa_nav_icon').evaluate((el) => {
            const box = getComputedStyle(el);
            return { colour: box.color, opacity: box.opacity };
        });
        const rule = (item) => item.evaluate((el) => getComputedStyle(el).borderLeftWidth);
        const blue = { colour: REPORT_BLUE, opacity: '1' };

        await openReport(page, 'content');

        const current = page.locator('#owa_reportNavPanel .is-current');

        await expect(current).toHaveCount(1);
        await expect(current).toHaveClass(/owa_admin_nav_topmenu_item/);
        await expect(current).toContainText(REPORT.group);
        await expect(groupPanel(page, REPORT.group)).toBeVisible();
        expect(await icon(current)).toEqual(blue);
        await expect(current.locator('a')).toHaveCSS('color', REPORT_BLUE);
        expect(await rule(current)).toBe('0px');

        await openReport(page, REPORT.id);

        const holding = page.locator('.owa_admin_nav_topmenu_item.has-current');

        expect(await icon(holding)).toEqual(blue);
        expect(await rule(holding)).toBe('0px');

        // Any other group's icon is not.
        const other = page.locator('.owa_admin_nav_topmenu_item', { hasText: 'Ecommerce' });
        expect((await icon(other)).colour).not.toBe(REPORT_BLUE);
    });

    /**
     * Every group's label starts at one x, whatever its icon's glyph width
     * (they ran from 20px to 30px, so the labels started anywhere in a 10px
     * band), and a label that wraps stays beside its icon. A report's rule is
     * at that x, under its group's label. Checked with a group's own report
     * current as well, where the label is bold and the icon orange.
     */
    test('labels, icons and the report rule line up', async ({ page }) => {
        for (const id of [REPORT.id, 'content']) {
            await openReport(page, id);

            const geometry = await page.evaluate(() => {
                const groups = [...document.querySelectorAll('.owa_admin_nav_topmenu_item')].map((item) => {
                    const icon = item.querySelector('.owa_nav_icon').getBoundingClientRect();
                    const label = item.querySelector('a');
                    const lines = [...label.getClientRects()];
                    return {
                        name: label.textContent.trim(),
                        iconCentre: Math.round(icon.x + icon.width / 2),
                        labelLefts: [...new Set(lines.map((r) => Math.round(r.x)))],
                    };
                });
                const reports = [...document.querySelectorAll('.owa_admin_nav_subgroup_item')]
                    .filter((el) => el.offsetParent)
                    .map((el) => Math.round(el.getBoundingClientRect().x));
                return { groups, reports };
            });

            const lefts = geometry.groups.flatMap((g) => g.labelLefts);

            expect(new Set(geometry.groups.map((g) => g.iconCentre)).size, `${id}: icon centres`).toBe(1);
            expect(new Set(lefts), `${id}: label starts ${JSON.stringify(geometry.groups)}`).toEqual(new Set([lefts[0]]));
            expect(geometry.reports.length).toBeGreaterThan(0);
            expect(new Set(geometry.reports), `${id}: report rules`).toEqual(new Set([lefts[0]]));
        }
    });

    /**
     * Every group's label has room to spare, in bold, beside a wide page.
     *
     * "Custom Reports" wrapped twice. On its roster, a list of long names beside
     * the column (a table cell) squeezed it below 200px. Then, bold for being
     * current, it filled its room to the pixel in Chromium, and Safari's
     * Helvetica wrapped it. "Does not wrap here" cannot catch the second, so
     * each label must fit with 8px over, measured unwrapped, as current, with
     * an unbreakable line widening the cell beside the column.
     */
    test('every group label fits with room to spare, when current and beside a wide page', async ({ page }) => {
        await page.goto(`?owa_do=base.customReports&owa_siteId=${FIXTURE.siteId}`, { waitUntil: 'networkidle' });

        const result = await page.evaluate(() => {
            const column = document.querySelector('.owa_reportLeftNavColumn');
            const wide = document.createElement('div');
            wide.style.whiteSpace = 'nowrap';
            wide.textContent = 'A Report With A Long Name '.repeat(80);
            column.nextElementSibling.appendChild(wide);

            const labels = [...document.querySelectorAll('.owa_admin_nav_topmenu_item')].map((item) => {
                item.classList.add('is-current');

                const label = item.querySelector('a');
                const lines = label.getClientRects().length;

                // The item's content box, less the icon, its gap and the chevron.
                const box = getComputedStyle(item);
                const icon = item.querySelector('.owa_nav_icon');
                const chevron = item.querySelector('.owa_admin_nav_topmenu_toggle');
                const room = item.clientWidth - parseFloat(box.paddingLeft) - parseFloat(box.paddingRight)
                    - icon.getBoundingClientRect().width - parseFloat(getComputedStyle(icon).marginRight)
                    - (chevron ? chevron.getBoundingClientRect().width : 0);

                // Its own width on one line: out of the flow, so it is not
                // held to the room it is being measured against.
                const probe = label.cloneNode(true);
                probe.style.cssText = 'position:absolute; white-space:nowrap; visibility:hidden';
                label.parentNode.appendChild(probe);
                const needs = probe.getBoundingClientRect().width;
                probe.remove();

                return { name: label.textContent.trim(), lines, spare: Math.round((room - needs) * 10) / 10 };
            });

            return { width: Math.round(column.getBoundingClientRect().width), labels };
        });

        expect(result.width).toBeGreaterThanOrEqual(200);

        for (const label of result.labels) {
            expect(label.lines, label.name).toBe(1);
            expect(label.spare, `${label.name} has ${label.spare}px to spare`).toBeGreaterThanOrEqual(8);
        }
    });

    /** The toggle still works by hand, for a group you are not in. */
    test('a closed group can still be opened by clicking it', async ({ page }) => {
        await openReport(page, REPORT.id);

        const ecommerce = page.locator('.owa_admin_nav_topmenu')
            .filter({ has: page.locator('.owa_admin_nav_topmenu_item', { hasText: 'Ecommerce' }) });

        await expect(ecommerce.locator('.owa_admin_nav_subgroup')).toBeHidden();

        const toggle = ecommerce.locator('.owa_admin_nav_topmenu_toggle');

        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await toggle.click();

        await expect(ecommerce.locator('.owa_admin_nav_subgroup')).toBeVisible();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    });
});
