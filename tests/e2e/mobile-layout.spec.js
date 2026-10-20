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
    await page.locator('.owa_reportPeriodLabelText').first().click();
    await expect(page.locator('#owa_reportPeriodFiltersContainer')).toBeVisible();
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

    test('the date range panel stacks its two calendars', async ({ page }) => {
        await page.goto(report('pages'), { waitUntil: 'networkidle' });
        await openPicker(page);

        const start = await page.locator('#owa_report-datepicker-start').boundingBox();
        const end = await page.locator('#owa_report-datepicker-end').boundingBox();

        expect(end.y, 'the end calendar is below the start calendar').toBeGreaterThanOrEqual(start.y + start.height);

        // Within the screen's width; how far down it sits depends on what is
        // above the report (a banner, say), and a phone scrolls down anyway.
        for (const el of [start, end, await page.locator('#owa_reportPeriodFilterSubmit').boundingBox()]) {
            expect(el.x).toBeGreaterThanOrEqual(0);
            expect(el.x + el.width).toBeLessThanOrEqual(390);
        }
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
        await expect(rail.locator('.owa_hierarchyNav').getByRole('link', { name: 'Modules', exact: true })).toBeVisible();

        await rail.locator('.owa_reportNavClose').click();
        await expect(rail).not.toBeInViewport();
        await expect(toggle).toHaveAttribute('aria-label', 'Show the menu');

        await toggle.click();
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            rail.locator('.owa_hierarchyNav').getByRole('link', { name: 'Modules', exact: true }).click(),
        ]);
        expect(page.url()).toContain('base.optionsModules');
        await expect(page.locator('#owa_reportNavRail')).not.toBeInViewport();
    });

    test('a card on its own row is as tall as its content', async ({ page }) => {
        await page.goto(report('dashboard'), { waitUntil: 'networkidle' });

        const floors = await page.locator('.owa_widget-grid-card').evaluateAll(
            (cards) => cards.map((c) => getComputedStyle(c).minHeight));
        expect(floors.length).toBeGreaterThan(0);
        expect(new Set(floors)).toEqual(new Set(['0px']));
    });
});

test.describe('at desktop width', () => {

    test.use({ viewport: { width: 1280, height: 900 } });

    test('a settings screen keeps its left column and cards keep their floor', async ({ page }) => {
        await adminLogin(page);

        await page.goto('?owa_do=base.optionsGeneral', { waitUntil: 'networkidle' });
        await expect(page.locator('.owa_reportNavToggle')).toBeHidden();
        await expect(page.locator('#owa_reportNavRail')).toBeInViewport();
        await expect(page.locator('.owa_hierarchyNav').getByRole('link', { name: 'Modules', exact: true })).toBeVisible();

        await page.goto(report('dashboard'), { waitUntil: 'networkidle' });
        const floor = await page.locator('.owa_widget-grid-card').first().evaluate((c) => getComputedStyle(c).minHeight);
        expect(floor).toBe('320px');
    });
});
