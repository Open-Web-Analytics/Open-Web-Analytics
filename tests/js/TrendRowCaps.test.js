/**
 * A trend draws every row its query returns: zero-filled as far as any period
 * a report offers, every row past that still drawn, and the grid's rows asked
 * for page by page when they are more than one page.
 */

jest.mock( 'jquery', () => {
    const jq = jest.requireActual( 'jquery' );
    jq.__esModule = true;
    return jq;
} );

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const $ = require( 'jquery' );

global.jQuery = global.$ = $;

require( '../../modules/Base/src/reporting/v1/owa.areachart.js' );
require( '../../modules/Base/src/reporting/v1/owa.resultSetExplorer.js' );

const DAY = 86400000;

function byDay( start, end, rows ) {
    return {
        timePeriod: { startDate: start, endDate: end },
        resultsRows: rows.map( ( [ d, v ] ) => ( { date: { value: d, data_type: 'yyyymmdd' }, visits: { value: v, data_type: 'integer' } } ) ),
        relatedMetrics: { Usage: [ { name: 'visits', metric_type: 'count' } ] },
        getMetricLabel: () => 'Visits',
    };
}

afterEach( () => jest.restoreAllMocks() );

describe( 'a trend over a long period', () => {

    test( 'two years of days are every day, the last one included', () => {
        const data = new OWA.areaChart().singleSeries( byDay( '20240101', '20251231', [ [ '20251231', 4 ] ] ), 'date', 'visits' ).series[0].data;

        expect( data ).toHaveLength( 731 );
        expect( data[730] ).toEqual( [ Date.UTC( 2025, 11, 31 ), 4 ] );
    } );

    test( 'a row past the zero-filled points is still drawn', () => {
        const max = OWA.areaChart.MAX_POINTS.day;
        OWA.areaChart.MAX_POINTS.day = 2;

        try {
            const data = new OWA.areaChart().singleSeries(
                byDay( '20261101', '20261105', [ [ '20261101', 1 ], [ '20261105', 5 ] ] ), 'date', 'visits' ).series[0].data;

            expect( data ).toEqual( [ [ Date.UTC( 2026, 10, 1 ), 1 ], [ Date.UTC( 2026, 10, 2 ), 0 ], [ Date.UTC( 2026, 10, 5 ), 5 ] ] );
        } finally {
            OWA.areaChart.MAX_POINTS.day = max;
        }
    } );
} );

describe( 'the grid\'s rows, more than a page of them', () => {

    test( 'are asked for page by page, in the order of their key, until a page is short', () => {
        OWA.setSetting( 'max_results_per_page', 2 );

        const row = ( d, b, v ) => ( {
            date: { value: d, data_type: 'yyyymmdd' }, browserType: { value: b }, visits: { value: v, data_type: 'integer' },
        } );
        const pages = {
            1: { resultsRows: [ row( '20261102', 'Chrome', 1 ), row( '20261102', 'Safari', 2 ) ],
                compare: { rows: [ row( '20261026', 'Chrome', 7 ) ] } },
            2: { resultsRows: [ row( '20261103', 'Chrome', 3 ) ],
                compare: { rows: [ row( '20261026', 'Chrome', 7 ), row( '20261027', 'Chrome', 8 ) ] } },
        };
        const asked = [];

        jest.spyOn( $, 'getJSON' ).mockImplementation( ( u, d, done ) => {
            asked.push( u );
            done( { data: pages[ new URL( u ).searchParams.get( 'page' ) || 1 ] } );

            return $.Deferred().resolve().promise();
        } );

        const chart = new OWA.areaChart();
        chart.explorer = { resultSet: { self: 'https://owa.test/api/index.php?metrics=visits&dimensions=date&sort=visits-' } };
        chart.xDimension = 'date';
        chart.redraw = jest.fn();

        try {
            chart.plotRows( [ 'browserType' ], [ { values: { browserType: 'Chrome' }, label: 'Chrome' },
                { values: { browserType: 'Safari' }, label: 'Safari' } ] );
        } finally {
            OWA.setSetting( 'max_results_per_page', 1000 );
        }

        expect( asked ).toHaveLength( 2 );
        expect( new URL( asked[0] ).searchParams.get( 'sort' ) ).toBe( 'date,browserType' );
        expect( new URL( asked[0] ).searchParams.get( 'resultsPerPage' ) ).toBe( '2' );
        expect( new URL( asked[1] ).searchParams.get( 'page' ) ).toBe( '2' );

        expect( chart.rowsResultSet.resultsRows.map( ( r ) => r.visits.value ) ).toEqual( [ 1, 2, 3 ] );
        expect( chart.rowsResultSet.compare.rows.map( ( r ) => r.visits.value ) ).toEqual( [ 7, 8 ] );
        expect( chart.redraw ).toHaveBeenCalledTimes( 1 );
    } );
} );
