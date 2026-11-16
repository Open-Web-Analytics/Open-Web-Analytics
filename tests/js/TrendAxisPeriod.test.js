// The chart imports jQuery as a namespace and calls it; see HourlyTrend.test.js.
jest.mock( 'jquery', () => {
    const jq = jest.requireActual( 'jquery' );
    jq.__esModule = true;
    return jq;
} );

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

const $ = require( 'jquery' );

global.jQuery = global.$ = $;

require( '../../modules/Base/src/reporting/owa.areachart.js' );

/**
 * A trend's x axis runs the whole period, not the rows.
 *
 * Today by hour runs to 11pm, and This week, This month, This quarter and
 * This year run to the last day of the week, month, quarter or year. The line
 * stops at today, or at the hour it is now.
 */

const HOUR = 3600000;
const day  = ( y, m, d, h = 0 ) => Date.UTC( y, m - 1, d, h );

function resultSet( x, type, rows, period ) {
    return {
        timePeriod: period,
        resultsRows: rows.map( ( [ value, metric ] ) => ( {
            [ x ]: { value, data_type: type },
            visits: { value: metric, data_type: 'integer' },
        } ) ),
        relatedMetrics: { Usage: [ { name: 'visits', metric_type: 'count' } ] },
        getMetricLabel: () => 'Visits',
    };
}

describe( 'a trend\'s x axis', () => {

    let realPlot;
    let drawn;

    beforeEach( () => {
        realPlot = $.plot;
        drawn = [];
        $.plot = jest.fn( ( $placeholder, series, options ) => {
            drawn.push( { series, xaxis: Object.assign( {}, options.xaxis ) } );
        } );

        document.body.innerHTML = '<div id="c"></div>';
    } );

    afterEach( () => {
        $.plot = realPlot;
    } );

    function generate( rs, x ) {
        const c = new OWA.areaChart();
        c.generate( rs, [ { x, y: 'visits' } ], 'c' );
        return drawn[ drawn.length - 1 ];
    }

    test( 'today by hour runs to 11pm, and the line stops at the hour it is now', () => {
        const today = { period: 'today', startDate: '20261114', endDate: '20261114',
            axisEndDate: '20261114', currentHour: 2026111419 };

        const plot = generate( resultSet( 'dateHour', 'yyyymmddhh', [ [ '2026111409', 3 ] ], today ), 'dateHour' );

        expect( plot.xaxis.min ).toBe( day( 2026, 11, 14 ) );
        expect( plot.xaxis.max ).toBe( day( 2026, 11, 14, 23 ) );

        const line = plot.series[0].data;
        expect( line ).toHaveLength( 20 );
        expect( line[ line.length - 1 ][0] ).toBe( day( 2026, 11, 14, 19 ) );
    } );

    test( 'today\'s hour ticks are placed across the whole day', () => {
        const today = { period: 'today', startDate: '20261114', endDate: '20261114',
            axisEndDate: '20261114', currentHour: 2026111405 };

        const plot = generate( resultSet( 'dateHour', 'yyyymmddhh', [ [ '2026111404', 1 ] ], today ), 'dateHour' );

        expect( plot.xaxis.ticks[ plot.xaxis.ticks.length - 1 ] ).toBeGreaterThan( day( 2026, 11, 14, 5 ) );
    } );

    test( 'this month by day runs to the month\'s last day, and the line stops at today', () => {
        const month = { period: 'this_month', startDate: '20261101', endDate: '20261114', axisEndDate: '20261130' };

        const plot = generate( resultSet( 'date', 'yyyymmdd', [ [ '20261103', 4 ] ], month ), 'date' );

        expect( plot.xaxis.min ).toBe( day( 2026, 11, 1 ) );
        expect( plot.xaxis.max ).toBe( day( 2026, 11, 30 ) );
        expect( plot.series[0].data ).toHaveLength( 14 );
    } );

    test( 'this week on its first day still has the whole week\'s ticks', () => {
        const week = { period: 'this_week', startDate: '20261115', endDate: '20261115', axisEndDate: '20261121' };

        const plot = generate( resultSet( 'date', 'yyyymmdd', [ [ '20261115', 2 ] ], week ), 'date' );

        expect( plot.xaxis.max ).toBe( day( 2026, 11, 21 ) );
        expect( plot.series[0].data ).toHaveLength( 1 );
        expect( plot.xaxis.owaMostTicks ).toBe( 7 );
    } );

    test( 'this year by month runs to December', () => {
        const year = { period: 'this_year', startDate: '20260101', endDate: '20261114', axisEndDate: '20261231' };

        const plot = generate( resultSet( 'yearMonth', 'yyyymm', [ [ '202603', 9 ] ], year ), 'yearMonth' );

        expect( plot.xaxis.min ).toBe( day( 2026, 1, 1 ) );
        expect( plot.xaxis.max ).toBe( day( 2026, 12, 1 ) );
        expect( plot.series[0].data ).toHaveLength( 11 );
    } );

    test( 'an empty this week on Sunday still draws last week, across the whole axis', () => {
        const week = { period: 'this_week', startDate: '20261115', endDate: '20261115', axisEndDate: '20261121' };
        const rs   = resultSet( 'date', 'yyyymmdd', [], week );

        rs.compare = { compare: 'previous_period', startDate: '20261108', endDate: '20261114', aggregates: {},
            rows: [ [ '20261116', '20261109', 3 ], [ '20261118', '20261111', 5 ] ].map( ( [ d, real, v ] ) => ( {
                date: { value: d, compare_period_value: real, data_type: 'yyyymmdd' },
                visits: { value: v, data_type: 'integer' },
            } ) ) };

        const plot = generate( rs, 'date' );
        const own  = plot.series.find( ( s ) => ! s.isCompare );
        const cmp  = plot.series.find( ( s ) => s.isCompare );

        expect( own.data ).toEqual( [ [ day( 2026, 11, 15 ), 0 ] ] );
        expect( cmp.data.map( ( p ) => p[1] ) ).toEqual( [ 0, 3, 0, 5, 0, 0, 0 ] );
        expect( plot.xaxis.max ).toBe( day( 2026, 11, 21 ) );
    } );

    test( 'a comparison with nothing in either period is still a line, at zero to Saturday', () => {
        const week = { period: 'this_week', startDate: '20261115', endDate: '20261115', axisEndDate: '20261121' };
        const rs   = resultSet( 'date', 'yyyymmdd', [], week );

        rs.compare = { compare: 'previous_period', startDate: '20261108', endDate: '20261114', aggregates: {}, rows: [] };

        const cmp = generate( rs, 'date' ).series.find( ( s ) => s.isCompare );

        expect( cmp.data ).toHaveLength( 7 );
        expect( cmp.data.every( ( p ) => p[1] === 0 ) ).toBe( true );
    } );

    test( 'an empty this month is zero to today on an axis to the month\'s end', () => {
        const month = { period: 'this_month', startDate: '20261101', endDate: '20261114', axisEndDate: '20261130' };

        const plot = generate( resultSet( 'date', 'yyyymmdd', [], month ), 'date' );

        expect( plot.xaxis.max ).toBe( day( 2026, 11, 30 ) );
        expect( plot.series[0].data ).toHaveLength( 14 );
    } );

    test( 'a period without an axis end runs to its end date', () => {
        const range = { period: 'date_range', startDate: '20261103', endDate: '20261109' };

        const plot = generate( resultSet( 'date', 'yyyymmdd', [ [ '20261105', 1 ] ], range ), 'date' );

        expect( plot.xaxis.min ).toBe( day( 2026, 11, 3 ) );
        expect( plot.xaxis.max ).toBe( day( 2026, 11, 9 ) );
    } );

    test( 'no period: the rows size the axis, as before', () => {
        const plot = generate( resultSet( 'date', 'yyyymmdd', [ [ '20261105', 1 ], [ '20261107', 2 ] ], null ), 'date' );

        expect( plot.xaxis.min ).toBeUndefined();
        expect( plot.xaxis.max ).toBeUndefined();
    } );

    test( 'the axis is in whole intervals', () => {
        const c = new OWA.areaChart();
        const p = { startDate: '20261101', endDate: '20261114', axisEndDate: '20261130' };

        expect( c.periodAxis( { timePeriod: p }, 'yyyymmdd' ).intervals ).toBe( 30 );
        expect( c.periodAxis( { timePeriod: p }, 'yyyymmddhh' ).intervals ).toBe( 30 * 24 );
        expect( c.periodAxis( { timePeriod: p }, 'yyyymm' ).intervals ).toBe( 1 );
        expect( c.periodAxis( { timePeriod: p }, 'yyyymmddhh' ).max - day( 2026, 11, 30 ) ).toBe( 23 * HOUR );
    } );
} );
