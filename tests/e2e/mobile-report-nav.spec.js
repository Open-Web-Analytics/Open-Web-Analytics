const { test, expect } = require('@playwright/test');
const { FIXTURE, login } = require('./fixtures');

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
    await page.waitForSelector('#owa_reportNavPanel', { state: 'attached', timeout: 20_000 });
}

const rail = (page) => page.locator('#owa_reportNavRail');
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

        // A tap beside it closes it, and does nothing to the report under it.
        await toggle(page).click();
        await expect(rail(page)).toBeInViewport();
        await page.mouse.click(370, 600);
        await expect(rail(page)).not.toBeInViewport();
        expect(page.url()).toContain(`owa_reportId=${REPORT}`);
    });

    /**
     * The rest of the screen fits the phone too: the top bar is one row, the
     * page never scrolls sideways, and the table keeps its columns readable by
     * scrolling inside its widget instead of squeezing them to a character.
     */
    test('the header is one row and the table scrolls rather than squeezes', async ({ page }) => {
        await openReport(page, REPORT);

        const rows = await page.evaluate(() =>
            ['.owa_logo', '.owa_navigation', '.owa_helpMenu', '.owa_notificationBell', '.owa_userMenu']
                .map((s) => Math.round(document.querySelector(s).getBoundingClientRect().top)));
        expect(new Set(rows).size, `header item tops: ${rows}`).toBe(1);

        const page_width = await page.evaluate(() => document.documentElement.scrollWidth);
        expect(page_width).toBeLessThanOrEqual(390);

        const grid = await page.evaluate(() => {
            const widths = [...document.querySelectorAll('.ui-jqgrid-htable th')]
                .filter((th) => th.offsetWidth > 0)
                .map((th) => th.offsetWidth);
            const box = document.querySelector('.ui-jqgrid').closest('.owa_widget-grid');
            return { narrowest: Math.min(...widths), scrolls: box.scrollWidth > box.clientWidth,
                overflow: getComputedStyle(box).overflowX };
        });
        expect(grid.narrowest, 'every column keeps a readable width').toBeGreaterThanOrEqual(60);
        expect(grid.scrolls, 'the table is wider than the phone, so its widget scrolls').toBe(true);
        expect(['auto', 'scroll']).toContain(grid.overflow);
    });

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

test.describe('report nav at desktop width', () => {

    test.use({ viewport: { width: 1280, height: 900 } });

    test('is the left column, with no menu button', async ({ page }) => {
        await login(page);
        await openReport(page, REPORT);

        await expect(toggle(page)).toBeHidden();
        await expect(rail(page).locator('.owa_reportNavClose')).toBeHidden();
        await expect(rail(page)).toBeInViewport();
        await expect(page.locator('#owa_reportNavPanel a', { hasText: OTHER.label })).toBeVisible();
        await shot(page, 'desktop');
    });
});
