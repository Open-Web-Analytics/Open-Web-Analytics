// The chart imports jQuery as a namespace and calls it. Webpack makes that
// callable; babel-jest's interop does only when jQuery is marked an ES module.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/v1/owa.areachart.js');

/**
 * A report never prints "null".
 *
 * A count over nothing is 0, and the server says so. A ratio over nothing has
 * no value (PLAN 2.11) and reads as a dash. On a trend, a day the cube has no
 * row for is a zero for a count and a gap for a ratio.
 */

describe('a metric\'s value as text', () => {

    test('is its formatted value', () => {
        expect(OWA.util.metricText({ value: 0, formatted_value: '0' })).toBe('0');
        expect(OWA.util.metricText({ value: 0.5, formatted_value: '50.00%' })).toBe('50.00%');
    });

    test('is the raw value when nothing formatted it', () => {
        expect(OWA.util.metricText({ value: 12, formatted_value: '' })).toBe('12');
    });

    test('is a dash when there is no value', () => {
        expect(OWA.util.metricText({ value: null, formatted_value: null })).toBe('—');
        expect(OWA.util.metricText({ value: null })).toBe('—');
        expect(OWA.util.metricText(undefined)).toBe('—');
    });
});

const DAY = 86400000;

function resultSet(rows, metricType) {
    return {
        timePeriod: { startDate: '20261020', endDate: '20261024' },
        resultsRows: rows.map(([date, value]) => ({
            date: { value: date, data_type: 'yyyymmdd' },
            metric: { value, data_type: 'integer' },
        })),
        relatedMetrics: { Usage: [{ name: 'metric', metric_type: metricType }] },
        getMetricLabel: () => 'Metric',
    };
}

describe('a trend over days with no rows', () => {

    const chart = () => new OWA.areaChart();

    test('a count is zero on the days it has no row', () => {
        const built = chart().singleSeries(resultSet([['20261021', 3], ['20261023', 5]], 'count'), 'date', 'metric');

        expect(built.series[0].data).toEqual([
            [Date.UTC(2026, 9, 20), 0],
            [Date.UTC(2026, 9, 21), 3],
            [Date.UTC(2026, 9, 22), 0],
            [Date.UTC(2026, 9, 23), 5],
            [Date.UTC(2026, 9, 24), 0],
        ]);
    });

    test('a ratio is a gap, not a zero', () => {
        const built = chart().singleSeries(resultSet([['20261021', 0.5]], 'ratio'), 'date', 'metric');

        expect(built.series[0].data.map((p) => p[1])).toEqual([null, 0.5, null, null, null]);
    });

    test('a breakdown has every day of the period', () => {
        const rs = resultSet([['20261022', 2]], 'distinct_count');
        rs.resultsRows[0].medium = { value: 'organic', formatted_value: 'organic' };

        const built = chart().seriesByDimension(rs, 'date', 'metric', 'medium');

        expect(built.points).toBe(5);
        expect(built.series[0].data.map((p) => p[1])).toEqual([0, 0, 2, 0, 0]);
        expect(built.series[1].data[0]).toEqual([Date.UTC(2026, 9, 20), 0]);
        expect(built.series[1].data[1][0] - built.series[1].data[0][0]).toBe(DAY);
    });

    test('one day is a dot, because a line needs two points', () => {
        const rs = resultSet([['20261024', 4]], 'count');
        rs.timePeriod = { startDate: '20261024', endDate: '20261024' };

        const series = chart().singleSeries(rs, 'date', 'metric').series[0];

        expect(series.data).toEqual([[Date.UTC(2026, 9, 24), 4]]);
        expect(series.points.show).toBe(true);
    });
});
