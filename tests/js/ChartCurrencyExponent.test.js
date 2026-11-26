/**
 * A chart plots currency in major units by the Property's currency's ISO 4217
 * exponent (the result set's currencyExponent), as the server formats it:
 * ¥1,500 is stored 1500 and plotted 1500, not 15.00; a KWD amount has three
 * decimals.
 */

jest.mock( 'jquery', () => {
    const jq = jest.requireActual( 'jquery' );
    jq.__esModule = true;
    return jq;
} );

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

const $ = require( 'jquery' );

global.jQuery = global.$ = $;

require( '../../modules/Base/src/reporting/owa.areachart.js' );

function revenue( exponent, formatted ) {
    return {
        currencyExponent: exponent,
        aggregates: { revenue: { name: 'revenue', data_type: 'currency', value: 1500, formatted_value: formatted } },
        resultsRows: [],
    };
}

describe( 'currency on a chart', () => {

    test.each( [
        [ 0, '¥1,500', 1500, 0 ],
        [ 2, '$15.00', 15, 2 ],
        [ 3, 'KWD 1.500', 1.5, 3 ],
    ] )( 'exponent %i: 1500 plots %s', ( exponent, formatted, plotted, decimals ) => {

        const chart = new OWA.areaChart();
        chart.currencyExponent = revenue( exponent ).currencyExponent;

        expect( chart.formatValue( 'currency', 1500 ) ).toBeCloseTo( plotted, 6 );
        expect( chart.yAxis( revenue( exponent, formatted ), 'revenue', [] ).tickDecimals ).toBe( decimals );
    } );

    test( 'generate() takes the exponent from the result set, and two without one', () => {

        const chart = new OWA.areaChart();

        try { chart.generate( revenue( 0, '¥1,500' ), null, 'owa-test-chart' ); } catch ( e ) { /* no DOM to draw in */ }
        expect( chart.currencyExponent ).toBe( 0 );

        const old = new OWA.areaChart();
        expect( old.formatValue( 'currency', 1500 ) ).toBe( 15 );
    } );
} );
