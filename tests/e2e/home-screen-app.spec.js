// @ts-check
/**
 * OWA added to a phone's Home Screen opens as an app: the page names its
 * manifest and icon, both are served, and in app mode the page draws its own
 * pull-to-refresh (owa.appmode.js). In a browser tab it draws nothing.
 */
const { test, expect } = require('@playwright/test');
const { login, openDashboard } = require('./fixtures');

test.describe('the Home Screen app', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('the page names a manifest and an icon that are served', async ({ page, request }) => {
        await openDashboard(page);

        const manifestHref = await page.locator('link[rel="manifest"]').getAttribute('href');
        const iconHref = await page.locator('link[rel="apple-touch-icon"]').getAttribute('href');

        const manifest = await request.get(new URL(manifestHref, page.url()).toString());
        expect(manifest.status()).toBe(200);
        const json = await manifest.json();
        expect(json.display).toBe('standalone');

        // start_url resolves to the install's own index.php.
        const start = new URL(json.start_url, new URL(manifestHref, page.url()));
        expect(start.pathname).toBe(new URL('index.php', new URL(manifestHref, page.url()).toString().replace(/public\/base\/i\/manifest\.json$/, '')).pathname);

        const icon = await request.get(new URL(iconHref, page.url()).toString());
        expect(icon.status()).toBe(200);
        expect(icon.headers()['content-type']).toContain('image/png');
    });

    test('in a browser tab there is no pull-to-refresh of OWA\'s own', async ({ page }) => {
        await openDashboard(page);

        await expect(page.locator('.owa_pullRefresh')).toHaveCount(0);
    });

    test('opened as an app, the page draws its pull-to-refresh', async ({ page }) => {
        // What iOS reports for a page opened from the Home Screen.
        await page.addInitScript(() => {
            Object.defineProperty(window.navigator, 'standalone', { get: () => true });
        });

        await openDashboard(page);

        await expect(page.locator('.owa_pullRefresh')).toHaveCount(1);
        await expect(page.locator('html')).toHaveClass(/owa_appMode/);
    });
});
