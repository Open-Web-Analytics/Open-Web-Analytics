const { test, expect } = require('@playwright/test');
const { FIXTURE, login, openReport, openReportNoTabs } = require('./fixtures');

/**
 * E-commerce reporting: the tabs, and the commerce reports themselves.
 *
 * WHY THIS EXISTS
 * ---------------
 * enableEcommerceReporting is a PER-SITE setting. There is a base setting of the
 * same name which has been false since it was introduced -- it carries a "move
 * to site settings" note -- and reading the wrong one is a silent failure: the
 * report still renders, it just quietly leaves the e-commerce columns out. That
 * is exactly what ReportCampaigns did until this suite was written, and nothing
 * caught it because every page still returned 200.
 *
 * WHICH REPORTS GET TABS
 * ----------------------
 * Tabs are a property of the SUBVIEW, not of the setting:
 *
 *   base.reportDimension       -> report_dimensionalTrend.php     TABS
 *   base.reportDimensionDetail -> report_dimensionDetail.php      TABS
 *   base.reportSimpleDimensional -> report_dimensionDetailNoTabs.php   none
 *
 * That split is deliberate and is not a bug: the tabbed reports are
 * session-based (Site Usage / e-commerce / goal groups are all per-visit
 * metrics), while the untabbed ones are content-based, where a session tab
 * would be meaningless. These tests pin the split so "fixing" one side of it
 * has to be a deliberate act.
 *
 * The fixture site has e-commerce enabled and two seeded transactions -- see
 * seed_reporting_fixtures.php, which also writes currency in CENTS because the
 * fact columns are BIGINT and the real handler multiplies by 100.
 */

// Dollar totals implied by the seeded fixture (E2E_TXNS).
const EXPECTED = {
    orderCount:     2,
    totalRevenue:   63.00,   // 42.60 + 20.40
    lineItemRevenue: 46.30,  // (10.20*2) + 5.50 + (10.20*2)
    widgetRevenue:  40.80,   // E2E Widget across both orders
    gadgetRevenue:   5.50,
};

test.describe('e-commerce reporting', () => {

    test.beforeEach(async ({ page }) => {
        await login(page);
    });

    test('the e-commerce tab appears on a session-based report when the site has it enabled', async ({ page }) => {
        await openReport(page, { reportId: 'browsers' });

        // Tabs render as <div id="tab_<key>"> -- the label itself is written
        // into the JS block below them, so the div id is the stable hook.
        const tabIds = await page.locator('#report-tabs > div[id^="tab_"]').evaluateAll(
            els => els.map(e => e.id.replace(/^tab_/, ''))
        );

        expect(tabIds).toContain('site_usage');
        expect(tabIds).toContain('ecommerce');
    });

    /**
     * The tab labels come from three places -- two literals in MetricSets and
     * whatever a site owner typed into a goal group's name -- and one of the
     * literals is lower case. A row of tabs reading "Site Usage | e-commerce"
     * shows its seams.
     *
     * Title-casing is a PRESENTATION rule (text-transform on the anchor), which
     * is why this reads innerText: innerText is the rendered text and reflects
     * text-transform, textContent is the source string and does not. Asserting
     * on textContent here would pass whether the rule existed or not.
     */
    test('tab labels are title-cased however the label was written', async ({ page }) => {
        await openReport(page, { reportId: 'browsers' });

        const labels = await page.locator('#report-tabs > .report-tabs-nav-list li a')
            .evaluateAll(els => els.map(e => e.innerText.trim()));

        expect(labels).toContain('Site Usage');

        // 'e-commerce' as MetricSets writes it; 'E-Commerce' as it must read.
        expect(labels).toContain('E-Commerce');
        expect(labels).not.toContain('e-commerce');
    });

    /**
     * The selected tab is painted the same blue the trend beside it draws its
     * total in. jQuery-UI's base theme paints an active widget #007fff, and two
     * blues a few pixels apart read as an accident rather than a choice.
     *
     * #1874CD is OWA.areaChart's totalColor -- read off the chart rather than
     * written down here, so the two cannot drift apart without this failing.
     */
    test('the active tab carries the trend blue', async ({ page }) => {
        await openReport(page, { reportId: 'browsers' });

        // Constructed, not read off the prototype: the defaults are assigned
        // to `this.options` in the constructor body.
        const trendBlue = await page.evaluate(
            () => new OWA.areaChart().options.totalColor
        );
        expect(trendBlue.toLowerCase()).toBe('#1874cd');

        const active = page.locator('#report-tabs > .report-tabs-nav-list li.ui-tabs-active');
        await expect(active).toHaveCount(1);

        const bg = await active.evaluate(el => getComputedStyle(el).backgroundColor);
        expect(bg).toBe('rgb(24, 116, 205)');

        // White on that blue -- .ui-state-active sets a colour on the LI and the
        // anchor inside it carries its own, so both have to be said.
        const fg = await active.locator('a').evaluate(el => getComputedStyle(el).color);
        expect(fg).toBe('rgb(255, 255, 255)');
    });

    test('the tabbed and untabbed report families stay as they are', async ({ page }) => {
        // Session-based -> tabs.
        await openReport(page, { reportId: 'browsers' });
        await expect(page.locator('#report-tabs > div[id^="tab_"]').first()).toBeAttached();

        // Content-based -> deliberately no tabs (report_dimensionDetailNoTabs).
        // Uses the no-tabs helper: openReport() waits for #report-tabs.ui-tabs,
        // a widget these pages never build.
        await openReportNoTabs(page, { reportId: 'pages' });
        await expect(page.locator('#report-tabs > div[id^="tab_"]')).toHaveCount(0);
    });

    test('Campaigns includes e-commerce metrics, which requires reading the SITE setting', async ({ page }) => {
        // ReportCampaigns used owa_coreAPI::getSetting('base', ...) -- the global
        // -- so transactions/transactionRevenue were never added to its metric
        // list no matter how the site was configured. The columns come from the
        // metrics string, so their absence is the observable symptom.
        await openReport(page, { reportId: 'campaigns' });

        const body = await page.content();
        expect(body).toContain('transactionRevenue');
        expect(body).toContain('transactions');
    });

    test('the Products report returns the seeded line items with correct revenue', async ({ page }) => {
        await openReportNoTabs(page, { reportId: 'products' });

        const grid = page.locator('.ui-jqgrid');
        await expect(grid).toBeAttached();

        const text = await page.locator('body').innerText();

        // Both seeded products, and the revenue split between them. Getting the
        // cents/dollars conversion wrong in either the seeder or the formatter
        // shows up here as an order-of-magnitude error rather than a near miss.
        expect(text).toContain('E2E Widget');
        expect(text).toContain('E2E Gadget');
        expect(text).toMatch(new RegExp(EXPECTED.widgetRevenue.toFixed(2).replace('.', '\\.')));
        expect(text).toMatch(new RegExp(EXPECTED.gadgetRevenue.toFixed(2).replace('.', '\\.')));
    });

    test('the Transactions report returns the seeded orders', async ({ page }) => {
        await openReportNoTabs(page, { reportId: 'transactions' });

        const text = await page.locator('body').innerText();

        for (const orderId of FIXTURE.order_ids || ['e2e-order-1001', 'e2e-order-1002']) {
            expect(text).toContain(orderId);
        }
    });

    test('the e-commerce overview report renders the seeded totals', async ({ page }) => {
        await openReportNoTabs(page, { reportId: 'ecommerce' });

        const text = await page.locator('body').innerText();

        // This report reads commerce totals off the SESSION, not off the fact
        // tables -- SessionCommerceSummaryHandlers rolls them up onto the
        // session row when a transaction arrives. The fixture therefore attaches
        // its transactions to the sessions seeded for the same day and runs the
        // SAME owa_coreAPI::summarize() calls the handler uses, so these totals
        // are the ones the application would have written itself.
        expect(text).toMatch(new RegExp(EXPECTED.totalRevenue.toFixed(2).replace('.', '\\.')));
        expect(text).toContain('Transactions');
        expect(text).toContain('Revenue Per Visit');
    });

    // Regression test for a real bug this suite found.
    //
    // base.reportEcommerce set sort='actions' -- neither a metric it queries nor
    // a dimension it groups on, and the report has no dimensions at all. The
    // unresolvable sort came back as a null sortColumn/sortOrder, which
    // setGridOptions handed to jqGrid, which called .toLowerCase() on it:
    // "Cannot read properties of null (reading 'toLowerCase')", thrown while
    // building the grid. The page still rendered, so the only trace was the
    // browser console -- which nothing was watching.
    test('no report page raises a browser console error', async ({ page }) => {
        // The dimension-resolution warning that started this work was invisible
        // in the browser -- it only reached the server log. This catches the
        // client-side equivalent across the commerce reports.
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));

        for (const reportId of ['ecommerce', 'products', 'transactions']) {
            await openReportNoTabs(page, { reportId });
        }

        expect(errors).toEqual([]);
    });

    /**
     * A money axis is labelled in money, in the install's own currency.
     *
     * Two things this pins, and the second is why the first was wrong for a
     * while. Revenue is stored in MINOR units and the chart's formatValue is
     * what divides it by a hundred -- dropping that call plotted 6300 for
     * $63.00, which nothing noticed until an axis put a unit on it. And the
     * symbol comes from the server's own formatting of the metric: which
     * currency an install uses is a setting the browser cannot see.
     */
    test('a currency trend is labelled in currency', async ({ page }) => {
        await login(page);

        await page.goto(
            `?owa_do=base.report&owa_reportId=ecommerce&owa_siteId=${FIXTURE.siteId}&owa_period=last_thirty_days`,
            { waitUntil: 'networkidle' });

        // The trend on this report, whichever variable it was built into.
        await expect.poll(async () => page.evaluate(() => !!Object.keys(window).find(
            (k) => window[k] && window[k].areaChart
                && typeof window[k].areaChart.chartedMetric === 'function')),
            { timeout: 20_000 }).toBe(true);

        const money = await page.evaluate(() => {

            const key = Object.keys(window).find(
                (k) => window[k] && window[k].areaChart
                    && typeof window[k].areaChart.chartedMetric === 'function');

            const rse = window[key];

            const currency = Object.keys(rse.resultSet.aggregates).find(
                (m) => rse.resultSet.aggregates[m].data_type === 'currency');

            rse.areaChart.changeMetric(currency);

            return {
                metric: currency,
                aggregate: rse.resultSet.aggregates[currency].formatted_value,
                ticks: [...document.querySelectorAll('.flot-y-axis .flot-tick-label')]
                    .map((e) => e.textContent.trim()),
                plotted: rse.areaChart.dataseries[0].data.map((p) => p[1]),
            };
        });

        expect(money.metric).toBeTruthy();
        expect(money.ticks.length).toBeGreaterThan(1);

        // The symbol the server used, on the side it used it.
        const symbol = money.aggregate.replace(/[0-9., -]/g, '');
        expect(symbol.length).toBeGreaterThan(0);
        expect(money.ticks.every((t) => t.includes(symbol))).toBe(true);

        // ...and in MAJOR units: nothing plotted may exceed the period total,
        // which is the check that catches an off-by-one-hundred.
        const total = parseFloat(money.aggregate.replace(/[^0-9.]/g, ''));
        expect(Math.max(...money.plotted)).toBeLessThanOrEqual(total + 0.001);
    });
});
