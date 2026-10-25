const { test, expect } = require('@playwright/test');
const { login, openReportNoTabs } = require('./fixtures');

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
