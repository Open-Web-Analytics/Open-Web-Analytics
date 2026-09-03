const { test } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');
test('dbg', async ({ page }) => {
    await adminLogin(page);
    await page.goto(`?owa_do=base.goalEvents&owa_siteId=${FIXTURE.siteId}`, { waitUntil: 'networkidle' });
    const rows = await page.locator('table.management tbody tr').allTextContents();
    console.log('GOALROWS ' + JSON.stringify(rows));
    const href = await page.locator('a[href*="base.goalEventEdit"]').first().getAttribute('href');
    await page.goto(href, { waitUntil: 'networkidle' });
    console.log('PROP ' + await page.locator('select[name="conditionProperty[]"]').inputValue());
    console.log('OP ' + await page.locator('select[name="conditionOperator[]"]').inputValue());
    console.log('VAL ' + await page.locator('input[name="conditionValue[]"]').inputValue());
    console.log('MATCH ' + await page.locator('select[name="conditionMatch"]').inputValue());
});
