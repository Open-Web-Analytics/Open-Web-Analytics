const { test, expect } = require('@playwright/test');
const { FIXTURE, login, openReportNoTabs } = require('./fixtures');

/**
 * The Pages report groups by page path, every query string of a path counted
 * as it -- a subscriber id or a campaign on a link is still the same page --
 * as the Pages and Top Content cards that open it do, so a row on a card is a
 * row in the report. Each page offers its actions behind "Actions" and a
 * chevron beside it: its heatmap, its element clicks, its recordings, the
 * pages either side. Grouped by path, the heatmap draws the clicks of every
 * query string of the path; regrouped by the path and its query string, it
 * is the heatmap of that exact page.
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

    test('groups by the page path, and offers its actions', async ({ page }) => {
        await openReportNoTabs(page, { reportId: 'pages' });

        const headers = await page.locator('.owa_gridTable thead th')
            .evaluateAll((ths) => ths.filter((th) => th.offsetWidth > 0).map((th) => th.innerText.trim()));

        expect(headers, `columns: ${headers.join(' | ')}`).toContain('Page Path');
        expect(headers).not.toContain('Page Path + Query String');

        const button = page.locator('.owa_gridTable tbody tr.owa_gridRow .owa_gridRowActions').first();
        await expect(button).toHaveText('Actions');

        const ids = await actionsOf(page, '/pricing');
        expect(ids).toEqual(expect.arrayContaining(['pathHeatmap', 'clicks', 'paths']));
        expect(ids).not.toContain('heatmap');
    });

    test('grouped by the path and its query string, a page offers its own heatmap', async ({ page }) => {
        await openReportNoTabs(page, { reportId: 'pages' });

        await page.evaluate(() => {
            const url = new OWA.uri(window.dim.resultSet.self);
            url.setQueryParam(OWA.util.appNs('dimensions'), 'pagePathPlusQuery');
            url.setQueryParam(OWA.util.appNs('sort'), 'pageViews-');
            window.dim.getNewResultSet(url.getSource());
        });

        await expect(page.locator('.owa_gridTable thead th', { hasText: 'Page Path + Query String' }).first()).toBeVisible();

        const ids = await actionsOf(page, '/pricing');
        expect(ids).toEqual(expect.arrayContaining(['heatmap', 'clicks']));
        expect(ids).not.toContain('pathHeatmap');
    });

    test('a page\'s heatmap opens that path with every query string\'s clicks', async ({ page }) => {
        await openReportNoTabs(page, { reportId: 'pages' });

        const row = page.locator('.owa_gridTable tbody tr.owa_gridRow', { hasText: '/pricing' }).first();
        await row.locator('.owa_gridRowActions').click();
        const link = page.locator('.owa_gridRowActionsMenu a[data-action="pathHeatmap"]');
        await expect(link).toHaveText('Heatmap');
        const href = await link.getAttribute('href');

        const response = await page.request.get(href, { maxRedirects: 0 });

        expect(response.status()).toBe(302);

        const location = response.headers()['location'];
        expect(location).toMatch(/^https:\/\/owa-e2e\.example\.test\/pricing#owa_overlay\./);

        const overlay = JSON.parse(Buffer.from(decodeURIComponent(location.split('#owa_overlay.')[1]), 'base64').toString());
        const constraints = new URL(overlay.api_url).searchParams.get('constraints');
        expect(constraints).toBe('pagePath==/pricing,eventName==click');
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
