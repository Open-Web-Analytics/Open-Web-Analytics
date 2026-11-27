const { test, expect } = require('@playwright/test');
const { FIXTURE, login, openReport, openReportNoTabs } = require('./fixtures');

/**
 * E-commerce reporting: the tabs, and the commerce reports themselves.
 *
 * WHY THIS EXISTS
 * ---------------
 * enableEcommerceReporting is a PER-PROPERTY setting, and the install-wide value
 * is false. Reading the wrong one is a silent failure: the report still renders,
 * it just quietly leaves the e-commerce columns out, and every page still
 * returns 200.
 *
 * WHICH REPORTS GET TABS
 * ----------------------
 * Tabs are a property of the REPORT DEFINITION, not of the setting.
 * report_widgets.php draws one tab per metric set unless the definition names
 * its own `metrics`:
 *
 *   browsers.json -- no `metrics`, measured by the sets    TABS
 *   pages.json    -- `metrics`: pageViews,sessions         none
 *
 * That split is deliberate and is not a bug: the tabbed reports are
 * session-based (Site Usage and e-commerce are per-visit
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

        // Each tab names its panel, <div id="tab_<key>">: the set's key.
        const tabIds = await page.getByRole('tab').evaluateAll(
            els => els.map(e => e.getAttribute('aria-controls').replace(/^tab_/, ''))
        );

        expect(tabIds).toContain('site_usage');
        expect(tabIds).toContain('ecommerce');
    });

    /**
     * The tab labels are literals in MetricSets. A row of tabs reading
     * "Site Usage | e-commerce" shows its seams.
     *
     * Title-casing is a PRESENTATION rule (text-transform on the tab), which
     * is why this reads innerText: innerText is the rendered text and reflects
     * text-transform, textContent is the source string and does not. Asserting
     * on textContent here would pass whether the rule existed or not.
     */
    test('tab labels are title-cased however the label was written', async ({ page }) => {
        await openReport(page, { reportId: 'browsers' });

        const labels = await page.getByRole('tab')
            .evaluateAll(els => els.map(e => e.innerText.trim()));

        expect(labels).toContain('Site Usage');

        expect(labels).toContain('Ecommerce');
        expect(labels).not.toContain('e-commerce');
    });

    /**
     * The selected tab is underlined in the same blue the trend beside it draws
     * its total in. Two blues a few pixels apart read as an accident rather
     * than a choice.
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

        const active = page.getByRole('tab', { selected: true });
        await expect(active).toHaveCount(1);

        // Underlined in that blue, and its label in it too.
        const rule = await active.evaluate(el => getComputedStyle(el).borderBottomColor);
        expect(rule).toBe('rgb(24, 116, 205)');

        const fg = await active.evaluate(el => getComputedStyle(el).color);
        expect(fg).toBe('rgb(24, 116, 205)');
    });

    test('the tabbed and untabbed report families stay as they are', async ({ page }) => {
        // Session-based -> tabs.
        await openReport(page, { reportId: 'browsers' });
        await expect(page.locator('#report-tabs > div[id^="tab_"]').first()).toBeAttached();

        // Content-based -> deliberately no tabs (pages.json names its own metrics).
        // Uses the no-tabs helper: openReport() waits for the tab list, which
        // these pages never draw.
        await openReportNoTabs(page, { reportId: 'pages' });
        await expect(page.locator('#report-tabs > div[id^="tab_"]')).toHaveCount(0);
    });

    test('Campaigns includes e-commerce metrics, which requires reading the SITE setting', async ({ page }) => {
        // The columns come from the metrics string, so their absence is the
        // observable symptom of reading the wrong (install-wide) setting.
        await openReport(page, { reportId: 'campaigns' });

        const body = await page.content();
        expect(body).toContain('transactionRevenue');
        expect(body).toContain('transactions');
    });

    /*
     * The Products report is not here: v2 stores a purchase's items as JSON in
     * params, and item reporting is to be designed separately. There is no
     * Products report to check until it is.
     */

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
        // its transactions to the sessions seeded for the same day.
        expect(text).toMatch(new RegExp(EXPECTED.totalRevenue.toFixed(2).replace('.', '\\.')));
        expect(text).toContain('Transactions');
        expect(text).toContain('Revenue Per Session');
    });

    // An unresolvable sort comes back as a null sortColumn/sortOrder, which
    // throws while building the grid. The page still renders, so the only trace
    // is the browser console.
    test('no report page raises a browser console error', async ({ page }) => {
        // The dimension-resolution warning that started this work was invisible
        // in the browser -- it only reached the server log. This catches the
        // client-side equivalent across the commerce reports.
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));

        for (const reportId of ['ecommerce', 'transactions']) {
            await openReportNoTabs(page, { reportId });
        }

        expect(errors).toEqual([]);
    });

    /**
     * A money axis is labelled in money, in the install's own currency.
     *
     * Two things this pins. Revenue is stored in MINOR units and the chart's
     * formatValue is what divides it by a hundred. And the
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
