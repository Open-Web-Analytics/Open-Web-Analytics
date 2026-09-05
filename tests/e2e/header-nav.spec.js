// @ts-check
/**
 * The top bar.
 *
 * WHAT ONLY THIS SUITE CAN CHECK
 *
 * That the account menu behaves like a menu. Whether it starts closed, opens on
 * the control, closes on a click elsewhere and on Escape, and reports its state
 * to assistive technology are all runtime behaviour -- the markup alone says
 * none of it.
 *
 * And the spacing. The links used to be fixed-width boxes whatever their text,
 * so the gaps between the words were all different; that is a measurement, not
 * a class name.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, loginAs } = require('./fixtures');

const toggle = '#owa_userMenuToggle';
const panel  = '#owa_userMenuPanel';

test.describe('the top bar', () => {

    test.beforeEach(async ({ page }) => {
        await loginAs(page, FIXTURE.adminUserId, FIXTURE.adminPassword);
        await page.goto(`?owa_do=base.reportingHome&owa_siteId=${FIXTURE.siteId}`,
            { waitUntil: 'networkidle' });
    });

    /**
     * The greeting is gone.
     *
     * "Hi, <name> !" spent the widest part of the bar saying hello. The name is
     * the control now, so there is nothing left to greet with.
     */
    test('there is no greeting, and the name is a control', async ({ page }) => {
        await expect(page.locator('#owa_header')).not.toContainText('Hi,');

        // A button, not a link: it opens something rather than going somewhere.
        await expect(page.locator(toggle)).toHaveCount(1);
        await expect(page.locator(toggle)).toContainText(FIXTURE.adminUserId);
    });

    test('the menu starts closed, opens, and holds Profile and Logout', async ({ page }) => {
        await expect(page.locator(panel)).toBeHidden();
        await expect(page.locator(toggle)).toHaveAttribute('aria-expanded', 'false');

        await page.click(toggle);

        await expect(page.locator(panel)).toBeVisible();
        await expect(page.locator(toggle)).toHaveAttribute('aria-expanded', 'true');

        expect(await page.locator('.owa_userMenuItem').allInnerTexts())
            .toEqual(['Profile', 'Logout']);
    });

    /** Clicking the control again closes it. */
    test('the control toggles', async ({ page }) => {
        await page.click(toggle);
        await expect(page.locator(panel)).toBeVisible();

        await page.click(toggle);
        await expect(page.locator(panel)).toBeHidden();
        await expect(page.locator(toggle)).toHaveAttribute('aria-expanded', 'false');
    });

    test('a click elsewhere closes it', async ({ page }) => {
        await page.click(toggle);
        await expect(page.locator(panel)).toBeVisible();

        await page.mouse.click(400, 400);

        await expect(page.locator(panel)).toBeHidden();
        await expect(page.locator(toggle)).toHaveAttribute('aria-expanded', 'false');
    });

    /**
     * Escape closes it and returns the focus to the control that opened it,
     * rather than leaving a keyboard user somewhere with no menu and no idea
     * where they are.
     */
    test('escape closes it and gives the focus back', async ({ page }) => {
        await page.click(toggle);
        await expect(page.locator(panel)).toBeVisible();

        await page.keyboard.press('Escape');

        await expect(page.locator(panel)).toBeHidden();
        await expect(page.locator(toggle)).toBeFocused();
    });

    test('logout from the menu actually signs out', async ({ page }) => {
        await page.click(toggle);
        await page.locator('.owa_userMenuLogout').click();
        await page.waitForLoadState('networkidle');

        /*
         * The login form, which renders no header at all -- so the check is
         * that we are on it, not that the bar has changed. The account menu is
         * necessarily gone with the rest of the chrome.
         */
        expect(page.url()).toContain('base.loginForm');
        await expect(page.locator(toggle)).toHaveCount(0);
        await expect(page.locator('body')).toContainText('Login');
    });

    /**
     * WHAT THE BAR OFFERS.
     *
     * "Reporting" is Analytics. Documentation and Report a Bug are no longer
     * here: both leave the application for GitHub, so they moved into the help
     * menu rather than spending half the bar navigating away from it.
     */
    test('the bar offers Analytics, Settings and Donate', async ({ page }) => {
        expect(await page.locator('.owa_navigation li a').allInnerTexts())
            .toEqual(['Analytics', 'Settings', 'Donate']);

        // Analytics still goes where Reporting went: base.reportingHome, which
        // resolves to the dashboard, so the landing page is what to assert.
        await page.locator('.owa_navigation li a', { hasText: 'Analytics' }).click();
        await page.waitForLoadState('networkidle');

        expect(page.url()).toContain('reportId=dashboard');
        await expect(page.locator('.owa_reportTitle')).toContainText('Dashboard');
    });

    /**
     * EVERYTHING SITS ON THE LOGO'S CENTRE LINE.
     *
     * Every item in this header is floated, and a float takes its own height
     * and sits at the top of the content box -- so the logo (55px), the links
     * (36px) and the round buttons (40px) each started at the same top edge and
     * ended in three different places, none of them centred on any other.
     *
     * Measured, because that is what the defect was: a class name cannot say
     * whether two boxes share a centre line.
     */
    test('the logo, the links and the buttons share one centre line', async ({ page }) => {
        const middles = await page.evaluate(() => {
            const mid = (selector) => {
                const el = document.querySelector(selector);

                if (!el) {
                    return null;
                }

                const box = el.getBoundingClientRect();

                return Math.round(box.top + box.height / 2);
            };

            return {
                logo: mid('.owa_logo'),
                link: mid('.owa_navigation li a'),
                help: mid('.owa_helpMenu'),
                account: mid('.owa_userMenu'),
                bell: mid('.owa_notificationBell'),
            };
        });

        for (const [name, middle] of Object.entries(middles)) {
            expect(middle, `${name} is missing from the bar`).not.toBeNull();
        }

        const distinct = new Set(Object.values(middles));

        expect(distinct.size,
            'every item in the bar must sit on one centre line: ' + JSON.stringify(middles))
            .toBe(1);
    });

    /**
     * THE HELP MENU, left of the account menu.
     *
     * Left, because these controls are floated right and the first in source
     * order lands furthest right -- so the order in the bar is a fact about the
     * markup, and worth pinning.
     */
    test('help is a menu of the links that leave the application', async ({ page }) => {
        await expect(page.locator('#owa_helpMenuPanel')).toBeHidden();

        await page.click('#owa_helpMenuToggle');

        await expect(page.locator('#owa_helpMenuPanel')).toBeVisible();

        expect(await page.locator('#owa_helpMenuPanel a').allInnerTexts())
            .toEqual(['Documentation', 'Report a Bug', 'GitHub']);

        // They leave the application, so they open away from it.
        for (const link of await page.locator('#owa_helpMenuPanel a').all()) {
            expect(await link.getAttribute('href')).toContain('github.com');
            expect(await link.getAttribute('target')).toBe('_blank');
            // rel, or the new tab can reach back into this one.
            expect(await link.getAttribute('rel')).toContain('noopener');
        }
    });

    test('help sits to the left of the account menu', async ({ page }) => {
        const lefts = await page.evaluate(() => {
            const left = (selector) =>
                Math.round(document.querySelector(selector).getBoundingClientRect().left);

            return {
                help: left('.owa_helpMenu'),
                account: left('.owa_userMenu'),
                bell: left('.owa_notificationBell'),
            };
        });

        expect(lefts.help).toBeLessThan(lefts.account);
        expect(lefts.account).toBeLessThan(lefts.bell);
    });

    /** Two panels must not hang open in the same corner. */
    test('opening one menu closes the other', async ({ page }) => {
        await page.click('#owa_helpMenuToggle');
        await expect(page.locator('#owa_helpMenuPanel')).toBeVisible();

        await page.click(toggle);

        await expect(page.locator('#owa_helpMenuPanel')).toBeHidden();
        await expect(page.locator(panel)).toBeVisible();

        await page.click('#owa_helpMenuToggle');

        await expect(page.locator(panel)).toBeHidden();
        await expect(page.locator('#owa_helpMenuPanel')).toBeVisible();
    });

    /**
     * THE LINKS ARE SPACED BY ONE RULE.
     *
     * Every link was a 9em box whatever its text, so "Donate" sat marooned in
     * the middle of a wide one while "Documentation" filled its own -- the gaps
     * between the words were all different widths. Sized by their text with one
     * padding value, the gaps are equal and the widths are not.
     */
    test('the nav links are evenly spaced and sized by their text', async ({ page }) => {
        const geometry = await page.evaluate(() => {
            const links = [...document.querySelectorAll('.owa_navigation li a')];
            const gaps  = [];

            for (let i = 1; i < links.length; i++) {
                gaps.push(Math.round(links[i].getBoundingClientRect().left -
                                     links[i - 1].getBoundingClientRect().right));
            }

            return {
                count: links.length,
                gaps,
                widths: links.map((l) => Math.round(l.getBoundingClientRect().width)),
            };
        });

        expect(geometry.count).toBeGreaterThan(2);

        // One gap, repeated.
        expect(new Set(geometry.gaps).size).toBe(1);

        // ...and the boxes are NOT all one width, which is what says they are
        // sized by their text rather than by a fixed em value.
        expect(new Set(geometry.widths).size).toBeGreaterThan(1);
    });

    /**
     * The last item used to be an unclosed <LI>, which nested the rest of the
     * bar inside it.
     */
    test('every nav item is closed', async ({ page }) => {
        const nested = await page.locator('.owa_navigation li li').count();

        expect(nested).toBe(0);
    });
});
