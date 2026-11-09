/**
 * How a compared period is shown: the line under a metric box's number, and
 * the compared period's line on a trend.
 *
 * The data comes from the reports REST answer (CompareMerger): metric cells
 * with compare_value, compare_formatted_value and change, and, on a trend,
 * compare.rows already moved onto the period's dates.
 */

jest.mock( 'jquery', () => {
    const jq = jest.requireActual( 'jquery' );
    jq.__esModule = true;
    return jq;
} );

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const $ = require( 'jquery' );

global.jQuery = global.$ = $;

require( '../../modules/Base/src/reporting/v1/owa.kpibox.js' );
require( '../../modules/Base/src/reporting/v1/owa.areachart.js' );

const COMPARE = { compare: 'previous_period_dow', startDate: '20260915', endDate: '20261012' };

const box = ( cell ) => {
    document.body.innerHTML = OWA.kpiBox.compareHtml( cell, COMPARE );
    return document.querySelector( '.owa_metricInfoboxCompare' );
};

describe( 'a metric box compared to another period', () => {

    test( 'shows the change and the dates; the old value is in the title', () => {
        const p = box( { name: 'visits', value: 150, compare_value: 120, compare_formatted_value: '120', change: 0.25 } );

        expect( p.querySelector( '.is-up' ).textContent ).toBe( '▲ 25.0%' );
        expect( p.textContent ).toMatch( /vs Sep 15.*Oct 12, 2026/ );
        expect( p.title ).toMatch( /Sep 15.*Oct 12, 2026: 120$/ );
    } );

    test( 'a fall is marked down', () => {
        expect( box( { value: 90, compare_value: 120, compare_formatted_value: '120', change: -0.25 } )
            .querySelector( '.is-down' ).textContent ).toBe( '▼ 25.0%' );
    } );

    test( 'nothing to compare against shows no percentage', () => {
        const none = box( { value: 5, compare_value: null, compare_formatted_value: null, change: null } );
        expect( none.querySelector( '.is-flat' ).textContent ).toBe( '—' );
        expect( none.title ).toMatch( /no data$/ );

        expect( box( { value: 5, compare_value: 0, compare_formatted_value: '0', change: null } )
            .querySelector( '.is-flat' ).textContent ).toBe( '—' );
    } );

    test( 'a value is escaped', () => {
        expect( box( { value: 1, compare_value: 1, compare_formatted_value: '<b>1</b>', change: 0 } ).title ).toMatch( /<b>1<\/b>$/ );
        expect( document.querySelector( 'b' ) ).toBeNull();
    } );
} );

describe( 'a trend compared to another period', () => {

    const day = ( ymd ) => Date.UTC( +ymd.substr( 0, 4 ), +ymd.substr( 4, 2 ) - 1, +ymd.substr( 6, 2 ) );

    function resultSet( rows, compareRows ) {
        return {
            resultsRows: rows.map( ( [ d, v ] ) => ( { date: { value: d, data_type: 'yyyymmdd' }, pageViews: { value: v, data_type: 'integer' } } ) ),
            compare: compareRows && Object.assign( { rows: compareRows.map( ( [ d, real, v ] ) => ( {
                date: { value: d, compare_period_value: real, data_type: 'yyyymmdd' },
                pageViews: { value: v, data_type: 'integer' },
            } ) ) }, COMPARE ),
            relatedMetrics: { Usage: [ { name: 'pageViews', metric_type: 'count' } ] },
            getMetricLabel: () => 'Page Views',
        };
    }

    test( 'the compared period is a second line, on the period\'s days', () => {
        const rs = resultSet( [ [ '20261102', 10 ], [ '20261104', 30 ] ],
            [ [ '20261102', '20261026', 1 ], [ '20261103', '20261027', 7 ], [ '20261104', '20261028', 3 ] ] );

        const chart = new OWA.areaChart();
        const built = chart.singleSeries( rs, 'date', 'pageViews' );
        const line  = built.series.find( ( s ) => s.isCompare );

        expect( built.series ).toHaveLength( 2 );
        expect( line.lines.fill ).toBe( false );
        expect( line.data ).toEqual( [ [ day( '20261102' ), 1 ], [ day( '20261103' ), 7 ], [ day( '20261104' ), 3 ] ] );
        // The tooltip names the day each point really is.
        expect( line.compareDates[ day( '20261103' ) ] ).toBe( '20261027' );
    } );

    test( 'no comparison, no second line', () => {
        expect( new OWA.areaChart().singleSeries( resultSet( [ [ '20261102', 10 ] ] ), 'date', 'pageViews' ).series ).toHaveLength( 1 );
    } );
} );
