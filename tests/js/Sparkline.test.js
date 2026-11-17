// owa.sparkline.js imports jQuery as a namespace for its result-set event.
jest.mock( 'jquery', () => {
    const jq = jest.requireActual( 'jquery' );
    jq.__esModule = true;
    return jq;
} );

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

require( '../../modules/Base/src/reporting/owa.sparkline.js' );

/**
 * A metric box's sparkline: the series as a line in an <svg>, scaled into the
 * box, with gaps where a value is missing.
 */
describe( 'the sparkline line', () => {

    const runs = ( data, inset = 0 ) => OWA.sparkline.runs( data, 100, 20, inset );

    test( 'spans the box: first value at the left, last at the right, lowest at the bottom, highest at the top', () => {
        expect( runs( [ 0, 10, 5 ] ) ).toEqual( [ [ [ 0, 20 ], [ 50, 0 ], [ 100, 10 ] ] ] );
    } );

    test( 'is inset so a stroke that wide is not clipped', () => {
        expect( runs( [ 0, 10 ], 1 ) ).toEqual( [ [ [ 1, 19 ], [ 99, 1 ] ] ] );
    } );

    test( 'scales by the series range, not from zero', () => {
        expect( runs( [ 100, 110 ] ) ).toEqual( [ [ [ 0, 20 ], [ 100, 0 ] ] ] );
    } );

    test( 'a flat series sits at mid-height', () => {
        expect( runs( [ 3, 3, 3 ] ) ).toEqual( [ [ [ 0, 10 ], [ 50, 10 ], [ 100, 10 ] ] ] );
    } );

    test( 'a single value is drawn flat across the box', () => {
        expect( runs( [ 7 ] ) ).toEqual( [ [ [ 0, 10 ], [ 100, 10 ] ] ] );
    } );

    test( 'an empty series, or one with no numbers, draws nothing', () => {
        expect( runs( [] ) ).toEqual( [] );
        expect( runs( null ) ).toEqual( [] );
        expect( runs( [ null, '', undefined ] ) ).toEqual( [] );
    } );

    test( 'a missing value breaks the line but keeps its place', () => {
        expect( runs( [ 0, null, 10, 0, '' ] ) ).toEqual( [
            [ [ 0, 20 ] ],
            [ [ 50, 0 ], [ 75, 20 ] ],
        ] );
    } );

    test( 'numbers given as strings are read as numbers', () => {
        expect( runs( [ '0', '10' ] ) ).toEqual( [ [ [ 0, 20 ], [ 100, 0 ] ] ] );
    } );
} );

describe( 'the sparkline svg', () => {

    test( 'is the size asked for, and an image named by its label', () => {
        const svg = OWA.sparkline.svg( [ 1, 2 ], { width: '100px', height: '20px', lineWidth: 2 }, 'Trend: lowest 1, highest 2' );

        expect( svg.getAttribute( 'width' ) ).toBe( '100' );
        expect( svg.getAttribute( 'height' ) ).toBe( '20' );
        expect( svg.getAttribute( 'viewBox' ) ).toBe( '0 0 100 20' );
        expect( svg.getAttribute( 'role' ) ).toBe( 'img' );
        expect( svg.getAttribute( 'aria-label' ) ).toBe( 'Trend: lowest 1, highest 2' );
        expect( svg.hasAttribute( 'aria-hidden' ) ).toBe( false );
    } );

    test( 'with no label it is hidden from assistive technology', () => {
        const svg = OWA.sparkline.svg( [ 1, 2 ], {} );

        expect( svg.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
        expect( svg.hasAttribute( 'role' ) ).toBe( false );
    } );

    test( 'draws the line, and the area under it down to the bottom edge', () => {
        const svg = OWA.sparkline.svg( [ 0, 10 ], { width: 100, height: 20, lineWidth: 2 } );
        const line = svg.querySelector( '[data-ui="sparkline-line"]' );
        const fill = svg.querySelector( '[data-ui="sparkline-fill"]' );

        expect( line.getAttribute( 'd' ) ).toBe( 'M1 19 L99 1' );
        expect( line.getAttribute( 'stroke-width' ) ).toBe( '2' );
        expect( line.getAttribute( 'fill' ) ).toBe( 'none' );
        expect( fill.getAttribute( 'd' ) ).toBe( 'M1 20 L1 19 L99 1 L99 20 Z' );
    } );

    test( 'fill: false draws the line alone', () => {
        const svg = OWA.sparkline.svg( [ 0, 10 ], { fill: false } );

        expect( svg.querySelector( '[data-ui="sparkline-fill"]' ) ).toBeNull();
        expect( svg.querySelector( '[data-ui="sparkline-line"]' ) ).not.toBeNull();
    } );

    test( 'a value alone between gaps is a dot: a zero-length segment', () => {
        const svg = OWA.sparkline.svg( [ null, 5, null, 0, 10 ], { width: 100, height: 20, lineWidth: 0 } );

        expect( svg.querySelector( '[data-ui="sparkline-line"]' ).getAttribute( 'd' ) )
            .toBe( 'M25 10 L25 10 M75 20 L100 0' );
    } );

    test( 'an empty series is an empty image', () => {
        const svg = OWA.sparkline.svg( [], {}, 'Trend' );

        expect( svg.querySelectorAll( 'path' ) ).toHaveLength( 0 );
        expect( svg.getAttribute( 'aria-label' ) ).toBe( 'Trend' );
    } );
} );

describe( 'a metric box sparkline', () => {

    test( 'is drawn into the box once per result set, at 100 by 20 with a 2px line', () => {
        document.body.innerHTML = '<div id="box"></div>';

        const sl = new OWA.sparkline();
        sl.generate( { getSeries: () => [ 4, null, 9 ] }, 'box', { metric: 'visits' } );

        const svgs = document.querySelectorAll( '#box [data-ui="sparkline"]' );

        expect( svgs ).toHaveLength( 1 );
        expect( svgs[0].getAttribute( 'width' ) ).toBe( '100' );
        expect( svgs[0].getAttribute( 'height' ) ).toBe( '20' );
        expect( svgs[0].querySelector( '[data-ui="sparkline-line"]' ).getAttribute( 'stroke-width' ) ).toBe( '2' );
        // The range of the values drawn: the gap is not a zero.
        expect( svgs[0].getAttribute( 'aria-label' ) ).toBe( 'Trend: lowest 4, highest 9' );

        require( 'jquery' )( '#box' ).trigger( 'new_result_set', [ { getSeries: () => [ 1, 2 ] } ] );

        expect( document.querySelectorAll( '#box [data-ui="sparkline"]' ) ).toHaveLength( 1 );
        expect( document.querySelector( '#box [data-ui="sparkline"]' ).getAttribute( 'aria-label' ) )
            .toBe( 'Trend: lowest 1, highest 2' );
    } );

    test( 'a result set with no series draws the flat zero line it always has', () => {
        document.body.innerHTML = '<div id="box"></div>';

        new OWA.sparkline().generate( { getSeries: () => undefined }, 'box', { metric: 'visits' } );

        expect( document.querySelector( '#box [data-ui="sparkline"]' ).getAttribute( 'aria-label' ) )
            .toBe( 'Trend: lowest 0, highest 0' );
    } );
} );
