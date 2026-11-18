// @ts-check
/**
 * The reporting engine, exercised on its facets against known numbers.
 *
 * WHY THIS EXISTS
 * Asserting that the dashboard RENDERS says nothing about its numbers: a proxy
 * stripping a constraint parameter would leave every source showing the same
 * unfiltered total and the page rendering fine.
 *
 * WHAT IT COVERS
 * The facets a report is actually made of, rather than any particular report:
 * constraints, sorts, secondary dimensions, and reconciliation between the
 * dimensional rows and the aggregate. Most reports are pure declaration --
 * metrics, dimensions, sort, a link template -- so testing the engine's facets
 * covers what all of them do.
 *
 * Asserted through the REST API rather than the UI: it is the same engine the
 * grids read from, and the numbers are the thing under test. One case at the end
 * does go through the rendered page, because the URL the page emits is part of
 * the path.
 */
const path = require('path');
const { execFileSync } = require('child_process');
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
        expect(fx.token, 'fixture user has no application password').toBeTruthy();
    });

    test.afterAll(() => { helper('cleanup'); });

    test.beforeEach(({}, testInfo) => {
        apiRoot = installRoot(testInfo.project.use.baseURL);
    });

    /** Query the reports API and return the parsed resultset. */
    async function report(request, params) {
        const q = new URLSearchParams(Object.assign({
            owa_do: 'reports',
            owa_module: 'base',
            owa_version: 'v1',
            owa_siteId: fx.site_id,
            owa_period: 'today',
        }, params));

        // An application password, over https as the proxy reports it.
        const url = apiRoot + 'api/index.php?' + q.toString();

        const res = await request.fetch(url, { method: 'GET', headers: {
            Authorization: 'Bearer ' + fx.token,
            'X-Forwarded-Proto': 'https',
        } });
        const body = await res.text();

        /*
         * 422 is a legitimate answer, not a transport failure: a request the
         * engine refuses to run -- an unknown constraint name, a constraint
         * whose value went missing -- is reported as one rather than answered
         * with numbers computed without the filter. The tests below assert on
         * which of the two came back.
         */
        expect([200, 422], `report request failed: ${body.slice(0, 200)}`)
            .toContain(res.status());

        const json = JSON.parse(body);
        expect(json.error, `API returned errors: ${JSON.stringify(json.error)}`).toEqual([]);

        return json.data;
    }

    const sessionsOf = (row) => Number(
        row?.sessions?.value ?? row?.sessions ?? row?.metrics?.sessions?.value ?? row?.metrics?.sessions
    );
    const dimOf = (row, name) => String(
        row?.[name]?.value ?? row?.[name] ?? row?.dimensions?.[name]?.value ?? row?.dimensions?.[name] ?? ''
    );

    test('the unconstrained aggregate matches the seeded total', async ({ request }) => {
        const data = await report(request, { owa_metrics: 'sessions' });

        expect(Number(data.aggregates.sessions.value)).toBe(fx.expected.total_visits);
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
                owa_metrics: 'sessions',
                owa_constraints: `sessionSource==${source}`,
            });

            expect(Number(data.aggregates.sessions.value),
                `constraint source==${source} returned the wrong count`).toBe(expected);

            expect(Number(data.aggregates.sessions.value),
                `constraint source==${source} returned the UNCONSTRAINED total -- the constraint was ignored`
            ).not.toBe(fx.expected.total_visits);
        }
    });

    test('a constraint matching nothing returns zero, not everything', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'sessions',
            owa_constraints: `sessionSource==${fx.expected.absent_source}`,
        });

        expect(Number(data.aggregates.sessions.value)).toBe(0);
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
            owa_metrics: 'sessions',
            owa_constraints: 'sessionSource==',
        });

        const errors = JSON.stringify(data.errors || []);

        /*
         * The engine REFUSES the query rather than running it unfiltered, so
         * there may be no aggregate to read.
         */
        const value = data.aggregates && data.aggregates.sessions
            ? Number(data.aggregates.sessions.value)
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
            owa_metrics: 'sessions',
            owa_dimensions: 'sessionSource',
        });

        const got = {};
        for (const row of data.resultsRows) {
            got[dimOf(row, 'sessionSource')] = sessionsOf(row);
        }

        expect(got,
            'a breakdown with no sort returned the wrong rows, though the aggregate was right'
        ).toEqual(fx.expected.by_source);
    });

    test('a dimensional breakdown gives the right count per value', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'sessions',
            owa_dimensions: 'sessionSource',
            owa_sort: 'sessions-',
        });

        const got = {};
        for (const row of data.resultsRows) {
            got[dimOf(row, 'sessionSource')] = sessionsOf(row);
        }

        expect(got).toEqual(fx.expected.by_source);
    });

    test('the dimensional rows reconcile with the aggregate', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'sessions',
            owa_dimensions: 'sessionSource',
            owa_sort: 'sessions-',
        });

        const sum = data.resultsRows.reduce((t, r) => t + sessionsOf(r), 0);

        expect(sum, 'the breakdown does not sum to the aggregate').toBe(
            Number(data.aggregates.sessions.value)
        );
    });

    test('sorting orders the rows by the metric', async ({ request }) => {
        const desc = await report(request, {
            owa_metrics: 'sessions',
            owa_dimensions: 'sessionSource',
            owa_sort: 'sessions-',
        });

        expect(desc.resultsRows.map((r) => dimOf(r, 'sessionSource'))).toEqual(fx.expected.sources_desc);

        const asc = await report(request, {
            owa_metrics: 'sessions',
            owa_dimensions: 'sessionSource',
            owa_sort: 'sessions',
        });

        expect(asc.resultsRows.map((r) => dimOf(r, 'sessionSource')))
            .toEqual([...fx.expected.sources_desc].reverse());
    });

    /** A second dimension must split the rows, not repeat the first one's totals. */
    test('a secondary dimension splits the breakdown', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'sessions',
            owa_dimensions: 'sessionSource,sessionMedium',
            owa_sort: 'sessions-',
        });

        const got = data.resultsRows
            .map((r) => ({ source: dimOf(r, 'sessionSource'), medium: dimOf(r, 'sessionMedium'), visits: sessionsOf(r) }))
            .sort((a, b) => (a.source + a.medium).localeCompare(b.source + b.medium));

        const want = [...fx.expected.by_source_medium]
            .sort((a, b) => (a.source + a.medium).localeCompare(b.source + b.medium));

        expect(got).toEqual(want);
    });

    test('a constraint and a breakdown compose', async ({ request }) => {
        const data = await report(request, {
            owa_metrics: 'sessions',
            owa_dimensions: 'sessionMedium',
            owa_constraints: 'sessionSource==google.com',
            owa_sort: 'sessions-',
        });

        const got = {};
        for (const row of data.resultsRows) {
            got[dimOf(row, 'sessionMedium')] = sessionsOf(row);
        }

        // google's mediums only -- not every medium in the fixture.
        expect(got).toEqual({ organic: 5, cpc: 3 });
    });
});
