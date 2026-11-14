const { test, expect } = require('@playwright/test');
const { FIXTURE, adminLogin, login, ui } = require('./fixtures');

/**
 * The admin and reporting screens used with a finger, upright and sideways:
 * nothing wider than the screen, dialogs wholly on it, a roster's actions in
 * reach, fields that do not make iOS zoom, 44px targets, the period sheet in
 * landscape, a trend whose legend wraps instead of scrolling the widget, a row
 * of metric boxes that swipes, and a metric's description opened by a tap.
 *
 * Elements are found by role and label, then by data-ui; never by a styling
 * class (CONTRIBUTING.md).
 */

const S = FIXTURE.siteId;
const report = (id) => `?owa_do=base.report&owa_reportId=${id}&owa_siteId=${S}&owa_period=last_thirty_days`;

const PHONE = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true };
const LANDSCAPE = { viewport: { width: 844, height: 390 }, isMobile: true, hasTouch: true };

const SCREENS = {
    users: '?owa_do=base.users',
    'add a user': '?owa_do=base.usersProfile',
    'goal events': `?owa_do=base.goalEventDefinitions&owa_siteId=${S}`,
    'app passwords': '?owa_do=base.myAppPasswords',
    dashboard: report('dashboard'),
    pages: report('pages'),
    realtime: report('realtime'),
    'main configuration': '?owa_do=base.optionsGeneral',
};

/** Elements whose right edge is past the screen's, innermost first. */
async function pastTheEdge(page) {
    return page.evaluate(() => {
        const vw = document.documentElement.clientWidth;
        const out = [];

        for (const el of document.querySelectorAll('body *')) {
            const r = el.getBoundingClientRect();

            // Inside a box that scrolls sideways on purpose (a table, a code block) is not past the edge.
            let scroller = el.parentElement;
            while (scroller && !['auto', 'scroll'].includes(getComputedStyle(scroller).overflowX)) {
                scroller = scroller.parentElement;
            }

            if (r.width > 0 && r.right > vw + 1 && !scroller) {
                out.push(el.outerHTML.slice(0, 100));
            }
        }

        return { over: document.documentElement.scrollWidth - vw, first: out.slice(-3) };
    });
}

/** A finger dragging `dx` pixels sideways across `locator` (negative: towards the left). */
async function swipe(page, locator, dx) {
    await locator.scrollIntoViewIfNeeded();
    const box = await locator.boundingBox();
    const y = Math.round(box.y + box.height / 2);
    const x = Math.round(box.x + box.width * 0.75);
    const cdp = await page.context().newCDPSession(page);
    const touch = (type, at) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: at === null ? [] : [{ x: at, y }] });

    await touch('touchStart', x);
    for (let i = 1; i <= 10; i++) {
        await touch('touchMove', Math.round(x + (dx * i) / 10));
    }
    await touch('touchEnd', null);
    await cdp.detach();
}

/** The dashboard's first row of metric boxes, its boxes drawn. */
async function metricRow(page) {
    await page.goto(report('dashboard'), { waitUntil: 'networkidle' });
    const row = ui(page, 'kpi-row').first();
    await expect(row.locator('[data-ui^="kpi:"]').first()).toBeVisible({ timeout: 20_000 });
    return row;
}

/** Height and width of a control, as drawn. */
async function size(locator) {
    const box = await locator.boundingBox();
    return { w: Math.round(box.width), h: Math.round(box.height) };
}

for (const [name, device] of [['phone', PHONE], ['phone held sideways', LANDSCAPE]]) {

    test.describe(`on a ${name}`, () => {

        test.use(device);

        test.beforeEach(async ({ page }) => {
            await adminLogin(page);
        });

        test('no screen is wider than the screen', async ({ page }) => {
            test.setTimeout(180_000);

            for (const [screen, url] of Object.entries(SCREENS)) {
                await page.goto(url, { waitUntil: 'networkidle' });
                const o = await pastTheEdge(page);
                expect(o.over, `${screen} is ${o.over}px wider than the screen: ${o.first.join(' | ')}`).toBeLessThanOrEqual(0);
            }
        });

        /* The dialog was 440px wide, and on a phone its Delete was off the right edge. */
        test('a delete confirmation is wholly on the screen, Delete in reach', async ({ page }) => {
            const vw = device.viewport.width;
            const vh = device.viewport.height;

            await page.goto(SCREENS.users, { waitUntil: 'networkidle' });

            const row = ui(page, 'user-row').filter({ hasText: FIXTURE.profilePwUserId });
            await row.getByRole('link', { name: 'Delete' }).click();

            const dialog = page.getByRole('dialog', { name: 'Delete this user?' });
            await expect(dialog).toBeVisible();

            const box = await dialog.boundingBox();
            expect(box.x).toBeGreaterThanOrEqual(0);
            expect(box.y).toBeGreaterThanOrEqual(0);
            expect(box.x + box.width).toBeLessThanOrEqual(vw);
            expect(box.y + box.height).toBeLessThanOrEqual(vh);

            const proceed = dialog.getByRole('button', { name: 'Delete user' });
            const p = await proceed.boundingBox();
            expect(p.x + p.width, 'Delete is on the screen').toBeLessThanOrEqual(vw);
            expect(p.height).toBeGreaterThanOrEqual(44);

            await dialog.getByRole('button', { name: 'Cancel' }).click();
            await expect(dialog).toBeHidden();
        });

        /* The editor's gallery was 479px tall at y=-73 in a 390px-tall window. */
        test('the Add a widget dialog fits the window', async ({ page }) => {
            const vh = device.viewport.height;

            await page.goto(`?owa_do=base.customReportEdit&owa_siteId=${S}`, { waitUntil: 'networkidle' });
            await page.getByRole('button', { name: /Add a widget/ }).first().click();

            const dialog = page.getByRole('dialog', { name: 'Add a widget' });
            await expect(dialog).toBeVisible();

            const box = await dialog.boundingBox();
            expect(box.y).toBeGreaterThanOrEqual(0);
            expect(box.y + box.height).toBeLessThanOrEqual(vh);
        });

        test('a user\'s Edit and Delete are on the screen and 44px tall', async ({ page }) => {
            const vw = device.viewport.width;

            await page.goto(SCREENS.users, { waitUntil: 'networkidle' });

            const row = ui(page, 'user-row').filter({ hasText: FIXTURE.profilePwUserId });

            for (const label of ['Edit', 'Delete']) {
                const link = row.getByRole('link', { name: label });
                await link.scrollIntoViewIfNeeded();
                const box = await link.boundingBox();
                expect(box.x + box.width, `${label} is on the screen`).toBeLessThanOrEqual(vw);
                if (vw <= 600) {
                    expect(box.height, `${label} is a 44px target`).toBeGreaterThanOrEqual(44);
                }
            }
        });

        test('the header\'s buttons are 44px', async ({ page }) => {
            await page.goto(report('dashboard'), { waitUntil: 'networkidle' });

            for (const control of [
                page.getByRole('button', { name: 'Help' }),
                page.locator('#owa_notificationToggle'),
                page.getByRole('button', { name: /^Account/ }),
            ]) {
                const s = await size(control);
                expect(s.w, await control.getAttribute('aria-label')).toBeGreaterThanOrEqual(44);
                expect(s.h, await control.getAttribute('aria-label')).toBeGreaterThanOrEqual(44);
            }
        });

        test('a report grid\'s controls are 44px', async ({ page }) => {
            await page.goto(report('pages'), { waitUntil: 'networkidle' });

            const grid = ui(page, 'grid').last();
            await expect(ui(grid, 'grid-row').first()).toBeVisible({ timeout: 20_000 });

            const controls = {
                'row actions': ui(grid, 'grid-row-actions').first(),
                'sort heading': grid.locator('thead').getByRole('button', { name: 'Sessions', exact: true }),
                'rows per page': grid.getByRole('combobox', { name: 'Rows per page' }),
                'previous page': grid.getByRole('button', { name: 'Previous page' }),
                'next page': grid.getByRole('button', { name: 'Next page' }),
                granularity: page.getByRole('combobox', { name: /trend by hour, day or month/ }).first(),
            };

            for (const [what, control] of Object.entries(controls)) {
                if (!(await control.count())) {
                    continue;
                }
                const s = await size(control);
                expect(s.h, `${what} is 44px tall`).toBeGreaterThanOrEqual(44);
            }

            for (const what of ['previous page', 'next page']) {
                expect((await size(controls[what])).w, `${what} is 44px wide`).toBeGreaterThanOrEqual(44);
            }
        });

        test('a report\'s tabs are 44px tall', async ({ page }) => {
            await page.goto(report('browsers'), { waitUntil: 'networkidle' });

            const tabs = page.getByRole('tab');
            await expect(tabs.first()).toBeVisible({ timeout: 20_000 });

            for (const tab of await tabs.all()) {
                expect((await size(tab)).h).toBeGreaterThanOrEqual(44);
            }
        });

        /* One legend row per series pushed the widget wider than the screen. */
        test('a broken-out trend\'s legend wraps instead of scrolling the widget', async ({ page }) => {
            await page.goto(report('pages'), { waitUntil: 'networkidle' });

            const widget = page.locator('[data-ui^="widget:"]').filter({ has: page.locator('[data-ui^="chart:"]') }).first();
            await expect(widget.locator('[data-ui^="chart:"] canvas').first()).toBeVisible({ timeout: 20_000 });

            const sideways = await widget.evaluate((el) => el.scrollWidth - el.clientWidth);
            expect(sideways).toBeLessThanOrEqual(0);

            for (const entry of await widget.locator('[aria-pressed]').all()) {
                const box = await entry.boundingBox();
                expect(box.x + box.width, 'every legend entry is on the screen').toBeLessThanOrEqual(device.viewport.width);
            }
        });

        /*
         * The row of metric boxes over a trend scrolls under a finger. A swipe
         * chooses no box, and a touch screen has no arrows over the boxes.
         */
        test('the metric boxes swipe sideways, and a swipe charts nothing', async ({ page }) => {
            const row = await metricRow(page);
            const chart = page.locator('[data-ui^="chart:"]').first();
            await expect(chart).toHaveAttribute('aria-label', /./, { timeout: 20_000 });
            const charted = await chart.getAttribute('aria-label');

            expect(await row.evaluate((el) => el.scrollWidth - el.clientWidth),
                'the dashboard\'s boxes fit, so the swipe proves nothing').toBeGreaterThan(10);

            await expect(ui(page, 'kpi-next').first()).toBeHidden();
            await expect(ui(page, 'kpi-prev').first()).toBeHidden();

            await swipe(page, row, -200);
            await expect.poll(() => row.evaluate((el) => el.scrollLeft)).toBeGreaterThan(20);

            expect(await chart.getAttribute('aria-label'), 'the swipe charted a box').toBe(charted);
        });

        test('a notification\'s Dismiss is shown, a 44px target', async ({ page }) => {
            await page.goto(report('dashboard'), { waitUntil: 'networkidle' });
            await page.locator('#owa_notificationToggle').click();

            const dismiss = page.getByRole('button', { name: 'Dismiss notification' }).first();
            await expect(dismiss).toBeVisible();
            expect(await dismiss.evaluate((el) => getComputedStyle(el).opacity)).toBe('1');

            const s = await size(dismiss);
            expect(s.w).toBeGreaterThanOrEqual(44);
            expect(s.h).toBeGreaterThanOrEqual(44);
        });
    });
}

test.describe('on a phone', () => {

    test.use(PHONE);

    test('every field is 16px, so focusing it does not zoom the page', async ({ page }) => {
        await page.goto('?owa_do=base.loginForm', { waitUntil: 'networkidle' });

        for (const field of [page.getByLabel('User name'), page.getByLabel('Password')]) {
            expect(await field.evaluate((el) => getComputedStyle(el).fontSize)).toBe('16px');
            expect((await size(field)).h).toBeGreaterThanOrEqual(44);
        }

        await adminLogin(page);

        for (const url of [SCREENS['add a user'], SCREENS['main configuration']]) {
            await page.goto(url, { waitUntil: 'networkidle' });

            const small = await page.evaluate(() => [...document.querySelectorAll('input, select, textarea')]
                .filter((el) => el.offsetParent !== null && !['checkbox', 'radio', 'hidden', 'submit', 'button'].includes(el.type))
                .map((el) => ({ name: el.name || el.id, size: parseFloat(getComputedStyle(el).fontSize) }))
                .filter((f) => f.size < 16));

            expect(small, `${url}: fields under 16px`).toEqual([]);
        }
    });

    test('the Property chooser\'s Edit is shown without a hover, a 44px target', async ({ page }) => {
        await adminLogin(page);
        await page.goto('?owa_do=base.optionsGeneral', { waitUntil: 'networkidle' });
        await page.getByRole('button', { name: 'Show the menu' }).click();
        await page.locator('#owa_siteControlSummary').click();

        const edit = page.locator('#owa_siteControl').getByRole('link', { name: 'edit', exact: true }).first();
        await expect(edit).toBeVisible();
        expect(await edit.evaluate((el) => getComputedStyle(el).opacity)).toBe('1');
        expect((await size(edit)).h).toBeGreaterThanOrEqual(44);
    });

    test('a grid wider than the phone says so at its edge', async ({ page }) => {
        await adminLogin(page);
        await page.goto(report('pages'), { waitUntil: 'networkidle' });

        const scroll = ui(page, 'grid-scroll').last();
        await expect(ui(scroll, 'grid-row').first()).toBeVisible({ timeout: 20_000 });
        await expect(scroll).toHaveClass(/has-more-right/);

        // No row numbers on a phone: a row starts with its page.
        expect(await ui(scroll, 'grid-row').first().getByRole('cell').first().textContent()).not.toMatch(/^\s*\d+\s*$/);

        await scroll.locator('div').first().evaluate((el) => { el.scrollLeft = el.scrollWidth; });
        await expect(scroll).toHaveClass(/has-more-left/);
        await expect(scroll).not.toHaveClass(/has-more-right/);

        const lead = await ui(scroll, 'grid-row').first().getByRole('cell').first().boundingBox();
        expect(lead.x).toBeGreaterThanOrEqual(0);
        expect(lead.x).toBeLessThan(40);
    });

    /*
     * About 2.4 boxes to a row: two whole ones and part of the next at the
     * edge, which is what says the row goes on.
     */
    test('a metric box shows in part at the row\'s edge, and a tap still charts a box', async ({ page }) => {
        await adminLogin(page);
        const row = await metricRow(page);

        const edge = await row.evaluate((el) => {
            const r = el.getBoundingClientRect();
            return [...el.querySelectorAll('[data-ui^="kpi:"]')].map((b) => {
                const x = b.getBoundingClientRect();
                return { whole: x.left >= r.left - 1 && x.right <= r.right + 1, shown: Math.min(x.right, r.right) - Math.max(x.left, r.left) };
            });
        });

        expect(edge.filter((b) => b.whole).length, 'two whole boxes').toBeGreaterThanOrEqual(2);
        const part = edge.find((b) => !b.whole && b.shown > 0);
        expect(part, 'no box shows in part at the edge').toBeTruthy();
        expect(part.shown, 'the part of the next box is too thin to see').toBeGreaterThanOrEqual(30);

        const chart = page.locator('[data-ui^="chart:"]').first();
        await expect(chart).toHaveAttribute('aria-label', /./, { timeout: 20_000 });
        const before = await chart.getAttribute('aria-label');

        await row.locator('[data-ui^="kpi:"]').nth(1).tap();
        await expect.poll(() => chart.getAttribute('aria-label')).not.toBe(before);
    });

    /*
     * A heading's description is behind its own button, opened by a tap: it
     * shows on the screen and the column's sort stays as it was.
     */
    test('a heading\'s (i) shows the description on the screen and does not sort', async ({ page }) => {
        await adminLogin(page);
        await page.goto(report('pages'), { waitUntil: 'networkidle' });

        const grid = ui(page, 'grid').last();
        await expect(ui(grid, 'grid-row').first()).toBeVisible({ timeout: 20_000 });

        const heading = grid.getByRole('columnheader').filter({ has: page.getByRole('button', { name: /^About / }) }).first();
        const info = heading.getByRole('button', { name: /^About / });
        const sort = await heading.getAttribute('aria-sort');

        await info.scrollIntoViewIfNeeded();
        const s = await size(info);
        expect(s.w).toBeGreaterThanOrEqual(44);
        expect(s.h).toBeGreaterThanOrEqual(44);

        await info.tap();

        const text = ui(page, 'metric-info-text');
        await expect(text).toBeVisible();
        await expect(text).not.toBeEmpty();
        await expect(info).toHaveAttribute('aria-expanded', 'true');

        const box = await text.boundingBox();
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(PHONE.viewport.width);

        await page.waitForTimeout(500);
        expect(await heading.getAttribute('aria-sort'), 'the (i) sorted the column').toBe(sort);

        await page.getByRole('heading', { level: 1 }).first().tap();
        await expect(text).toBeHidden();
        await expect(info).toHaveAttribute('aria-expanded', 'false');
    });

    test('refused a page, a signed-in user still has the menu', async ({ page }) => {
        await login(page);
        await page.goto('?owa_do=base.optionsGeneral', { waitUntil: 'networkidle' });

        await expect(page.getByText('Your account does not have access to this page.')).toBeVisible();
        await page.getByRole('button', { name: 'Show the menu' }).click();
        await expect(ui(page, 'nav:Dashboard')).toBeVisible();
    });
});

test.describe('on a phone held sideways', () => {

    test.use(LANDSCAPE);

    /* The desktop panel opened off the bottom of a 390px-tall window. */
    test('the period picker is the full-screen sheet, its days 44px', async ({ page }) => {
        await adminLogin(page);
        await page.goto(report('pages'), { waitUntil: 'networkidle' });
        await ui(page, 'period-trigger').click();

        const sheet = page.getByRole('dialog', { name: 'Date range' });
        await expect(sheet).toBeVisible();

        expect(await sheet.boundingBox()).toEqual({ x: 0, y: 0, width: 844, height: 390 });

        const day = sheet.locator('[data-d]:visible').first();
        await day.scrollIntoViewIfNeeded();
        expect((await size(day)).h).toBeGreaterThanOrEqual(44);

        const apply = await sheet.getByRole('button', { name: 'Apply' }).boundingBox();
        expect(apply.y + apply.height).toBeLessThanOrEqual(390);
    });
});
