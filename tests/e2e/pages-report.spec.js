const { test, expect } = require('@playwright/test');
const { FIXTURE, login, openReportNoTabs } = require('./fixtures');

/**
 * The Pages report groups by page: its path AND its query string, as the Pages
 * and Top Content cards that open it do, so a row on a card is a row in the
 * report. Each page offers its actions behind "Actions" and a chevron beside
 * it: its heatmap, its element clicks, its recordings, the pages either side.
 * The heatmap only while the grid is grouped by the page and its query string
 * first -- grouped by the path alone, a row pools every query string's clicks.
 */
test.describe('pages report', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    const actionsOf = async (page, path) => {
        const row = page.locator('.owa_gridTable tbody tr.owa_gridRow', { hasText: path }).first();
        await row.locator('.owa_gridRowActions').click();
        const ids = await page.locator('.owa_gridRowActionsMenu a').evaluateAll((as) => as.map((a) => a.dataset.action));
        await page.keyboard.press('Escape');
        return ids;
    };

    test('groups by the page and its query string, and offers its actions', async ({ page }) => {
        await openReportNoTabs(page, { reportId: 'pages' });

        const headers = await page.locator('.owa_gridTable thead th')
            .evaluateAll((ths) => ths.filter((th) => th.offsetWidth > 0).map((th) => th.innerText.trim()));

        expect(headers, `columns: ${headers.join(' | ')}`).toContain('Page Path + Query String');

        const button = page.locator('.owa_gridTable tbody tr.owa_gridRow .owa_gridRowActions').first();
        await expect(button).toHaveText('Actions');

        const ids = await actionsOf(page, '/pricing');
        expect(ids).toEqual(expect.arrayContaining(['heatmap', 'clicks', 'paths']));
    });

    test('grouped by the path alone, a page offers no heatmap', async ({ page }) => {
        await openReportNoTabs(page, { reportId: 'pages' });

        await page.evaluate(() => {
            const url = new OWA.uri(window.dim.resultSet.self);
            url.setQueryParam(OWA.util.appNs('dimensions'), 'pagePath');
            url.setQueryParam(OWA.util.appNs('sort'), 'pageViews-');
            window.dim.getNewResultSet(url.getSource());
        });

        await expect(page.locator('.owa_gridTable thead th', { hasText: 'Page Path' }).first()).toBeVisible();
        await expect(page.locator('.owa_gridTable thead th', { hasText: 'Query String' })).toHaveCount(0);

        const ids = await actionsOf(page, '/pricing');
        expect(ids).toContain('clicks');
        expect(ids).not.toContain('heatmap');
    });

    test('a page\'s heatmap opens that page', async ({ page }) => {
        await openReportNoTabs(page, { reportId: 'pages' });

        const row = page.locator('.owa_gridTable tbody tr.owa_gridRow', { hasText: '/pricing' }).first();
        await row.locator('.owa_gridRowActions').click();
        const href = await page.locator('.owa_gridRowActionsMenu a[data-action="heatmap"]').getAttribute('href');

        const response = await page.request.get(href, { maxRedirects: 0 });

        expect(response.status()).toBe(302);
        expect(response.headers()['location']).toMatch(/^https:\/\/owa-e2e\.example\.test\/pricing#owa_overlay\./);
    });
});

/**
 * Page Detail shows the pages viewed before and after its page, each linked to
 * that page's own Page Detail -- except (entrance) and (exit), which are not
 * pages.
 *
 * The fixture's visitor walks / -> /pricing three times, going on to /docs
 * once, so /pricing came from / three times and led to (exit) twice and /docs
 * once.
 */
test.describe('page detail', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    // A card draws a bar row under each value; those have one empty cell.
    const rows = (page, container) => page.locator(`#${container} .owa_gridTable tbody tr`)
        .evaluateAll((trs) => trs.map((tr) => {
            const cells = Array.from(tr.querySelectorAll('td')).map((td) => td.innerText.trim());
            return { cells, linked: !!tr.querySelector('td a[href*="reportId=document"]') };
        }).filter((r) => r.cells.length > 1));

    test('shows the previous and next pages', async ({ page }) => {
        await page.goto(`?owa_do=base.report&owa_reportId=document&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days&owa_pagePath=%2Fpricing', { waitUntil: 'networkidle' });

        await expect(page.locator('#next-pages .owa_gridTable tbody tr').first()).toBeVisible({ timeout: 20_000 });

        expect(await rows(page, 'previous-pages')).toEqual([{ cells: ['/', '3'], linked: true }]);

        expect(await rows(page, 'next-pages')).toEqual([
            { cells: ['(exit)', '2'], linked: false },
            { cells: ['/docs', '1'], linked: true },
        ]);
    });
});
