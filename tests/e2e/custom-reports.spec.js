// @ts-check
/**
 * Custom reports: build one, save it, open it, and find it on the roster.
 *
 * WHAT ONLY THIS SUITE CAN CHECK
 *
 * The access rules. `view_reports` is listed in
 * capabilitiesThatRequireSiteAccess, so passing it needs a REAL user with a
 * real grant on the site being looked at. A unit test can give a user a role
 * but not a grant, so the interesting cases -- an analyst opening a report an
 * admin built, and an analyst being refused the builder -- can only be driven
 * here, where the fixture users are real.
 *
 * The other half is the builder itself: it assembles the report definition in
 * the browser and posts it as one field, so nothing server-side ever sees the
 * form's shape. If the assembly is wrong, every server test still passes.
 */
const { test, expect } = require('@playwright/test');
const { FIXTURE, login, loginAs } = require('./fixtures');

/** A name unique to this run, so reruns do not collide on the roster. */
const reportName = (label) => `E2E ${label} ${Date.now()}`;

/**
 * The builder, opened ON THE FIXTURE SITE.
 *
 * The site matters: makeLink carries the current request's params, so the site
 * the author is looking at travels into the form, into the save, and into the
 * URL the author lands on -- which is the URL they then share. Building on a
 * site the recipient cannot see produces a link that only works for its author.
 */
async function openBuilder(page) {
    await page.goto(`?owa_do=base.customReportEdit&owa_siteId=${FIXTURE.siteId}`,
        { waitUntil: 'networkidle' });
    await page.waitForSelector('#customReportForm', { timeout: 20_000 });
}

async function openRoster(page) {
    await page.goto('?owa_do=base.customReports', { waitUntil: 'networkidle' });
}

/**
 * Build and save a report with one grid widget.
 * @returns {Promise<string>} the name it was saved under
 */
async function buildOne(page, label) {
    const name = reportName(label);

    await openBuilder(page);
    await page.fill('#customReportName', name);

    // The first widget row is drawn for a new report, so there is always
    // something to configure without pressing Add first.
    const widget = page.locator('.owa_customWidget').first();

    await widget.locator('.widgetType').selectOption('grid');
    await widget.locator('.widgetMetrics').selectOption(['pageViews']);
    await widget.locator('.widgetDimensions').selectOption(['pagePath']);

    await page.click('input[type=submit]');
    await page.waitForLoadState('networkidle');

    return name;
}

test.describe('custom reports', () => {

    test.describe('as an author', () => {

        test.beforeEach(async ({ page }) => {
            await loginAs(page, FIXTURE.adminUserId, FIXTURE.adminPassword);
        });

        test('the builder offers the four widget types and nothing else', async ({ page }) => {
            await openBuilder(page);

            const types = await page.locator('.owa_customWidget .widgetType option')
                .evaluateAll((opts) => opts.map((o) => o.getAttribute('value')));

            expect(types.sort()).toEqual(['grid', 'metric-boxes', 'pie', 'trend']);
        });

        /**
         * The choices come from the reporting registry, not from a list in the
         * template -- so a name the author picks is one the validator will
         * accept. An empty picker would make every save fail for a reason the
         * author could not see.
         */
        test('the pickers are populated from the registry', async ({ page }) => {
            await openBuilder(page);

            const widget = page.locator('.owa_customWidget').first();

            expect(await widget.locator('.widgetMetrics option').count()).toBeGreaterThan(10);
            expect(await widget.locator('.widgetDimensions option').count()).toBeGreaterThan(10);

            // Named options, not just a count: the value is what reaches the
            // definition, so it has to be the registry NAME.
            await expect(widget.locator('.widgetMetrics option[value="pageViews"]')).toHaveCount(1);
            await expect(widget.locator('.widgetDimensions option[value="pagePath"]')).toHaveCount(1);
        });

        test('a report can be built, saved, and opens showing its own name', async ({ page }) => {
            const name = await buildOne(page, 'Built');

            // Saving lands on the report itself -- the author's next question
            // is always whether it looks right.
            await expect(page.locator('body')).toContainText(name);

            expect(page.url()).toContain('custom-');
        });

        test('the saved report appears on the roster with its author', async ({ page }) => {
            const name = await buildOne(page, 'Rostered');

            await openRoster(page);

            const row = page.locator('table.management tr').filter({ hasText: name });

            await expect(row).toHaveCount(1);
            await expect(row).toContainText(FIXTURE.adminUserId);
        });

        /** The roster link is the shareable URL, and it must actually open. */
        test('the roster links to the report', async ({ page }) => {
            const name = await buildOne(page, 'Linked');

            await openRoster(page);

            await page.locator('table.management a').filter({ hasText: name }).click();
            await page.waitForLoadState('networkidle');

            expect(page.url()).toContain('custom-');
            await expect(page.locator('body')).toContainText(name);
        });

        /**
         * A custom report gets the SAME chrome as every other report, because
         * it renders through the same dispatcher rather than supplying its own.
         */
        test('a custom report has the site filter and the date picker', async ({ page }) => {
            await buildOne(page, 'Chrome');

            await expect(page.locator('#owa_reportPeriodLabelContainer')).toHaveCount(1);
            await expect(page.locator('#owa_reportSiteFilter')).toHaveCount(1);
        });

        /**
         * The cap is enforced in the browser too, so an author is stopped at
         * ten rather than told at save time that the eleventh was too many.
         */
        test('the builder stops at ten widgets', async ({ page }) => {
            await openBuilder(page);

            for (let i = 1; i < 10; i++) {
                await page.click('#addWidget');
            }

            await expect(page.locator('.owa_customWidget')).toHaveCount(10);
            await expect(page.locator('#addWidget')).toBeDisabled();
        });

        /**
         * A definition naming something that does not resolve is refused, and
         * the author is told which name -- with what they typed still in the
         * form, because a redirect would hand back an empty one.
         */
        test('an unresolvable name is refused by name, and the work is kept', async ({ page }) => {
            const name = reportName('Refused');

            await openBuilder(page);
            await page.fill('#customReportName', name);

            // Reach past the pickers: a sort is free text, which is the field
            // an author can put an unresolvable name into.
            const widget = page.locator('.owa_customWidget').first();
            await widget.locator('.widgetMetrics').selectOption(['pageViews']);
            await widget.locator('.widgetSort').fill('notARealMetric-');

            await page.click('input[type=submit]');
            await page.waitForLoadState('networkidle');

            await expect(page.locator('.notice')).toContainText('notARealMetric');

            await expect(page.locator('#customReportName')).toHaveValue(name);
        });
    });

    test.describe('as a reader who cannot author', () => {

        test.beforeEach(async ({ page }) => {
            await login(page);   // the analyst fixture user
        });

        /**
         * edit_reports is admin-only by default, and an analyst does not have
         * it. This is the case the unit tests cannot reach: the analyst is a
         * REAL user with a REAL site grant, so being refused here is about
         * edit_reports and not about site access.
         */
        test('the builder is refused', async ({ page }) => {
            await page.goto('?owa_do=base.customReportEdit', { waitUntil: 'networkidle' });

            await expect(page.locator('#customReportForm')).toHaveCount(0);
        });

        test('the roster opens, without the authoring controls', async ({ page }) => {
            await openRoster(page);

            // The page is theirs to read -- asserted on the roster's own table
            // or its empty-state text, not on a string the NAV also contains.
            await expect(page.locator('table.management, .owa_reportSectionContent').first())
                .toBeVisible();

            // ...but there is nothing on it to author with.
            await expect(page.locator('a:has-text("New Custom Report")')).toHaveCount(0);
        });
    });

    /**
     * The whole point of "shareable by url": a report built by one person opens
     * for another, who could not have built it themselves.
     *
     * The second reader gets their own CONTEXT rather than the first one's with
     * the cookies cleared. Clearing cookies mid-test leaves the page on a
     * document that belongs to the previous session, and the next request goes
     * out from it; a fresh context is how Playwright models "a different person
     * at a different computer", which is what is being tested.
     */
    test('a report built by an admin opens for an analyst who was sent the link',
        async ({ page, browser }) => {

        await loginAs(page, FIXTURE.adminUserId, FIXTURE.adminPassword);

        const name = await buildOne(page, 'Shared');

        // The URL the author lands on IS the share link, and it carries the
        // site -- view_reports is only ever satisfied against a particular one.
        const url = page.url();

        expect(url).toContain('siteId');

        const reader = await browser.newContext();
        const theirs = await reader.newPage();

        try {
            await login(theirs);
            await theirs.goto(url, { waitUntil: 'networkidle' });

            await expect(theirs.locator('body')).toContainText(name);
        } finally {
            await reader.close();
        }
    });

    /**
     * ...and the roster still filters by ownership, so the analyst does not see
     * it LISTED. Sharing is by link; the roster is what you made.
     */
    test('a shared report is not listed on the roster of someone who did not make it',
        async ({ page, browser }) => {

        await loginAs(page, FIXTURE.adminUserId, FIXTURE.adminPassword);

        const name = await buildOne(page, 'NotListed');

        const reader = await browser.newContext();
        const theirs = await reader.newPage();

        try {
            await login(theirs);
            await openRoster(theirs);

            await expect(theirs.locator('table.management tr').filter({ hasText: name }))
                .toHaveCount(0);
        } finally {
            await reader.close();
        }
    });
});
