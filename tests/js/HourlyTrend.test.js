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
 * A trend of one day is drawn by hour.
 *
 * dateHour arrives as 2026103014, the install's wall clock. The chart plots it
 * as that hour, fills the hours with no row, stops today at the hour it is
 * now, and labels the axis on a 12-hour clock.
 */

const HOUR = 3600000;

function resultSet(rows, period, metricType = 'count') {
    return {
        timePeriod: period,
        resultsRows: rows.map(([hour, value]) => ({
            dateHour: { value: hour, data_type: 'yyyymmddhh' },
            metric: { value, data_type: 'integer' },
        })),
        relatedMetrics: { Usage: [{ name: 'metric', metric_type: metricType }] },
        getMetricLabel: () => 'Metric',
    };
}

const chart = () => new OWA.areaChart();

describe('an hour', () => {

    test('is plotted as that hour of that day', () => {
        expect(chart().formatValue('yyyymmddhh', '2026103014')).toBe(Date.UTC(2026, 9, 30, 14));
        expect(chart().formatValue('yyyymmddhh', 2026103000)).toBe(Date.UTC(2026, 9, 30, 0));
    });

    test('is labelled on a 12-hour clock', () => {
        const c = chart();
        const at = (h) => c.hourLabel(Date.UTC(2026, 9, 30, h), false);

        expect([0, 1, 11, 12, 13, 23].map(at)).toEqual(['12am', '1am', '11am', '12pm', '1pm', '11pm']);
    });

    test('is the date at midnight when the chart spans days', () => {
        const c = chart();

        expect(c.hourLabel(Date.UTC(2026, 9, 30, 0), true)).toBe('10/30');
        expect(c.hourLabel(Date.UTC(2026, 9, 30, 12), true)).toBe('12pm');
    });

    test('a tooltip names the day and the hour', () => {
        const c = chart();
        c.xType = 'yyyymmddhh';

        expect(c.timestampFormatter(Date.UTC(2026, 9, 30, 14))).toBe('10/30/2026 2pm');

        c.xType = 'yyyymmdd';
        expect(c.timestampFormatter(Date.UTC(2026, 9, 30))).toBe('10/30/2026');
    });
});

describe('a day by hour', () => {

    const day = { startDate: '20261030', endDate: '20261030', currentHour: 2026110109 };

    test('has every hour, a count zero where there is no row', () => {
        const built = chart().singleSeries(resultSet([['2026103002', 3], ['2026103014', 5]], day), 'dateHour', 'metric');
        const data = built.series[0].data;

        expect(built.x_type).toBe('yyyymmddhh');
        expect(data).toHaveLength(24);
        expect(data[0]).toEqual([Date.UTC(2026, 9, 30, 0), 0]);
        expect(data[2]).toEqual([Date.UTC(2026, 9, 30, 2), 3]);
        expect(data[14]).toEqual([Date.UTC(2026, 9, 30, 14), 5]);
        expect(data[23]).toEqual([Date.UTC(2026, 9, 30, 23), 0]);
    });

    test('a ratio is a gap where there is no row', () => {
        const built = chart().singleSeries(resultSet([['2026103014', 0.5]], day, 'ratio'), 'dateHour', 'metric');

        expect(built.series[0].data.filter((p) => p[1] !== null)).toEqual([[Date.UTC(2026, 9, 30, 14), 0.5]]);
    });

    test('today stops at the hour it is now', () => {
        const today = { startDate: '20261031', endDate: '20261031', currentHour: 2026103109 };
        const data = chart().singleSeries(resultSet([['2026103108', 2]], today), 'dateHour', 'metric').series[0].data;

        expect(data).toHaveLength(10);
        expect(data[data.length - 1]).toEqual([Date.UTC(2026, 9, 31, 9), 0]);
    });

    test('a breakdown has every hour too', () => {
        const rs = resultSet([['2026103014', 2]], day, 'distinct_count');
        rs.resultsRows[0].medium = { value: 'organic', formatted_value: 'organic' };

        const built = chart().seriesByDimension(rs, 'dateHour', 'metric', 'medium');

        expect(built.points).toBe(24);
        expect(built.series[1].data[14]).toEqual([Date.UTC(2026, 9, 30, 14), 2]);
        expect(built.series[1].data[1][0] - built.series[1].data[0][0]).toBe(HOUR);
    });

    test('an empty day is zero across its hours', () => {
        const zero = chart().zeroFilled(resultSet([], day), 'dateHour');

        expect(zero.x_type).toBe('yyyymmddhh');
        expect(zero.points).toHaveLength(24);
    });
});

describe('the hour ticks', () => {

    const start = Date.UTC(2026, 9, 30, 0);

    test('a wide chart of a day is labelled every two hours', () => {
        const ticks = chart().hourTicks(start, start + 23 * HOUR, 800);

        expect(ticks).toHaveLength(12);
        expect(ticks[1] - ticks[0]).toBe(2 * HOUR);
        expect(ticks[0]).toBe(start);
    });

    test('a card of a day is labelled every six hours', () => {
        const ticks = chart().hourTicks(start, start + 23 * HOUR, 300);

        expect(ticks.map((t) => chart().hourLabel(t, false))).toEqual(['12am', '6am', '12pm', '6pm']);
    });

    test('a week falls on midnights and noons, or midnights alone when narrow', () => {
        const end = start + (7 * 24 - 1) * HOUR;

        const wide = chart().hourTicks(start, end, 900);
        expect(wide[1] - wide[0]).toBe(12 * HOUR);

        const narrow = chart().hourTicks(start, end, 450);
        expect(narrow.every((t) => new Date(t).getUTCHours() === 0)).toBe(true);
    });
});

describe('the granularity control', () => {

    function drawn(period, current) {
        document.body.innerHTML = '<div id="w"><div class="owa_chartControls"></div></div>';

        const c = chart();
        c.explorer = { resultSet: { timePeriod: period } };
        c.xDimension = current;
        c.drawGranularityControl('w');

        return Array.from(document.querySelectorAll('#w option')).map((o) => o.value);
    }

    test('offers hours for up to eight days, which Last Seven Days covers', () => {
        expect(drawn({ startDate: '20261030', endDate: '20261030' }, 'dateHour'))
            .toEqual(['dateHour', 'date', 'yearMonth']);
        expect(drawn({ startDate: '20261023', endDate: '20261030' }, 'date'))
            .toEqual(['dateHour', 'date', 'yearMonth']);
    });

    test('does not offer hours over a longer period', () => {
        expect(drawn({ startDate: '20261022', endDate: '20261030' }, 'date')).toEqual(['date', 'yearMonth']);
        expect(drawn({ startDate: '20261001', endDate: '20261030' }, 'date')).toEqual(['date', 'yearMonth']);
    });
});
