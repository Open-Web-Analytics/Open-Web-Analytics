const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

/**
 * Reporting and the settings screens on a phone and a tablet: no screen scrolls
 * sideways, the date range panel stacks, a settings screen's left column is
 * behind a menu button as the report nav is, and a card on its own row is as
 * tall as its content.
 *
 * Breakpoints: 900px and 600px for the page (owa.report.css, owa.admin.css);
 * the widget grid answers to its own container width.
 */

const S = FIXTURE.siteId;
const report = (id) => `?owa_do=base.report&owa_reportId=${id}&owa_siteId=${S}&owa_period=last_thirty_days`;

const SCREENS = {
    dashboard: report('dashboard'),
    pages: report('pages'),
    sources: report('sources'),
    'latest visits': report('latest-visits'),
    realtime: `?owa_do=base.realtime&owa_siteId=${S}`,
    'custom reports': '?owa_do=base.customReports',
    'main configuration': '?owa_do=base.optionsGeneral',
    'profile settings': `?owa_do=base.sitesProfile&owa_siteId=${S}`,
    'tracking tag': `?owa_do=base.sitesInvocation&owa_siteId=${S}`,
    'property profile': `?owa_do=base.propertyProfile&owa_siteId=${S}`,
    'observation profile': `?owa_do=base.sitesProfile&owa_siteId=${S}`,
};

const PHONE = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true };
const TABLET = { viewport: { width: 820, height: 1180 } };

/** How far the page is wider than the screen, and what sticks out first. */
async function overflow(page) {
    return page.evaluate(() => {
        const vw = document.documentElement.clientWidth;
        const over = document.documentElement.scrollWidth - vw;
        const first = [...document.querySelectorAll('body *')]
            .find((el) => el.getBoundingClientRect().right > vw + 1
                && ![...el.children].some((c) => c.getBoundingClientRect().right > vw + 1));
        return { over, first: first ? first.outerHTML.slice(0, 120) : '' };
    });
}

async function openPicker(page) {
    await page.locator('.owa_periodTrigger').first().click();
    await expect(page.locator('.owa_periodPanel')).toBeVisible();
}

for (const [name, size] of [['phone', PHONE], ['tablet', TABLET]]) {

    test.describe(`no screen scrolls sideways on a ${name}`, () => {

        test.use(size);

        test(`every screen fits a ${name}`, async ({ page }) => {
            test.setTimeout(180_000);
            await adminLogin(page);

            for (const [screen, url] of Object.entries(SCREENS)) {
                await page.goto(url, { waitUntil: 'networkidle' });
                const o = await overflow(page);
                expect(o.over, `${screen} is ${o.over}px wider than the ${name}: ${o.first}`).toBeLessThanOrEqual(0);
            }
        });

        test(`the open date range panel fits a ${name}`, async ({ page }) => {
            await adminLogin(page);

            for (const id of ['dashboard', 'pages']) {
                await page.goto(report(id), { waitUntil: 'networkidle' });
                await openPicker(page);
                const o = await overflow(page);
                expect(o.over, `${id} with the date panel open: ${o.first}`).toBeLessThanOrEqual(0);
            }
        });
    });
}

test.describe('on a phone', () => {

    test.use(PHONE);

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    /**
     * A full-screen sheet: the presets as chips above the date fields, one
     * month of calendar, and Apply in reach at the bottom of the screen.
     */
    test('the date range panel is a full-screen sheet', async ({ page }) => {
        await page.goto(report('pages'), { waitUntil: 'networkidle' });
        await openPicker(page);

        const sheet = await page.locator('.owa_periodPanel').boundingBox();
        expect(sheet.x).toBe(0);
        expect(sheet.width).toBe(390);
        expect(sheet.height).toBe(844);

        const chips = await page.locator('.owa_periodChips').first().boundingBox();
        const fields = await page.locator('.owa_periodFieldRow').first().boundingBox();
        expect(chips.y + chips.height, 'the presets come first').toBeLessThanOrEqual(fields.y);
        await expect(page.locator('.owa_periodLeft')).toBeHidden();

        await expect(page.locator('.owa_periodMonth:visible')).toHaveCount(1);

        const apply = await page.locator('.owa_periodFoot [data-act="apply"]').boundingBox();
        expect(apply.y + apply.height).toBeLessThanOrEqual(844);
    });

    test('the calendar pages a month at a time', async ({ page }) => {
        await page.goto(report('pages'), { waitUntil: 'networkidle' });
        await openPicker(page);

        const name = page.locator('.owa_periodCalNav span');
        const before = await name.textContent();
        await page.locator('.owa_periodCalNav [data-act="mprev"]').click();
        await expect(name).not.toHaveText(before);
        await expect(page.locator('.owa_periodMonth:visible')).toHaveCount(1);
    });

    test('the menu takes the whole screen', async ({ page }) => {
        await page.goto('?owa_do=base.optionsGeneral', { waitUntil: 'networkidle' });
        await page.locator('.owa_reportNavToggle').click();

        // It slides in; measured once it has arrived.
        const rail = page.locator('#owa_reportNavRail');
        await expect.poll(async () => (await rail.boundingBox()).x).toBe(0);

        expect((await rail.boundingBox()).width).toBe(390);
    });

    /**
     * The Property chooser opens under its tile, inside the menu. Beside the
     * tile, as on a desktop, it opened off the side of the screen.
     */
    test('the Property chooser opens on the screen, its columns stacked', async ({ page }) => {
        await page.goto('?owa_do=base.optionsGeneral', { waitUntil: 'networkidle' });
        await page.locator('.owa_reportNavToggle').click();

        const rail = page.locator('#owa_reportNavRail');
        await expect.poll(async () => (await rail.boundingBox()).x).toBe(0);
        await page.locator('#owa_siteControlSummary').click();

        // Moved from the header into the menu, not drawn twice.
        await expect(page.locator('#owa_siteControl')).toHaveCount(1);
        await expect(page.locator('.owa_railSiteControl #owa_siteControl')).toHaveCount(1);

        const tile = await page.locator('#owa_siteControlSummary').boundingBox();
        const orgs = await page.locator('.owa_siteControlOrgs').boundingBox();
        const properties = page.locator('.owa_siteControlProperties');
        const props = await properties.boundingBox();

        expect(orgs.y, 'under the tile').toBeGreaterThanOrEqual(tile.y + tile.height);
        expect(props.y, 'Properties under Organization').toBeGreaterThanOrEqual(orgs.y + orgs.height - 1);

        for (const column of [orgs, props]) {
            expect(column.x).toBeGreaterThanOrEqual(0);
            expect(column.x + column.width).toBeLessThanOrEqual(390);
            expect(column.height, 'a stacked column with no height').toBeGreaterThan(20);
        }

        // The list scrolls the current Property into view, so that is the row to look for.
        await expect(properties.locator('.owa_siteControlItem.is-selected')).toBeInViewport();
    });

    test('a settings screen\'s menu is behind a button, and opens and closes', async ({ page }) => {
        await page.goto('?owa_do=base.optionsGeneral', { waitUntil: 'networkidle' });

        const rail = page.locator('#owa_reportNavRail');
        const toggle = page.locator('.owa_reportNavToggle');

        await expect(rail).not.toBeInViewport();
        await expect(toggle).toHaveAttribute('aria-label', 'Show the menu');

        // The content has the width the column gave up.
        const panel = await page.locator('.owa_hierarchyContent').boundingBox();
        expect(panel.x).toBeLessThan(60);

        await toggle.click();
        await expect(rail).toBeInViewport();
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(toggle).toHaveAttribute('aria-label', 'Hide the menu');
        await expect(rail.locator('.owa_navColumn').getByRole('link', { name: 'Modules', exact: true })).toBeVisible();

        await rail.locator('.owa_reportNavClose').click();
        await expect(rail).not.toBeInViewport();
        await expect(toggle).toHaveAttribute('aria-label', 'Show the menu');

        await toggle.click();
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            rail.locator('.owa_navColumn').getByRole('link', { name: 'Modules', exact: true }).click(),
        ]);
        expect(page.url()).toContain('base.optionsModules');
        await expect(page.locator('#owa_reportNavRail')).not.toBeInViewport();
    });

    test('the header keeps to one row', async ({ page }) => {
        await page.goto('?owa_do=base.optionsGeneral', { waitUntil: 'networkidle' });

        const nav = await page.locator('#owa_header .owa_reportNavToggle').boundingBox();
        for (const sel of ['.owa_helpMenuToggle', '.owa_notificationToggle', '.owa_userMenuToggle']) {
            const box = await page.locator(`#owa_header ${sel}`).boundingBox();
            expect(box.y, `${sel} wrapped below the menu button`).toBeLessThan(nav.y + nav.height);
            expect(box.x + box.width).toBeLessThanOrEqual(390);
        }
    });

    /**
     * The menu, the Property chooser and the date range panel at phone sizes:
     * nothing in them below 12px, a menu link or a Property row at least 44px
     * tall, and every form control in the date panel at 16px, below which iOS
     * zooms the page when one is tapped.
     */
    test('the menu, Property chooser and date range panel are sized for a phone', async ({ page }) => {
        const px = (loc) => loc.evaluateAll((els) => els
            .filter((el) => el.offsetParent !== null && el.textContent.trim() !== '')
            .map((el) => ({
                text: el.textContent.trim().slice(0, 30),
                size: parseFloat(getComputedStyle(el).fontSize),
                height: el.getBoundingClientRect().height,
            })));

        await page.goto(report('pages'), { waitUntil: 'networkidle' });
        await page.locator('.owa_reportNavToggle').click();
        const rail = page.locator('#owa_reportNavRail');
        await expect.poll(async () => (await rail.boundingBox()).x).toBe(0);
        await page.locator('#owa_siteControlSummary').click();
        await expect(page.locator('.owa_siteControlProperties')).toBeVisible();

        const text = await px(rail.locator('.owa_railItem, .owa_navColumnItem, .owa_navColumnTitle, '
            + '.owa_siteControlOrg, .owa_siteControlProperty, .owa_siteControlDomain, '
            + '.owa_siteControlColumnHead, .owa_siteControlItemName, .owa_siteControlAdd'));
        expect(text.length).toBeGreaterThan(10);
        for (const t of text) {
            expect(t.size, `"${t.text}" is ${t.size}px`).toBeGreaterThanOrEqual(12);
        }

        for (const t of await px(rail.locator('.owa_navColumnItem, .owa_siteControlItem'))) {
            expect(t.height, `"${t.text}" is ${t.height}px tall`).toBeGreaterThanOrEqual(44);
        }

        await page.goto(report('pages'), { waitUntil: 'networkidle' });
        await openPicker(page);
        const panel = page.locator('.owa_periodPanel');

        // Custom date range: its two fields are inputs a phone types into.
        await panel.locator('.owa_periodChips button', { hasText: 'Custom date range' }).click();
        const controls = await panel.locator('input').evaluateAll((els) => els
            .filter((el) => el.offsetParent !== null)
            .map((el) => ({ name: el.dataset.in || el.type, size: parseFloat(getComputedStyle(el).fontSize) })));
        expect(controls.length).toBe(2);
        for (const c of controls) {
            expect(c.size, `${c.name} is ${c.size}px`).toBeGreaterThanOrEqual(16);
        }

        for (const b of await px(panel.locator('.owa_periodChips button, .owa_periodBtn, .owa_periodCalNav button'))) {
            expect(b.height, `"${b.text}" is ${b.height}px tall`).toBeGreaterThanOrEqual(40);
        }

        const days = await px(panel.locator('.owa_periodMonth.is-page .owa_periodDay'));
        expect(days.length).toBeGreaterThan(27);
        for (const d of days) {
            expect(d.size, `day ${d.text} is ${d.size}px`).toBeGreaterThanOrEqual(14);
            expect(d.height, `day ${d.text} is ${d.height}px tall`).toBeGreaterThanOrEqual(44);
        }
    });

    test('a form laid out as a table stacks each label above its field', async ({ page }) => {
        await page.goto(`?owa_do=base.sitesProfile&owa_siteId=${S}`, { waitUntil: 'networkidle' });

        const table = page.locator('table.owa_formTable');
        const card = await table.boundingBox();
        const label = await table.locator('th').first().boundingBox();
        const field = await table.locator('td select, td input[type=text]').first().boundingBox();

        expect(field.y, 'the field is beside its label').toBeGreaterThanOrEqual(label.y + label.height);
        expect(field.x + field.width, 'the field runs past the card').toBeLessThanOrEqual(card.x + card.width);
    });

    test('a card is as tall as its content', async ({ page }) => {
        await page.goto(report('dashboard'), { waitUntil: 'networkidle' });

        const floors = await page.locator('.owa_widget-grid-card').evaluateAll(
            (cards) => cards.map((c) => getComputedStyle(c).minHeight));
        expect(floors.length).toBeGreaterThan(0);
        expect(new Set(floors)).toEqual(new Set(['auto']));
    });
});

test.describe('at desktop width', () => {

    test.use({ viewport: { width: 1280, height: 900 } });

    test('a settings screen keeps its left column', async ({ page }) => {
        await adminLogin(page);

        await page.goto('?owa_do=base.optionsGeneral', { waitUntil: 'networkidle' });
        await expect(page.locator('.owa_reportNavToggle')).toBeHidden();
        await expect(page.locator('#owa_reportNavRail')).toBeInViewport();
        await expect(page.locator('.owa_navColumn').getByRole('link', { name: 'Modules', exact: true })).toBeVisible();
    });
});
