const { test, expect } = require('@playwright/test');
const { adminLogin } = require('./fixtures');

/**
 * Reporting Cubes: the install-wide list with a status per cube, and a cube's
 * details.
 *
 * The seeder builds the fixture Property's cube (seedCube()), so the list has
 * at least that row. The level itself depends on this installation's scheduler,
 * so what is asserted is that every cube carries a badge with a word -- never
 * colour alone -- and that the details screen shows each check and the days.
 */

test.describe('reporting cube status', () => {

    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('the Instance nav reaches a list with a status per cube', async ({ page }) => {
        await page.goto('?owa_do=base.optionsModules', { waitUntil: 'networkidle' });

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.getByRole('link', { name: 'Reporting Cubes' }).click(),
        ]);

        await expect(page.locator('.panel_headline')).toHaveText('Reporting Cubes');

        const rows = page.locator('table.management tr:has(td)');
        expect(await rows.count()).toBeGreaterThan(0);

        const badges = page.locator('table.management .owa_cubeStatus');
        await expect(badges).toHaveCount(await rows.count());

        for (const badge of await badges.all()) {
            await expect(badge).toHaveText(/^(OK|Attention|Action needed)$/);
        }
    });

    test('a cube\'s details show every check and the recent days', async ({ page }) => {
        await page.goto('?owa_do=base.cubeStatus', { waitUntil: 'networkidle' });

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('table.management a', { hasText: 'Details' }).first().click(),
        ]);

        await expect(page.locator('.panel_headline')).toHaveText('Reporting Cube');

        for (const check of ['Scheduled build', 'Last build', 'Built through', 'Partitions', 'Custom dimensions']) {
            await expect(page.locator('table.management td', { hasText: check }).first()).toBeVisible();
        }

        await expect(page.getByText(/Last \d+ days/)).toBeVisible();
        await expect(page.locator('pre.owa_cubeStatusCommands')).toContainText('cmd=cube-rebuild property=');
    });
});
