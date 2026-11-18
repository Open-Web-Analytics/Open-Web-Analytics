/**
 * @jest-environment jsdom
 */
jest.mock( 'jquery', () => {
    const jq = jest.requireActual( 'jquery' );
    jq.__esModule = true;
    return jq;
} );

import { Util } from '../../modules/Base/src/common/Util.js';
import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

/**
 * The tracker's and the reporting UI's string and type helpers, on native code.
 *
 * The cookie-domain hash is the one value here that must not move: every OWA
 * cookie carries it, and a different hash makes an existing cookie unreadable.
 * The expected values below were produced by the previous implementation.
 */

/** The previous encoder, as earlier trackers still write it. */
function phpStyleUrlEncode( str ) {
    return encodeURIComponent( String( str ) ).replace( /!/g, '%21' ).replace( /'/g, '%27' )
        .replace( /\(/g, '%28' ).replace( /\)/g, '%29' ).replace( /\*/g, '%2A' ).replace( /%20/g, '+' );
}

/** The decoder earlier trackers use. */
function earlierDecode( str ) {
    return decodeURIComponent( str.replace( /\+/g, '%20' ) );
}

const SAMPLES = [ 'a b', "it's (here)!*", 'x+y/z=', 'bücher', '{"vid":"1","nps":2}', 'eyJ2aWQiOiIxIn0+/=' ];

describe( 'the cookie-domain hash', () => {

    test.each( [
        [ 'example.com', 'b6fa4eb9' ],
        [ '.example.com', '57a831dc' ],
        [ 'test.openwebanalytics.com', '1ffcacd2' ],
        [ 'www.openwebanalytics.com', '622f6f2c' ],
        [ 'a', 'e8b7be43' ],
        [ 'bücher.example', 'fd5562f9' ],
        [ 'sub.domain.co.uk', 'a23b57d9' ],
        [ 'localhost', '9ec20823' ],
    ] )( '%s hashes to %s, as before', ( domain, hash ) => {
        expect( Util.getCookieDomainHash( domain ) ).toBe( hash );
    } );

    test( 'dechex writes a negative 32-bit integer as its two\'s complement', () => {
        expect( Util.dechex( -1 ) ).toBe( 'ffffffff' );
        expect( Util.dechex( -1225109831 ) ).toBe( 'b6fa4eb9' );
        expect( Util.dechex( 255 ) ).toBe( 'ff' );
        expect( Util.dechex( 0 ) ).toBe( '0' );
    } );
} );

describe( 'URL encoding', () => {

    test( 'a space is %20 and the characters encodeURIComponent leaves alone stay', () => {
        expect( Util.urlEncode( "a b!'()*" ) ).toBe( "a%20b!'()*" );
        expect( OWA.util.urlEncode( "a b!'()*" ) ).toBe( "a%20b!'()*" );
    } );

    test.each( SAMPLES )( '%j round-trips, and an earlier tracker decodes it', ( s ) => {
        expect( Util.urldecode( Util.urlEncode( s ) ) ).toBe( s );
        expect( earlierDecode( Util.urlEncode( s ) ) ).toBe( s );
    } );

    test.each( SAMPLES )( '%j as PHP and earlier trackers encode it is decoded', ( s ) => {
        expect( Util.urldecode( phpStyleUrlEncode( s ) ) ).toBe( s );
    } );

    test( 'urldecode reads a non-string', () => {
        expect( Util.urldecode( 12 ) ).toBe( '12' );
    } );
} );

describe( 'is_object', () => {

    test.each( [
        [ {}, true ], [ { a: 1 }, true ], [ new Date(), true ],
        [ [], false ], [ null, false ], [ undefined, false ], [ '23', false ], [ 3, false ], [ () => {}, false ],
    ] )( '%p is %p', ( value, expected ) => {
        expect( Util.is_object( value ) ).toBe( expected );
        expect( OWA.util.is_object( value ) ).toBe( expected );
    } );
} );

describe( 'in_array', () => {

    test( 'finds a value in an array or an object\'s values', () => {
        expect( OWA.util.in_array( 'b', [ 'a', 'b' ] ) ).toBe( true );
        expect( OWA.util.in_array( 'c', [ 'a', 'b' ] ) ).toBe( false );
        expect( OWA.util.in_array( 2, { x: 1, y: 2 } ) ).toBe( true );
    } );

    test( 'compares loosely unless strict', () => {
        expect( OWA.util.in_array( '1', [ 1 ] ) ).toBe( true );
        expect( OWA.util.in_array( '1', [ 1 ], true ) ).toBe( false );
    } );

    test( 'an empty haystack holds nothing', () => {
        expect( OWA.util.in_array( 'a', null ) ).toBe( false );
    } );
} );

describe( 'removed helpers', () => {

    test( 'Util.rand and OWA.util.urldecode are gone', () => {
        expect( Util.rand ).toBeUndefined();
        expect( OWA.util.urldecode ).toBeUndefined();
    } );
} );
