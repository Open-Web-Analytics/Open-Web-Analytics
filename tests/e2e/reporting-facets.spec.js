// @ts-check
/**
 * The reporting engine, exercised on its facets against known numbers.
 *
 * WHY THIS EXISTS
 * The existing reporting spec asserts that the dashboard RENDERS -- grids
 * present, dropdown sprites loaded, column widths aligned -- and its only
 * numeric assertion is `expect(gridText).toMatch(/\b2\b/)`, which passes if the
 * digit 2 appears anywhere on the page. Every number on every report could be
 * wrong and it would stay green.
 *
 * It did. Varnish was stripping owa_source from the request before PHP ever saw
 * it, so the Source Detail report ran with NO constraint and showed the same
 * unfiltered total for google, facebook and everything else. A person noticed;
 * the suite did not. These are the assertions that would have failed.
 *
 * WHAT IT COVERS
 * The facets a report is actually made of, rather than any particular report:
 * constraints, sorts, secondary dimensions, and reconciliation between the
 * dimensional rows and the aggregate. There are 69 report controllers and
 * ~60 are pure declaration -- metrics, dimensions, sort, a link template -- so
 * testing the engine's facets covers what all of them do, and one bespoke
 * report per facet would not.
 *
 * Asserted through the REST API rather than the UI: it is the same engine the
 * grids read from, and the numbers are the thing under test. One case at the end
 * does go through the rendered page, because the bug that prompted this lived in
 * the URL the page emits, not in the engine.
 */
const path = require('path');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const { test, expect } = require('@playwright/test');

const HELPER = path.join(__dirname, 'reporting_facets_helper.php');
const SELFHOST = process.env.OWA_E2E_SELFHOST === '1';

function helper(...args) {
    return JSON.parse(execFileSync('php', [HELPER, ...args], { encoding: 'utf8' }));
}

function installRoot(baseURL) {
    return baseURL.replace(/index\.php.*$/, '');
}

test.describe('the reporting engine answers correctly on every facet @selfhost-only', () => {

    test.skip(!SELFHOST, 'Seeds sessions with a known dimension distribution.');

    /** @type {any} */ let fx;
    /** @type {string} */ let apiRoot;

    test.beforeAll(() => {
        fx = helper('provision');
        expect(fx.expected.total_visits, 'fixture seeded nothing').toBeGreaterThan(0);
        expect(fx.api_key, 'fixture user has no api key').toBeTruthy();
    });

    test.afterAll(() => { helper('cleanup'); });

    test.beforeEach(({}, testInfo) => {
        apiRoot = installRoot(testInfo.project.use.baseURL);
    });

    /** Sign a request the way Auth::isSignatureValid recomputes it. */
    function sign(url, apiKey, authKey) {
        const date = new Date().toISOString().slice(0, 10).replace(/-/g, '');
        return Buffer.from(
            crypto.createHash('sha256')
                .update('OWASIGNATURE' + apiKey + url + date + authKey)
                .digest('hex')
        ).toString('base64');
    }

    /** Query the reports API and return the parsed resultset. */
    async function report(request, params) {
        const q = new URLSearchParams(Object.assign({
            owa_do: 'reports',
            owa_module: 'base',
            owa_version: 'v1',
            owa_siteId: fx.site_id,
            owa_period: 'today',
            owa_apiKey: fx.api_key,
        }, params));

        // Signed over its own URL, apiKey included, signature appended last --
        // the order Auth::isSignatureValid recomputes.
        const unsigned = apiRoot + 'api/index.php?' + q.toString();
        const url = unsigned + '&owa_signature=' + encodeURIComponent(
            sign(unsigned, fx.api_key, fx.auth_key)
        );

        const res = await request.fetch(url, { method: 'GET' });
        const body = await res.text();

        /*
         * 422 is a legitimate answer, not a transport failure: a request the
         * engine refuses to run -- an unknown constraint name, a constraint
         * whose value went missing -- is reported as one rather than answered
         * with numbers computed without the filter. The tests below assert on
         * which of the two came back.
         */
        expect([201, 422], `report request failed: ${body.slice(0, 200)}`)
            .toContain(res.status());

        const json = JSON.parse(body);
        expect(json.error, `API returned errors: ${JSON.stringify(json.error)}`).toEqual([]);

        return json.data;
    }

    const visitsOf = (row) => Number(
        row?.visits?.value ?? row?.visits ?? row?.metrics?.visits?.value ?? row?.metrics?.visits
    );
    const dimOf = (row, name) => String(
        row?.[name]?.value ?? row?.[name] ?? row?.dimensions?.[name]?.value ?? row?.dimensions?.[name] ?? ''
    );

    test('the unconstrained aggregate matches the seeded total', async ({ request }) => {
        const data = await report(request, { owa_metrics: 'visits' });

        expect(Number(data.aggregates.visits.value)).toBe(fx.expected.total_visits);
    });

    /**
     * The case the Varnish bug broke: a constraint must actually constrain, and
     * each value must give its OWN number. Asserting one value is not enough --
     * a query that ignores its constraint returns the total, which for a single
     * check could coincidentally be right.
     */
    test('a constraint filters, and each value gives its own count', async ({ request }) => {
        for (const [source, expected] of Object.entries(fx.expected.by_source)) {
            const data = await report(request, {
                owa_metrics: 'visits',
                owa_constraints: `source==${source}`,
            });

            expect(Number(data.aggregates.visits.value),
                `constraint source==${source} returned the wrong count`).toBe(expected);

            expect(Number(data.aggregates.visits.value),
                `constraint source==${source} returned the UNCONSTRAINED total -- the constraint was ignored`
            ).not.toBe(fx.expected.total_visits);
        }
    });

    test('a constraint matching nothing returns zero, not everything', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'visits',
            owa_constraints: `source==${fx.expected.absent_source}`,
        });

        expect(Number(data.aggregates.visits.value)).toBe(0);
    });

    /**
     * An EMPTY constraint value must not silently mean "no filter".
     *
     * `source==` is a non-empty STRING carrying an empty VALUE, so it survives
     * every emptiness check until Db::where() drops it -- which is precisely how
     * a stripped request parameter turned into a wrong number instead of an
     * error. Whatever the engine decides to do here, returning the full
     * unfiltered total silently is the one outcome that must not stand.
     */
    test('an empty constraint value does not silently return everything', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'visits',
            owa_constraints: 'source==',
        });

        const errors = JSON.stringify(data.errors || []);

        /*
         * The engine now takes the first of the two outcomes this test always
         * allowed: it REFUSES the query rather than running it unfiltered. So
         * there is no aggregate to read -- reading one unguarded is what this
         * assertion used to do, and it threw once the refusal landed.
         */
        const value = data.aggregates && data.aggregates.visits
            ? Number(data.aggregates.visits.value)
            : null;

        expect(/constraint/i.test(errors),
            `an empty constraint value produced no error saying so. errors=${errors}`
        ).toBe(true);

        const silent = value === fx.expected.total_visits && !/constraint/i.test(errors);

        expect(silent,
            `an empty constraint value returned the unconstrained total (${value}) with no `
            + `error to say so. A lost request parameter must not be indistinguishable from `
            + `"no filter requested". errors=${errors}`
        ).toBe(false);

        expect(value,
            `a refused query must not answer with numbers -- got ${value}`
        ).not.toBe(fx.expected.total_visits);
    });

    /**
     * A breakdown must not depend on being sorted.
     *
     * This found a real defect: computeDimensionalRows() sat INSIDE the
     * `if (orderby)` block, so an unsorted breakdown never ran its query at all.
     * $dresults stayed undefined, generate() got nothing, and the caller saw
     * zero rows beside a perfectly correct aggregate -- silent, because an
     * unassigned variable is only a notice. It survived because all ~60
     * declarative report controllers set a sort, so no shipped report took the
     * unsorted path.
     *
     * Asserted on the counts as well as the row count, so a fix that returns
     * rows but loses their values cannot pass.
     */
    test('a breakdown without a sort still returns its rows', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'visits',
            owa_dimensions: 'source',
        });

        const got = {};
        for (const row of data.resultsRows) {
            got[dimOf(row, 'source')] = visitsOf(row);
        }

        expect(got,
            'a breakdown with no sort returned the wrong rows, though the aggregate was right'
        ).toEqual(fx.expected.by_source);
    });

    test('a dimensional breakdown gives the right count per value', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'visits',
            owa_dimensions: 'source',
            owa_sort: 'visits-',
        });

        const got = {};
        for (const row of data.resultsRows) {
            got[dimOf(row, 'source')] = visitsOf(row);
        }

        expect(got).toEqual(fx.expected.by_source);
    });

    test('the dimensional rows reconcile with the aggregate', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'visits',
            owa_dimensions: 'source',
            owa_sort: 'visits-',
        });

        const sum = data.resultsRows.reduce((t, r) => t + visitsOf(r), 0);

        expect(sum, 'the breakdown does not sum to the aggregate').toBe(
            Number(data.aggregates.visits.value)
        );
    });

    test('sorting orders the rows by the metric', async ({ request }) => {
        const desc = await report(request, {
            owa_metrics: 'visits',
            owa_dimensions: 'source',
            owa_sort: 'visits-',
        });

        expect(desc.resultsRows.map((r) => dimOf(r, 'source'))).toEqual(fx.expected.sources_desc);

        const asc = await report(request, {
            owa_metrics: 'visits',
            owa_dimensions: 'source',
            owa_sort: 'visits',
        });

        expect(asc.resultsRows.map((r) => dimOf(r, 'source')))
            .toEqual([...fx.expected.sources_desc].reverse());
    });

    /** A second dimension must split the rows, not repeat the first one's totals. */
    test('a secondary dimension splits the breakdown', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'visits',
            owa_dimensions: 'source,medium',
            owa_sort: 'visits-',
        });

        const got = data.resultsRows
            .map((r) => ({ source: dimOf(r, 'source'), medium: dimOf(r, 'medium'), visits: visitsOf(r) }))
            .sort((a, b) => (a.source + a.medium).localeCompare(b.source + b.medium));

        const want = [...fx.expected.by_source_medium]
            .sort((a, b) => (a.source + a.medium).localeCompare(b.source + b.medium));

        expect(got).toEqual(want);
    });

    test('a constraint and a breakdown compose', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'visits',
            owa_dimensions: 'medium',
            owa_constraints: 'source==google.com',
            owa_sort: 'visits-',
        });

        const got = {};
        for (const row of data.resultsRows) {
            got[dimOf(row, 'medium')] = visitsOf(row);
        }

        // google's mediums only -- not every medium in the fixture.
        expect(got).toEqual({ organic: 5, cpc: 3 });
    });
});
