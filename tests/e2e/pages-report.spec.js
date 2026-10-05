const { test, expect } = require('@playwright/test');
const { login, openReportNoTabs } = require('./fixtures');

/**
 * The Pages report groups by path AND query string, so /?p=3497 is not
 * counted as the home page. The query is the one ingest leaves: campaign
 * tags (owa_* and utm_*) are dropped from it, as GA drops them from Page
 * path + query string. The path still links to that page's own report.
 */
test.describe('pages report', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('lists the query string beside the path, and links the path', async ({ page }) => {
        await openReportNoTabs(page, { reportId: 'pages' });

        const headers = await page.locator('.ui-jqgrid-htable th')
            .evaluateAll((ths) => ths.filter((th) => th.offsetWidth > 0).map((th) => th.innerText.trim()));

        const path = headers.indexOf('Page Path');
        expect(path, `columns: ${headers.join(' | ')}`).toBeGreaterThanOrEqual(0);
        expect(headers[path + 1]).toBe('Page Query');
        expect(headers[path + 2]).toBe('Page Title');

        const link = page.locator('.ui-jqgrid-btable a').first();
        await expect(link).toBeVisible();
        expect(await link.getAttribute('href')).toContain('reportId=document');
    });
});
