jest.mock( 'jquery', () => {
    const jq = jest.requireActual( 'jquery' );
    jq.__esModule = true;
    return jq;
} );

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const $ = require( 'jquery' );

global.jQuery = global.$ = $;

require( '../../modules/Base/src/reporting/v1/owa.areachart.js' );

/**
 * A chart legend's labels are tracked values -- a page title, a campaign name
 * -- stored as the text that was sent, and flot writes a label into the legend
 * as HTML. So the label is escaped on the way in.
 */
describe( 'a chart legend label', () => {

    test( 'is escaped as text', () => {
        const label = new OWA.areaChart().legendLabel( '<img src=x onerror=alert(1)> & "Café"' );

        expect( label ).toBe( '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;Café&quot;' );

        document.body.innerHTML = '<table><tr><td>' + label + '</td></tr></table>';
        expect( document.querySelector( 'img' ) ).toBeNull();
        expect( document.querySelector( 'td' ).textContent ).toBe( '<img src=x onerror=alert(1)> & "Café"' );
    } );

    test( 'is what the chart hands flot as its label formatter', () => {
        const src = require( 'fs' ).readFileSync(
            require( 'path' ).join( __dirname, '../../modules/Base/src/reporting/v1/owa.areachart.js' ), 'utf8' );

        expect( src ).toMatch( /labelFormatter:\s*this\.legendLabel/ );
    } );
} );
