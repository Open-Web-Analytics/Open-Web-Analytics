/**
 * A broken-out trend's Total is its own query by the x axis alone: users or a
 * bounce rate summed over the values of a dimension count a visitor in two
 * values twice, and a rate goes past 100%.
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

const day = ( ymd ) => Date.UTC( +ymd.substr( 0, 4 ), +ymd.substr( 4, 2 ) - 1, +ymd.substr( 6, 2 ) );

const SELF = 'https://owa.test/api/index.php?metrics=totalUsers&dimensions=date,medium&page=2&sort=date&resultsPerPage=1000';

/** By day and medium: one visitor came by two media on the 2nd. */
function byMedium( metricType ) {
    const row = ( [ d, m, v ] ) => ( {
        date: { value: d, data_type: 'yyyymmdd' },
        medium: { value: m, formatted_value: m },
        totalUsers: { value: v, data_type: 'integer' },
    } );
    return {
        self: SELF,
        timePeriod: { startDate: '20261102', endDate: '20261103' },
        resultsRows: [ [ '20261102', 'organic', 1 ], [ '20261102', 'referral', 1 ], [ '20261103', 'organic', 2 ] ].map( row ),
        relatedMetrics: { Usage: [ { name: 'totalUsers', metric_type: metricType } ] },
        getMetricLabel: () => 'Users',
    };
}

/** The same query by day alone. */
const BY_DAY = { data: { resultsRows: [
    { date: { value: '20261102', data_type: 'yyyymmdd' }, totalUsers: { value: 1, data_type: 'integer' } },
] } };

afterEach( () => jest.restoreAllMocks() );

describe( 'a broken-out trend\'s Total', () => {

    test( 'is asked for by the x axis alone, every row of it', () => {
        const asked = [];
        jest.spyOn( $, 'getJSON' ).mockImplementation( ( u ) => asked.push( u ) );

        const chart = new OWA.areaChart();
        chart.seriesByDimension( byMedium( 'distinct_count' ), 'date', 'totalUsers', 'medium' );
        chart.seriesByDimension( byMedium( 'distinct_count' ), 'date', 'totalUsers', 'medium' );

        expect( asked ).toHaveLength( 1 );
        const url = new URL( asked[0] );
        expect( url.searchParams.get( 'dimensions' ) ).toBe( 'date' );
        expect( url.searchParams.has( 'page' ) ).toBe( false );
        expect( url.searchParams.has( 'sort' ) ).toBe( false );
        expect( url.searchParams.has( 'resultsPerPage' ) ).toBe( false );
    } );

    test( 'of a distinct count is none until its own query answers, then that answer', () => {
        let answer;
        jest.spyOn( $, 'getJSON' ).mockImplementation( ( u, d, done ) => { answer = done; } );

        const chart = new OWA.areaChart();
        chart.redraw = jest.fn();

        const before = chart.seriesByDimension( byMedium( 'distinct_count' ), 'date', 'totalUsers', 'medium' );
        expect( before.series[0].data ).toEqual( [ [ day( '20261102' ), null ], [ day( '20261103' ), null ] ] );

        answer( BY_DAY );
        expect( chart.redraw ).toHaveBeenCalled();

        const after = chart.seriesByDimension( byMedium( 'distinct_count' ), 'date', 'totalUsers', 'medium' );
        expect( after.series[0].label ).toBe( 'Total' );
        expect( after.series[0].data ).toEqual( [ [ day( '20261102' ), 1 ], [ day( '20261103' ), 0 ] ] );
        expect( after.series[1].data ).toEqual( [ [ day( '20261102' ), 1 ], [ day( '20261103' ), 2 ] ] );
    } );

    test( 'of a count is the sum of the rows until its own query answers', () => {
        jest.spyOn( $, 'getJSON' ).mockImplementation( () => {} );

        const built = new OWA.areaChart().seriesByDimension( byMedium( 'count' ), 'date', 'totalUsers', 'medium' );

        expect( built.series[0].data ).toEqual( [ [ day( '20261102' ), 2 ], [ day( '20261103' ), 2 ] ] );
    } );
} );
