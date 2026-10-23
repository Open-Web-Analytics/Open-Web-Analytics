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
const { FIXTURE, loginAs, adminLogin } = require('./fixtures');

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
        const links = page.locator('.owa_navigation li a');

        // Icons, each named by its aria-label and its tooltip.
        expect(await links.evaluateAll((as) => as.map((a) => a.getAttribute('aria-label'))))
            .toEqual(['Analytics', 'Settings', 'Donate']);
        expect(await links.evaluateAll((as) => as.map((a) => a.getAttribute('title'))))
            .toEqual(['Analytics', 'Settings', 'Donate']);
        expect(await links.evaluateAll((as) => as.map((a) => a.querySelector('i').className)))
            .toEqual(['fas fa-chart-line', 'fas fa-cog', 'fas fa-hands-helping']);

        // Analytics still goes where Reporting went: base.reportingHome, which
        // resolves to the dashboard, so the landing page is what to assert.
        await page.getByRole('link', { name: 'Analytics', exact: true }).click();
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

    /**
     * The bar reads help, notifications, account from left to right.
     *
     * These are floated right, so the order is set by SOURCE order in reverse
     * -- the first element lands furthest right. That makes the arrangement a
     * fact about the markup, and easy to invert by accident.
     */
    test('the controls run help, notifications, account', async ({ page }) => {
        const lefts = await page.evaluate(() => {
            const left = (selector) =>
                Math.round(document.querySelector(selector).getBoundingClientRect().left);

            return {
                help: left('.owa_helpMenu'),
                bell: left('.owa_notificationBell'),
                account: left('.owa_userMenu'),
            };
        });

        expect(lefts.help).toBeLessThan(lefts.bell);
        expect(lefts.bell).toBeLessThan(lefts.account);
    });

    /**
     * ...and they are evenly spaced, measured BOX TO BOX.
     *
     * The badge overhangs the bell and gets no say in the spacing: the gaps are
     * between the controls themselves, so they are the same whether or not
     * anything is unread.
     */
    test('the header controls are evenly spaced', async ({ page }) => {
        const gaps = await page.evaluate(() => {
            const box = (selector) => document.querySelector(selector).getBoundingClientRect();

            return {
                helpToBell: Math.round(box('.owa_notificationBell').left
                                       - box('.owa_helpMenu').right),
                bellToAccount: Math.round(box('.owa_userMenu').left
                                          - box('.owa_notificationBell').right),
            };
        });

        expect(gaps.helpToBell).toBe(gaps.bellToAccount);
        expect(gaps.helpToBell).toBeGreaterThan(0);
    });

    /**
     * ...and they do not move when the badge does.
     *
     * The badge is absolutely positioned and overhangs its button, so showing
     * or hiding it must leave every control exactly where it was -- which is
     * what makes hiding it at zero safe. Measured with the badge forced on and
     * forced off, rather than trusted from the CSS.
     */
    test('the spacing does not change when the badge appears', async ({ page }) => {
        const measure = () => page.evaluate(() => {
            const box = (selector) => document.querySelector(selector).getBoundingClientRect();

            return [
                Math.round(box('.owa_helpMenu').left),
                Math.round(box('.owa_notificationBell').left),
                Math.round(box('.owa_userMenu').left),
            ];
        });

        await page.evaluate(() => {
            document.getElementById('owa_notificationBadge').hidden = false;
        });

        const withBadge = await measure();

        await page.evaluate(() => {
            document.getElementById('owa_notificationBadge').hidden = true;
        });

        expect(await measure()).toEqual(withBadge);
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
     * SIGNED OUT, ON AN INSTALL THAT LETS ANONYMOUS PEOPLE READ REPORTS.
     *
     * Granting view_reports to "everyone" -- which the demo install does -- means
     * the chrome renders for somebody with no account. There is nothing to
     * notify them about and no account to open a menu on, so the bell must not
     * be there and the pill must be a way IN rather than a menu.
     *
     * base.error is the screen used to check it: it renders the full header
     * without requiring a session, which the report screens on this fixture do
     * not.
     */
    test.describe('signed out', () => {

        test.use({ storageState: { cookies: [], origins: [] } });

        test('there is no bell and the pill is a login link', async ({ page }) => {
            await page.goto(`?owa_do=base.error&owa_siteId=${FIXTURE.siteId}`,
                { waitUntil: 'networkidle' });

            // The header is rendered at all, or this test proves nothing.
            await expect(page.locator('#owa_header')).toHaveCount(1);

            // Nothing to notify an anonymous reader about.
            await expect(page.locator('.owa_notificationBell')).toHaveCount(0);
            await expect(page.locator('#owa_notificationBadge')).toHaveCount(0);

            // No account, so no account menu.
            await expect(page.locator('#owa_userMenuToggle')).toHaveCount(0);
            await expect(page.locator('#owa_userMenuPanel')).toHaveCount(0);

            // A way in instead.
            const signIn = page.locator('.owa_userMenuSignIn');

            await expect(signIn).toHaveCount(1);
            await expect(signIn).toHaveText('Login');
            expect(await signIn.getAttribute('href')).toContain('base.loginForm');
        });

        /**
         * ...but help IS there, and works.
         *
         * Documentation is the thing an anonymous reader is most likely to
         * want, and every destination in this menu is a public GitHub URL, so
         * none of it depends on having a session. It used to sit inside the
         * signed-in branch and vanish with the rest of the account chrome.
         */
        test('help is still offered, and still opens', async ({ page }) => {
            await page.goto(`?owa_do=base.error&owa_siteId=${FIXTURE.siteId}`,
                { waitUntil: 'networkidle' });

            await expect(page.locator('.owa_helpMenu')).toHaveCount(1);

            await page.click('#owa_helpMenuToggle');

            await expect(page.locator('#owa_helpMenuPanel')).toBeVisible();
            expect(await page.locator('#owa_helpMenuPanel a').allInnerTexts())
                .toEqual(['Documentation', 'Report a Bug', 'GitHub']);
        });

        /*
         * There is deliberately no ORDERING assertion here. base.error is the
         * only screen that renders this header without a session, and its
         * container is narrow enough that the floats wrap onto two rows -- so
         * comparing their left edges compares different rows and says nothing.
         * The arrangement is asserted on a report screen above, where the bar
         * is full width.
         */
    });

    /**
     * THE LINKS ARE SPACED BY ONE RULE.
     *
     * Every link was a 9em box whatever its text, so "Donate" sat marooned in
     * the middle of a wide one while "Documentation" filled its own -- the gaps
     * between the words were all different widths. Sized by their text with one
     * padding value, the gaps are equal and the widths are not.
     */
    test('the nav icons are one size and evenly spaced', async ({ page }) => {
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
        expect(new Set(geometry.gaps).size).toBe(1);
        expect(new Set(geometry.widths).size).toBe(1);
    });

    /** The Property chooser is in the bar, right of the logo and left of the separator. */
    test('the Property chooser sits between the logo and the icons', async ({ page }) => {
        const x = await page.evaluate(() => ({
            logo: document.querySelector('.owa_logo').getBoundingClientRect().right,
            chooser: document.querySelector('#owa_header #owa_siteControl')?.getBoundingClientRect(),
            nav: document.querySelector('.owa_navigation').getBoundingClientRect().left,
            inRail: !!document.querySelector('.owa_railSiteControl #owa_siteControl'),
        }));

        expect(x.chooser, 'no chooser in the header').toBeTruthy();
        expect(x.inRail).toBe(false);
        expect(x.chooser.left).toBeGreaterThanOrEqual(x.logo);
        expect(x.chooser.right).toBeLessThanOrEqual(x.nav + 1);
    });

    /** A thin rule directly right of the logo, three quarters of the bar's height. */
    test('a separator stands right of the logo', async ({ page }) => {
        const rule = await page.evaluate(() => {
            const el   = document.querySelector('.owa_headerRule');
            const css  = getComputedStyle(el, '::before');
            const box  = el.getBoundingClientRect();
            const logo = document.querySelector('.owa_logo').getBoundingClientRect();
            const next = document.querySelector('#owa_header #owa_siteControl, .owa_navigation li a').getBoundingClientRect();

            return {
                width: parseFloat(css.width),
                ratio: parseFloat(css.height) / box.height,
                between: logo.right <= box.left + 1 && box.right <= next.left,
            };
        });

        expect(rule.width).toBe(1);
        expect(rule.ratio).toBeCloseTo(0.75, 2);
        expect(rule.between).toBe(true);
    });

    /** Each icon's glyph is centred on the same line as the pills on the right. */
    test('the icons are vertically centred with the other pills', async ({ page }) => {
        const centres = await page.evaluate(() => {
            const mid = (el) => { const r = el.getBoundingClientRect(); return r.top + r.height / 2; };

            return {
                pill: mid(document.querySelector('.owa_helpMenuToggle')),
                glyphs: [...document.querySelectorAll('.owa_navigation li a i')].map(mid),
                heights: [...document.querySelectorAll('.owa_navigation li a')].map((a) => a.getBoundingClientRect().height),
            };
        });

        for (const g of centres.glyphs) {
            expect(Math.abs(g - centres.pill)).toBeLessThan(1.5);
        }
        expect(new Set(centres.heights)).toEqual(new Set([40]));
    });

    /** One dark grey; orange for the section you are in and on hover. */
    test('the icons are grey, and orange when current or hovered', async ({ page }) => {
        const colours = () => page.locator('.owa_navigation li a').evaluateAll((as) =>
            as.map((a) => [a.getAttribute('aria-label'), getComputedStyle(a).color, a.getAttribute('aria-current')]));

        const GREY = 'rgb(80, 80, 80)';
        const ORANGE = 'rgb(255, 165, 0)';

        // A report: Analytics is current.
        expect(await colours()).toEqual([
            ['Analytics', ORANGE, 'page'], ['Settings', GREY, null], ['Donate', GREY, null]]);

        await page.getByRole('link', { name: 'Donate', exact: true }).hover();
        await expect.poll(async () => (await colours())[2][1]).toBe(ORANGE);

        // A settings screen: Settings is current.
        await page.goto('?owa_do=base.optionsGeneral', { waitUntil: 'networkidle' });
        await page.mouse.move(0, 400);
        expect(await colours()).toEqual([
            ['Analytics', GREY, null], ['Settings', ORANGE, 'page'], ['Donate', GREY, null]]);
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

/**
 * The footer: "Open Web Analytics version <n>", right-aligned, in place of the
 * two "Web Analytics powered by Open Web Analytics" links. The version only for
 * a signed-in user; this footer also draws on screens a visitor can reach.
 */
test.describe('the footer', () => {

    const footerText = async (page) => (await page.locator('.owa_footer').innerText()).trim();

    test('names the product and its version, right-aligned', async ({ page }) => {
        await adminLogin(page);
        await page.goto(`?owa_do=base.reportingHome&owa_siteId=${FIXTURE.siteId}`, { waitUntil: 'networkidle' });

        const footer = page.locator('.owa_footer');

        expect(await footerText(page)).toMatch(/^Open Web Analytics version \S+$/);
        await expect(footer.locator('a')).toHaveCount(0);
        await expect(footer).toHaveCSS('text-align', 'right');
    });

    test.describe('signed out', () => {

        test.use({ storageState: { cookies: [], origins: [] } });

        test('names the product without its version', async ({ page }) => {
            await page.goto(`?owa_do=base.error&owa_siteId=${FIXTURE.siteId}`, { waitUntil: 'networkidle' });

            expect(await footerText(page)).toBe('Open Web Analytics');
        });
    });
});

/**
 * A page's messages sit above its content column, not across the page.
 *
 * Above the whole page they pushed the left navigation down by their own height,
 * so a save moved the menu someone was about to use.
 */
test.describe('messages and the left navigation', () => {

    test('a status message after a save is in the content column, and the nav starts at the top', async ({ page }) => {
        await adminLogin(page);
        await page.goto(`?owa_do=base.profileSettings&owa_siteId=${FIXTURE.siteId}`, { waitUntil: 'networkidle' });
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.locator('input[type="submit"][value="Save Settings"]').click(),
        ]);

        const status = page.locator('.owa_hierarchyContent .status');
        await expect(status).toHaveCount(1);

        const nav = await page.locator('.owa_reportLeftNavColumn').boundingBox();
        const content = await page.locator('.owa_hierarchyContent').boundingBox();
        const banner = await status.boundingBox();

        expect(Math.abs(nav.y - content.y), 'the nav and the content start together').toBeLessThan(2);
        expect(banner.x, 'the banner is in the content column').toBeGreaterThanOrEqual(content.x - 1);
    });
});
