const { test, expect } = require('@playwright/test');
const { FIXTURE, login, adminLogin } = require('./fixtures');

/**
 * The report nav on a phone: hidden behind a menu button so the report has the
 * whole width, sliding in over it when the button is tapped, and closing when a
 * report is chosen, when the page beside it is tapped, and on Escape. At
 * desktop width the left column is unchanged and there is no button.
 */

const REPORT = 'pages';
const OTHER = { id: 'entry-pages', label: 'Entry Pages' };
const SCREENSHOTS = process.env.OWA_NAV_SCREENSHOTS || '';

async function openReport(page, reportId) {
    await page.goto(
        `?owa_do=base.report&owa_reportId=${reportId}`
        + `&owa_siteId=${FIXTURE.siteId}&owa_period=last_thirty_days`,
        { waitUntil: 'networkidle' }
    );
    await page.waitForSelector('#owa_navShell', { state: 'attached', timeout: 20_000 });
}

const rail = (page) => page.locator('#owa_reportNavRail');

// A report, and the settings screen every reader has, whose column is the longest.
const SCREENS = [
    { name: 'a report', open: (page) => openReport(page, REPORT) },
    { name: 'a settings screen', open: (page) => page.goto(
        `?owa_do=base.myProfile&owa_siteId=${FIXTURE.siteId}`, { waitUntil: 'networkidle' }) },
];
const toggle = (page) => page.locator('.owa_reportNavToggle');

async function shot(page, name) {
    if (SCREENSHOTS) {
        await page.screenshot({ path: `${SCREENSHOTS}/${name}.png` });
    }
}

test.describe('report nav on a phone', () => {

    // isMobile, so the page lays out as a phone browser would: without a
    // viewport meta tag that is 980px wide, past the breakpoint, whatever the
    // screen. A plain 390px viewport skips that step and hides the omission.
    test.use({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('the nav is behind the menu button, and opens and closes', async ({ page }) => {
        await openReport(page, REPORT);

        await expect(toggle(page)).toBeVisible();
        await expect(toggle(page)).toHaveAttribute('aria-expanded', 'false');
        await expect(rail(page)).not.toBeInViewport();
        await shot(page, 'phone-closed');

        // The report has the width the nav gave up.
        const report = await page.locator('.reportSectionContainer').first().boundingBox();
        expect(report.x).toBeLessThan(60);

        await toggle(page).click();
        await expect(rail(page)).toBeInViewport();
        await expect(toggle(page)).toHaveAttribute('aria-expanded', 'true');
        await expect(page.locator('#owa_siteControl')).toBeVisible();
        await shot(page, 'phone-open');

        // Its own close button, since the open panel covers the menu button.
        await rail(page).locator('.owa_reportNavClose').click();
        await expect(rail(page)).not.toBeInViewport();

        // Escape closes it.
        await toggle(page).click();
        await expect(rail(page)).toBeInViewport();
        await page.keyboard.press('Escape');
        await expect(rail(page)).not.toBeInViewport();
    });

    /**
     * The rest of the screen fits the phone too: the top bar is one row, the
     * page never scrolls sideways, and the table keeps its columns readable by
     * scrolling inside its widget instead of squeezing them to a character.
     */
    test('the header is one row and the table scrolls rather than squeezes', async ({ page }) => {
        await openReport(page, REPORT);

        const rows = await page.evaluate(() =>
            ['.owa_reportNavToggle', '.owa_helpMenu', '.owa_notificationBell', '.owa_userMenu']
                .map((s) => Math.round(document.querySelector(s).getBoundingClientRect().top)));
        expect(new Set(rows).size, `header item tops: ${rows}`).toBe(1);

        const page_width = await page.evaluate(() => document.documentElement.scrollWidth);
        expect(page_width).toBeLessThanOrEqual(390);

        // The row-number column is narrow by design; the data columns are not.
        const grid = await page.evaluate(() => {
            const widths = [...document.querySelectorAll('.owa_gridTable thead th:not(.owa_gridRowNum)')]
                .filter((th) => th.offsetWidth > 0)
                .map((th) => th.offsetWidth);
            const box = document.querySelector('.owa_gridTable').closest('.owa_gridScroll');
            return { narrowest: Math.min(...widths), scrolls: box.scrollWidth > box.clientWidth,
                overflow: getComputedStyle(box).overflowX };
        });
        expect(grid.narrowest, 'every column keeps a readable width').toBeGreaterThanOrEqual(60);
        expect(grid.scrolls, 'the table is wider than the phone, so its box scrolls').toBe(true);
        expect(['auto', 'scroll']).toContain(grid.overflow);
    });

    /**
     * The menu button is where the logo is at full width, at the left of the
     * header, and the logo moves to the top of the menu. The button used to be
     * under the header, at the top of the report.
     */
    test('the menu button has the logo\'s place, and the logo heads the menu', async ({ page }) => {
        await openReport(page, REPORT);

        const header = await page.locator('#owa_header').boundingBox();
        const button = await toggle(page).boundingBox();

        await expect(toggle(page)).toHaveCount(1);
        expect(button.x, 'at the left of the header').toBeLessThan(16);
        expect(button.y).toBeGreaterThanOrEqual(header.y);
        expect(button.y + button.height).toBeLessThanOrEqual(header.y + header.height);
        await expect(page.locator('#owa_header .owa_logo')).toBeHidden();

        await toggle(page).click();
        await expect(rail(page)).toBeInViewport();

        const logo = page.locator('#owa_reportNavRail .owa_railLogo img');
        await expect(logo).toBeVisible();

        const box = await logo.boundingBox();
        const close = await rail(page).locator('.owa_reportNavClose').boundingBox();
        expect(box.x, 'at the left of the menu').toBeLessThan(20);
        expect(Math.abs((box.y + box.height / 2) - (close.y + close.height / 2)), 'beside the close button').toBeLessThan(4);
    });

    /** A screen with no slide-out menu has no button, and keeps its logo. */
    test('a screen without a menu keeps its logo', async ({ page }) => {
        await page.goto(`?owa_do=base.error&owa_siteId=${FIXTURE.siteId}`, { waitUntil: 'networkidle' });

        await expect(page.locator('#owa_header')).toHaveCount(1);
        await expect(toggle(page)).toHaveCount(0);
        await expect(page.locator('#owa_header .owa_logo')).toBeVisible();
    });

    /**
     * On a phone the sections are a grid, as many across as fit, Settings
     * among them, with the current section's pages under it at the full
     * width. As a rail beside the column they were a strip 88px wide, and
     * Settings, at its foot, was below the fold.
     */
    for (const screen of SCREENS) {
        test(`the sections are a grid with Settings in view, on ${screen.name}`, async ({ page }) => {
            await screen.open(page);

            await toggle(page).click();
            await expect(rail(page)).toBeInViewport();

            const tiles = await rail(page).locator('.owa_railItem').evaluateAll((els) =>
                els.map((el) => { const r = el.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y) }; }));
            const rows = new Map();
            tiles.forEach((t) => rows.set(t.y, (rows.get(t.y) || 0) + 1));
            // As many columns of 96px (and 4px gaps) as the grid's width has: 3 at 390px.
            const width = await rail(page).locator('.owa_railList').evaluate((el) => el.clientWidth);
            const across = Math.min(Math.floor((width + 4) / 100), tiles.length);
            expect(across).toBeGreaterThanOrEqual(3);
            expect(Math.max(...rows.values()), `tiles per row: ${[...rows.values()]}`).toBe(across);

            await expect(rail(page).locator('#owa_railItem_settings')).toBeInViewport({ ratio: 1 });

            const grid = await rail(page).locator('.owa_rail').boundingBox();
            const column = await rail(page).locator('.owa_navColumn').boundingBox();
            expect(column.y, 'the pages are under the sections').toBeGreaterThanOrEqual(grid.y + grid.height - 1);
            expect(column.width, 'at the full width').toBeGreaterThan(360);
            await shot(page, `phone-grid-${screen.name.replace(/\W+/g, '-')}`);
        });
    }

    test('choosing a report goes to it with the nav closed', async ({ page }) => {
        await openReport(page, REPORT);

        await toggle(page).click();
        await expect(rail(page)).toBeInViewport();

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            rail(page).locator('a', { hasText: OTHER.label }).click(),
        ]);

        expect(page.url()).toContain(`reportId=${OTHER.id}`);
        await expect(rail(page)).not.toBeInViewport();
        await expect(toggle(page)).toHaveAttribute('aria-expanded', 'false');
    });
});

/**
 * A phone on its side is past the phone width, so the slide-out is the rail
 * and column, but it is short: the sections are taller than the screen and
 * scroll under Settings, which stays at the foot of the rail.
 */
test.describe('report nav on a phone on its side', () => {

    test.use({ viewport: { width: 844, height: 390 }, isMobile: true, hasTouch: true });

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    for (const screen of SCREENS) {
        test(`Settings is at the foot of the rail on ${screen.name}`, async ({ page }) => {
            await screen.open(page);

            await toggle(page).click();
            await expect(rail(page)).toBeInViewport();

            const gear = rail(page).locator('#owa_railItem_settings');
            await expect(gear).toBeInViewport({ ratio: 1 });
            const box = await gear.boundingBox();
            expect(390 - (box.y + box.height), 'at the foot of the screen').toBeLessThan(48);

            // Scrolled to the end of the sections, it is still there.
            await rail(page).locator('.owa_rail').evaluate((el) => { el.scrollTop = el.scrollHeight; });
            await expect(gear).toBeInViewport({ ratio: 1 });
            await shot(page, `side-${screen.name.replace(/\W+/g, '-')}`);
        });
    }
});

/**
 * On a tablet the slide-out is the rail and column, with the report beside it.
 * On a phone it is the whole screen, so there is nothing beside it to tap.
 */
test.describe('report nav on a tablet', () => {

    test.use({ viewport: { width: 820, height: 1180 }, isMobile: true, hasTouch: true });

    test('a tap beside the open nav closes it, and does nothing to the report under it', async ({ page }) => {
        await login(page);
        await openReport(page, REPORT);

        await toggle(page).click();
        await expect(rail(page)).toBeInViewport();

        const box = await rail(page).boundingBox();
        expect(box.width, 'the slide-out is as wide as the rail and column').toBeLessThan(400);

        await page.mouse.click(700, 600);
        await expect(rail(page)).not.toBeInViewport();
        expect(page.url()).toContain(`owa_reportId=${REPORT}`);
    });
});

test.describe('report nav at desktop width', () => {

    test.use({ viewport: { width: 1280, height: 900 } });

    test('is the left column, with no menu button', async ({ page }) => {
        await login(page);
        await openReport(page, REPORT);

        await expect(toggle(page)).toBeHidden();
        await expect(rail(page).locator('.owa_reportNavClose')).toBeHidden();
        await expect(rail(page).locator('.owa_railLogo')).toBeHidden();
        await expect(page.locator('#owa_header .owa_logo')).toBeVisible();
        await expect(rail(page)).toBeInViewport();
        await expect(page.locator('#owa_navColumn a', { hasText: OTHER.label })).toBeVisible();
        await shot(page, 'desktop');
    });

    /**
     * The window never scrolls; the content column does. The page is exactly
     * the window's height, the header is at the top, the navigation fills the
     * rest of the height with Settings at its foot, and scrolling the content
     * moves the report and nothing else. Checked on a report and a settings
     * screen, and in a short window, where the rail is compact rather than the
     * page taller than the window.
     */
    const SCROLL_SCREENS = [
        ['a report', `?owa_do=base.report&owa_reportId=${REPORT}&owa_siteId=${FIXTURE.siteId}&owa_period=last_thirty_days`],
        ['a settings screen', '?owa_do=base.optionsGeneral'],
    ];

    for (const height of [900, 600]) {
        for (const [name, url] of SCROLL_SCREENS) {
            test(`only the content scrolls, on ${name} in a window ${height} high`, async ({ page }) => {
                await page.setViewportSize({ width: 1280, height });
                await adminLogin(page);
                await page.goto(url, { waitUntil: 'networkidle' });
                await page.waitForSelector('#owa_navShell', { state: 'attached', timeout: 20_000 });

                const state = () => page.evaluate(() => {
                    const box = document.querySelector('.owa_contentScroll');
                    const top = (s) => document.querySelector(s).getBoundingClientRect().top;
                    return {
                        pageHeight: document.documentElement.scrollHeight,
                        windowScrolled: window.scrollY,
                        header: top('#owa_header'),
                        headerBottom: document.querySelector('#owa_header').getBoundingClientRect().bottom,
                        rail: top('#owa_reportNavRail'),
                        railBottom: document.querySelector('#owa_reportNavRail').getBoundingClientRect().bottom,
                        box: top('.owa_contentScroll'),
                        boxBottom: box.getBoundingClientRect().bottom,
                        overflow: getComputedStyle(box).overflowY,
                        first: box.firstElementChild.getBoundingClientRect().top,
                        boxScrolled: box.scrollTop,
                    };
                });

                const before = await state();
                expect(before.pageHeight, 'the page is no taller than the window').toBeLessThanOrEqual(height);
                expect(before.overflow).toBe('auto');
                expect(Math.abs(before.rail - before.headerBottom), 'the nav is right under the header').toBeLessThanOrEqual(1);
                expect(Math.abs(before.box - before.headerBottom), 'the content is right under the header').toBeLessThanOrEqual(1);
                expect(Math.abs(before.railBottom - height), 'the nav reaches the bottom of the window').toBeLessThanOrEqual(1);
                expect(Math.abs(before.boxBottom - height), 'the content reaches the bottom of the window').toBeLessThanOrEqual(1);
                await expect(rail(page).locator('.owa_railEntry.is-foot')).toBeInViewport({ ratio: 1 });

                // A wheel over the content scrolls the content, not the window.
                const box = await page.locator('.owa_contentScroll').boundingBox();
                await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
                await page.mouse.wheel(0, 2000);
                await expect.poll(async () => (await state()).boxScrolled).toBeGreaterThan(0);

                const after = await state();
                expect(after.windowScrolled).toBe(0);
                expect(after.header).toBe(0);
                expect(after.rail).toBe(before.rail);
                expect(Math.round(before.first - after.first)).toBe(Math.round(after.boxScrolled));
                await expect(page.locator('#owa_header .owa_userMenu')).toBeInViewport();
                await expect(rail(page).locator('.owa_railEntry.is-foot')).toBeInViewport({ ratio: 1 });
            });
        }
    }
});
