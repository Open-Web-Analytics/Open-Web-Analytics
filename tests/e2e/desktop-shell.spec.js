// @ts-check
/**
 * The page at full width (owa.shell.css, 901px and up): the header across the
 * top, the rail and the content box under it, and only the content box
 * scrolls. The window is never taller than itself, so a wheel over the report
 * that runs past the foot of the content box moves nothing else, and the
 * header and the whole rail, Settings included, stay in view.
 *
 * Anything inside the content box counts: an absolutely positioned element in
 * it is placed in the content box, so it cannot make the window taller.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin } = require('./fixtures');

const S = FIXTURE.siteId;
const report = (id) => `?owa_do=base.report&owa_reportId=${id}&owa_siteId=${S}&owa_period=last_thirty_days`;

/** Two reports longer than the window, and a settings screen. */
const SCREENS = {
    dashboard: { url: report('dashboard'), long: true },
    'the visitors report': { url: report('visitors'), long: true },
    'a settings screen': { url: '?owa_do=base.optionsModules', long: false },
};

const SIZES = [
    { width: 1440, height: 900 },
    { width: 1280, height: 720 },
];

const header = (page) => page.locator('[data-ui="shell:header"]');
const content = (page) => page.locator('[data-ui="shell:content"]');
const rail = (page) => page.getByRole('navigation', { name: 'Sections' });

/** How far the window can scroll. */
function windowOverflow(page) {
    return page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight);
}

for (const size of SIZES) {

    test.describe(`at ${size.width}x${size.height}`, () => {

        test.use({ viewport: size });

        for (const [name, screen] of Object.entries(SCREENS)) {

            test(`${name}: only the content box scrolls`, async ({ page }) => {
                await adminLogin(page);
                await page.goto(screen.url, { waitUntil: 'networkidle' });

                await expect(header(page)).toBeVisible();
                await expect(content(page)).toBeVisible();

                // The rail's first section and Settings at its foot.
                const first = rail(page).getByRole('link').first();
                const settings = rail(page).getByRole('link', { name: 'Settings', exact: true });

                await expect(first).toBeInViewport({ ratio: 1 });
                await expect(settings).toBeInViewport({ ratio: 1 });

                expect(await windowOverflow(page)).toBeLessThanOrEqual(1);

                // The header ends where the rail and the content box start.
                const head = await header(page).boundingBox();
                const box = await content(page).boundingBox();

                expect(Math.abs(box.y - (head.y + head.height))).toBeLessThanOrEqual(1);
                expect(Math.abs(box.y + box.height - size.height)).toBeLessThanOrEqual(1);

                if (!screen.long) {
                    return;
                }

                const scroll = () => content(page).evaluate((el) => ({
                    top: el.scrollTop,
                    max: el.scrollHeight - el.clientHeight,
                }));

                expect((await scroll()).max).toBeGreaterThan(0);

                // Wheel over the report, past the foot of the content box.
                await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);

                for (let i = 0; i < 12; i++) {
                    await page.mouse.wheel(0, 600);
                }

                await expect.poll(async () => {
                    const s = await scroll();
                    return s.max - s.top;
                }).toBeLessThanOrEqual(1);

                expect(await page.evaluate(() => window.scrollY)).toBe(0);

                await expect(header(page)).toBeInViewport({ ratio: 1 });
                await expect(first).toBeInViewport({ ratio: 1 });
                await expect(settings).toBeInViewport({ ratio: 1 });
            });
        }
    });
}
