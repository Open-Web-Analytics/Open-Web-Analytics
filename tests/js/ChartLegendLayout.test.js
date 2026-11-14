// The chart imports jQuery as a namespace and calls it; see HourlyTrend.test.js.
jest.mock( 'jquery', () => {
    const jq = jest.requireActual( 'jquery' );
    jq.__esModule = true;
    return jq;
} );

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const $ = require( 'jquery' );

global.jQuery = global.$ = $;

require( '../../modules/Base/src/reporting/v1/owa.areachart.js' );

const day = ( ymd ) => Date.UTC( +ymd.substr( 0, 4 ), +ymd.substr( 4, 2 ) - 1, +ymd.substr( 6, 2 ) );

/**
 * A trend's legend and date axis fit the chart's width: as many legend
 * entries a row as there is room for, the rest wrapping under them, and fewer
 * date labels on a narrow chart. On a phone one row of eight entries ran past
 * the widget and made it scroll sideways.
 */
describe( 'fitting the width', () => {

    test.each( [
        [ 'a phone', 346, 120, 8, 2 ],
        [ 'a desktop', 1000, 120, 8, 8 ],
        [ 'never fewer than one', 60, 120, 8, 1 ],
        [ 'never more than there are', 1000, 120, 3, 3 ],
        [ 'an unknown width', 0, 120, 5, 5 ],
    ] )( '%s', ( name, width, each, most, expected ) => {
        expect( OWA.areaChart.fitCount( width, each, most ) ).toBe( expected );
    } );
} );

describe( 'a trend drawn at a width', () => {

    let realPlot, realWidth, plotted;

    beforeEach( () => {
        realPlot = $.plot;
        realWidth = $.fn.width;
        $.plot = jest.fn( ( $placeholder, series, options ) => {
            plotted = JSON.parse( JSON.stringify( { legend: { noColumns: options.legend.noColumns }, xaxis: options.xaxis } ) );
        } );
        document.body.innerHTML = '<div id="c"><div class="owa_areaChart"></div><div class="owa_chartLegend"></div></div>';
    } );

    afterEach( () => {
        $.plot = realPlot;
        $.fn.width = realWidth;
    } );

    function draw( width, labels ) {
        $.fn.width = function () { return width; };
        const c = new OWA.areaChart();
        c.dom_id = 'c';
        c.domSelector = '#c > .owa_areaChart';
        c.xType = 'yyyymmdd';
        c.selected = null;
        c.dataseries = labels.map( ( label, i ) => ( {
            label, color: '#00000' + i,
            data: [ [ day( '20261101' ), 1 ], [ day( '20261130' ), 2 ] ],
        } ) );
        c.flotOptions = {
            legend: { show: true, noColumns: labels.length, container: $( '#c > .owa_chartLegend' ) },
            xaxis: { mode: 'time', ticks: 10 },
        };
        c.draw();
        return c;
    }

    const paths = [ 'Total', '/', '/docs', '/pricing', '/about', '/checkout' ];

    test( 'on a phone, two legend entries a row and four date labels', () => {
        draw( 346, paths );

        expect( plotted.legend.noColumns ).toBe( 2 );
        expect( plotted.xaxis.ticks ).toBe( 4 );
    } );

    test( 'on a desktop, the legend is one row and the dates are as many as before', () => {
        draw( 1000, paths );

        expect( plotted.legend.noColumns ).toBe( 6 );
        expect( plotted.xaxis.ticks ).toBe( 10 );
    } );

    test( 'redrawn wider, it gets its columns and labels back', () => {
        const c = draw( 346, paths );

        $.fn.width = function () { return 1000; };
        c.draw();

        expect( plotted.legend.noColumns ).toBe( 6 );
        expect( plotted.xaxis.ticks ).toBe( 10 );
    } );

    test( 'a compared line has no entry, so it takes no column', () => {
        const c = draw( 1000, [ 'Total', 'Chrome' ] );

        c.dataseries.push( { label: '', isCompare: true, compareOf: 0, color: '#cccccc', data: c.dataseries[0].data } );
        c.draw();

        expect( plotted.legend.noColumns ).toBe( 2 );
    } );

    test( 'the plot carries its data-ui hook', () => {
        const c = new OWA.areaChart();
        document.body.innerHTML = '<div id="trend1"></div>';
        c.dom_id = 'trend1';
        c.domSelector = '#trend1 > .owa_areaChart';
        c.options.width = '100%';
        c.setupAreaChart( [], 'trend1' );

        expect( $( '#trend1 > .owa_areaChart' ).attr( 'data-ui' ) ).toBe( 'chart:trend1' );
    } );
} );
