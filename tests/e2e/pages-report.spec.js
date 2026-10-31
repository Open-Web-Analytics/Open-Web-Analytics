const { test, expect } = require('@playwright/test');
const { FIXTURE, login, openReportNoTabs } = require('./fixtures');

/**
 * The Pages report groups by path alone, as the Pages and Top Content cards
 * that open it do, so a row on a card is a row in the report. The query
 * string and title are columns a reader can add from the heading. The path
 * links to that page's own report.
 */
test.describe('pages report', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('groups by the path alone, and links the path', async ({ page }) => {
        await openReportNoTabs(page, { reportId: 'pages' });

        const headers = await page.locator('.owa_gridTable thead th')
            .evaluateAll((ths) => ths.filter((th) => th.offsetWidth > 0).map((th) => th.innerText.trim()));

        expect(headers, `columns: ${headers.join(' | ')}`).toContain('Page Path');
        expect(headers).not.toContain('Page Query');
        expect(headers).not.toContain('Page Title');

        const link = page.locator('.owa_gridTable tbody a').first();
        await expect(link).toBeVisible();
        expect(await link.getAttribute('href')).toContain('reportId=document');
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

    /**
     * The Heatmap Overlay link opens the page, with the overlay anchor on it.
     *
     * Page Detail's link went through the launcher with the report's link
     * state, which is its Property and no siteId since reports became a
     * Property's; the launcher read siteId alone, found no page and redirected
     * nowhere. The other heatmap specs build the anchor or pass siteId by hand,
     * so none of them followed this link. The site is not real, so the
     * launcher's redirect is read rather than followed.
     */
    test('the heatmap link opens the page with the overlay', async ({ page }) => {
        await page.goto(`?owa_do=base.report&owa_reportId=document&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days&owa_pagePath=%2Fpricing', { waitUntil: 'networkidle' });

        const href = await page.locator('a', { hasText: 'Heatmap Overlay' }).getAttribute('href');

        const response = await page.request.get(href, { maxRedirects: 0 });

        expect(response.status()).toBe(302);
        expect(response.headers()['location']).toMatch(/^https:\/\/owa-e2e\.example\.test\/pricing#owa_overlay\./);
    });
});
