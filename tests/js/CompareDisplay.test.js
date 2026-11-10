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

    test( 'shows the change; the dates and the old value are in the title', () => {
        const p = box( { name: 'visits', value: 150, compare_value: 120, compare_formatted_value: '120', change: 0.25 } );

        expect( p.querySelector( '.is-up' ).textContent ).toBe( '▲ 25.0%' );
        expect( p.textContent ).toBe( '▲ 25.0%' );
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
        // The same metric, so no label of its own: the tooltip names the metric.
        expect( line.label ).toBeUndefined();
        expect( line.tooltipLabel ).toBe( 'Page Views' );
        expect( line.dashed ).toBe( true );
        expect( built.series.find( ( s ) => ! s.isCompare ).dashed ).toBeUndefined();
    } );

    test( 'a dashed series is stroked in dashes, and the next series is not', () => {
        const chart = new OWA.areaChart();
        const ctx   = { dash: null, setLineDash( d ) { this.dash = d; } };

        chart.dashLine( null, ctx, { dashed: true } );
        expect( ctx.dash ).toEqual( [ 5, 4 ] );

        chart.dashLine( null, ctx, {} );
        expect( ctx.dash ).toEqual( [] );
    } );

    test( 'the key under the chart names the two periods, solid and dashed', () => {
        const rs = Object.assign( resultSet( [ [ '20261102', 10 ] ], [ [ '20261102', '20261026', 1 ], [ '20261103', '20261027', 2 ] ] ),
            { timePeriod: { startDate: '20261102', endDate: '20261108' } } );

        const chart = new OWA.areaChart();
        const line  = chart.singleSeries( rs, 'date', 'pageViews' ).series.find( ( s ) => s.isCompare );

        // No names for them: a custom range is its dates.
        expect( line.keyLabels[0].label ).toMatch( /^Nov 2\s*–\s*8, 2026$/ );
        expect( line.keyLabels[1].label ).toMatch( /^Sep 15\s*–\s*Oct 12, 2026$/ );

        document.body.innerHTML = '<div id="kc"><div class="owa_chartLegend"></div></div>';
        chart.dom_id = 'kc';
        chart.flotOptions = { legend: { show: false } };
        chart.drawCompareKey( [ { color: '#1874CD' }, Object.assign( {}, line, { color: '#1874CD' } ) ] );

        const key = document.querySelector( '#kc .owa_chartKey' );
        expect( key.querySelector( '.owa_chartKeyPeriod' ).textContent ).toBe( line.keyLabels[0].label );
        expect( key.querySelector( '.owa_chartKeyCompare' ).textContent ).toBe( line.keyLabels[1].label );
        expect( key.querySelector( '.owa_chartKeyPeriod' ).hasAttribute( 'title' ) ).toBe( false );
    } );

    test( 'a preset and a relative comparison are named as the picker names them, dates in the title', () => {
        OWA.periodNames = { periods: { last_seven_days: 'Last 7 days' }, compares: { previous_period_dow: 'Previous period (same weekdays)' } };

        const rs = Object.assign( resultSet( [ [ '20261102', 10 ] ], [ [ '20261102', '20261026', 1 ] ] ),
            { timePeriod: { period: 'last_seven_days', startDate: '20261102', endDate: '20261108' } } );
        const chart = new OWA.areaChart();
        const line  = chart.singleSeries( rs, 'date', 'pageViews' ).series.find( ( s ) => s.isCompare );

        document.body.innerHTML = '<div id="kc"><div class="owa_chartLegend"></div></div>';
        chart.dom_id = 'kc';
        chart.flotOptions = { legend: { show: false } };
        chart.drawCompareKey( [ { color: '#1874CD' }, Object.assign( {}, line, { color: '#1874CD' } ) ] );

        const period = document.querySelector( '.owa_chartKeyPeriod' );
        expect( period.textContent ).toBe( 'Last 7 days' );
        expect( period.title ).toMatch( /^Nov 2\s*–\s*8, 2026$/ );
        expect( document.querySelector( '.owa_chartKeyCompare' ).textContent ).toBe( 'Previous period (same weekdays)' );

        delete OWA.periodNames;
    } );

    test( 'no period on the result set, no key', () => {
        const rs = resultSet( [ [ '20261102', 10 ] ], [ [ '20261102', '20261026', 1 ], [ '20261103', '20261027', 2 ] ] );
        expect( new OWA.areaChart().singleSeries( rs, 'date', 'pageViews' ).series.find( ( s ) => s.isCompare ).keyLabels ).toBeNull();
    } );

    test( 'no comparison, no second line', () => {
        expect( new OWA.areaChart().singleSeries( resultSet( [ [ '20261102', 10 ] ] ), 'date', 'pageViews' ).series ).toHaveLength( 1 );
    } );
} );
