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
require( '../../modules/Base/src/reporting/v1/owa.sparkline.js' );

const day = ( ymd ) => Date.UTC( +ymd.substr( 0, 4 ), +ymd.substr( 4, 2 ) - 1, +ymd.substr( 6, 2 ) );

/**
 * A trend's legend entries are toggle buttons a keyboard can reach and press,
 * and a chart has a text alternative: the plot's accessible name summarises
 * it, and a sparkline says its range.
 */
describe( 'a trend chart', () => {

    let realPlot;

    /*
     * flot rebuilds the legend on every draw: one column per series, a swatch
     * cell then a label cell. The stand-in does the same into the container.
     */
    beforeEach( () => {
        realPlot = $.plot;
        $.plot = jest.fn( ( $placeholder, series, options ) => {
            const cells = series.filter( ( s ) => ! s.isCompare ).map( ( s ) =>
                '<td class="legendColorBox"><div></div></td><td class="legendLabel">' + s.label + '</td>' ).join( '' );
            options.legend.container.html( '<table><tr>' + cells + '</tr></table>' );
        } );

        document.body.innerHTML = '<div id="c"><div class="owa_areaChart"></div><div class="owa_chartLegend"></div></div>';
    } );

    afterEach( () => {
        $.plot = realPlot;
    } );

    function chart( labels ) {
        const c = new OWA.areaChart();
        c.dom_id = 'c';
        c.domSelector = '#c > .owa_areaChart';
        c.xType = 'yyyymmdd';
        c.selected = null;
        c.dataseries = labels.map( ( label, i ) => ( {
            label,
            color: '#00000' + i,
            data: [ [ day( '20261102' ), 4 + i ], [ day( '20261103' ), 10 * ( i + 1 ) ], [ day( '20261104' ), 0 ] ],
        } ) );
        c.flotOptions = { legend: { show: true, container: $( '#c > .owa_chartLegend' ) } };
        c.draw();
        c.bindLegend();
        return c;
    }

    const labels = () => $( '#c .legendLabel' ).toArray();

    test( 'each legend label is a focusable toggle button, none pressed at first', () => {
        chart( [ 'Total', 'Chrome', 'Safari' ] );

        for ( const el of labels() ) {
            expect( el.getAttribute( 'role' ) ).toBe( 'button' );
            expect( el.getAttribute( 'tabindex' ) ).toBe( '0' );
            expect( el.getAttribute( 'aria-pressed' ) ).toBe( 'false' );
        }

        expect( $( '#c .legendColorBox' ).attr( 'aria-hidden' ) ).toBe( 'true' );
    } );

    test.each( [ 'Enter', ' ' ] )( '%p presses a label, focus stays on it, and pressing again releases it', ( key ) => {
        const c = chart( [ 'Total', 'Chrome', 'Safari' ] );

        labels()[1].focus();
        $( labels()[1] ).trigger( $.Event( 'keydown', { key } ) );

        expect( c.selected ).toBe( 1 );
        expect( labels().map( ( el ) => el.getAttribute( 'aria-pressed' ) ) ).toEqual( [ 'false', 'true', 'false' ] );
        expect( document.activeElement ).toBe( labels()[1] );

        $( labels()[1] ).trigger( $.Event( 'keydown', { key } ) );

        expect( c.selected ).toBeNull();
        expect( labels()[1].getAttribute( 'aria-pressed' ) ).toBe( 'false' );
    } );

    test( 'another key does nothing', () => {
        const c = chart( [ 'Total', 'Chrome' ] );

        for ( const key of [ 'a', 'Tab', 'ArrowDown', 'Escape' ] ) {
            $( labels()[1] ).trigger( $.Event( 'keydown', { key } ) );
        }

        expect( c.selected ).toBeNull();
    } );

    test( 'a click still selects a line', () => {
        const c = chart( [ 'Total', 'Chrome' ] );

        $( labels()[1] ).trigger( 'click' );

        expect( c.selected ).toBe( 1 );
        expect( labels()[1].getAttribute( 'aria-pressed' ) ).toBe( 'true' );
    } );

    test( 'one line: its label is not a control', () => {
        chart( [ 'Visits' ] );

        expect( labels()[0].hasAttribute( 'role' ) ).toBe( false );
        expect( labels()[0].hasAttribute( 'tabindex' ) ).toBe( false );
        expect( $( '#c .owa_chartLegend' ).hasClass( 'owa_chartLegendInteractive' ) ).toBe( false );
    } );

    test( 'the plot is an image named by a summary of its dates and each line\'s range', () => {
        chart( [ 'Total', 'Chrome' ] );

        const $plot = $( '#c > .owa_areaChart' );

        expect( $plot.attr( 'role' ) ).toBe( 'img' );
        expect( $plot.attr( 'aria-label' ) ).toBe(
            'Trend, 11/2/2026 to 11/4/2026. Total: lowest 0, highest 10. Chrome: lowest 0, highest 20.' );
    } );

    test( 'the summary leaves out a comparison and says when there is nothing plotted', () => {
        const c = new OWA.areaChart();
        c.xType = 'yyyymmdd';
        c.dataseries = [
            { label: '', data: [ [ day( '20261102' ), 3 ], [ day( '20261103' ), 1500 ] ] },
            { label: 'previous', isCompare: true, data: [ [ day( '20261102' ), 99999 ] ] },
        ];

        expect( c.chartSummary() ).toBe( 'Trend, 11/2/2026 to 11/3/2026. Lowest 3, highest 1,500.' );

        c.dataseries = [ { data: [] } ];

        expect( c.chartSummary() ).toBe( 'No data for this period' );
    } );
} );

describe( 'a sparkline', () => {

    test( 'is an image named by its lowest and highest value', () => {
        document.body.innerHTML = '<div id="s"></div>';
        $.fn.sparkline = jest.fn();

        const sl = new OWA.sparkline();
        sl.generate( { getSeries: () => [ 4, 1200, 7 ] }, 's', { metric: 'visits' } );

        const $line = $( '#s > .sparkline' );

        expect( $line.attr( 'role' ) ).toBe( 'img' );
        expect( $line.attr( 'aria-label' ) ).toBe( 'Trend: lowest 4, highest 1,200' );
    } );
} );
