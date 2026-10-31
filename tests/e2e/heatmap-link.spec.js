const { test, expect } = require('@playwright/test');
const { FIXTURE, login } = require('./fixtures');

/**
 * The Heatmap Overlay link on Page Detail opens the page, with the overlay
 * anchor on it.
 *
 * It goes through base.overlayLauncher, whose redirect is to the tracked site:
 * another host. The redirect guard (#980) turned every such redirect back to
 * this installation, so the link landed on the dashboard. The other heatmap
 * specs build the overlay url or pass siteId by hand, so none of them took
 * this path. The site is not real, so the redirect is read rather than
 * followed.
 */
test.describe('heatmap link', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('opens the page with the overlay', async ({ page }) => {
        await page.goto(`?owa_do=base.report&owa_reportId=document&owa_siteId=${FIXTURE.siteId}`
            + '&owa_period=last_thirty_days&owa_pagePath=%2Fpricing', { waitUntil: 'networkidle' });

        const href = await page.locator('a', { hasText: 'Heatmap Overlay' }).getAttribute('href');

        const response = await page.request.get(href, { maxRedirects: 0 });

        expect(response.status()).toBe(302);
        expect(response.headers()['location']).toMatch(/^https:\/\/owa-e2e\.example\.test\/pricing#owa_overlay\./);
    });
});
