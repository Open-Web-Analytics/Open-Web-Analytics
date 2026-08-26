/**
 * @jest-environment jsdom
 */
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../../modules/Base/src/reporting/v1/owa.js';
require('../../../modules/Base/src/reporting/v1/owa.report.js');

/**
 * A period is EITHER relative OR a fixed range, never both.
 *
 * The two mean different things on the next page load. A relative period is a
 * question the server answers again every time -- "last seven days" is a
 * different week tomorrow. A fixed range is an answer already given, and must
 * not drift.
 *
 * So the picker keeps them mutually exclusive: choosing a relative period wipes
 * any dates, and choosing dates wipes the relative period. Whichever the user
 * touched last is the only one that travels.
 *
 * This lives in a test because it is invisible. Both values are legitimate
 * request parameters, both are carried by server-rendered links, and the server
 * resolves either without complaint -- so leaving a stale one behind produces no
 * error anywhere. It produces a report showing a different fortnight than the
 * picker claims, which is the kind of thing a user reports as "the dates are
 * wrong sometimes".
 */
describe('the period picker keeps relative and fixed selections exclusive', () => {

    function report(initial) {
        const r = new OWA.report('test-report');
        Object.assign(r.properties, initial);
        return r;
    }

    test('choosing a relative period wipes the dates', () => {
        const r = report({
            period: 'date_range',
            startDate: '20260801',
            endDate: '20260815',
            siteId: 's1',
        });

        r.setPeriod('last_seven_days');

        expect(r.properties.period).toBe('last_seven_days');
        expect(r.properties).not.toHaveProperty('startDate');
        expect(r.properties).not.toHaveProperty('endDate');

        // ...and it leaves everything that is not a period alone.
        expect(r.properties.siteId).toBe('s1');
    });

    test('choosing a date range wipes the relative period', () => {
        const r = report({ period: 'last_seven_days', siteId: 's1' });

        r.setDateRange('20260801', '20260815');

        expect(r.properties.startDate).toBe('20260801');
        expect(r.properties.endDate).toBe('20260815');
        expect(r.properties).not.toHaveProperty('period');
        expect(r.properties.siteId).toBe('s1');
    });

    test('switching back and forth never leaves both behind', () => {
        const r = report({ period: 'today' });

        r.setDateRange('20260801', '20260815');
        r.setPeriod('this_month');
        r.setDateRange('20260101', '20260131');

        const hasRelative = Object.prototype.hasOwnProperty.call(r.properties, 'period');
        const hasDates    = Object.prototype.hasOwnProperty.call(r.properties, 'startDate');

        expect(hasRelative && hasDates).toBe(false);
        expect(hasDates).toBe(true);
    });

    /**
     * The wipe only matters because the next URL is built from properties
     * ALONE. If reload() merged with the current query string instead, a
     * deleted key would come straight back from the address bar and the
     * exclusivity above would be decorative.
     */
    test('the next url is built from properties alone, not merged with the current one', () => {
        const src = require('fs').readFileSync(
            require('path').join(__dirname, '../../../modules/Base/src/reporting/v1/owa.report.js'),
            'utf8');

        const reload = src.slice(src.indexOf('reload: function'), src.indexOf('_parseDate'));

        expect(reload).toContain('this.properties');
        expect(reload).not.toMatch(/location\.search|window\.location\.href\s*\+|parseUrlParams/);
    });
});
