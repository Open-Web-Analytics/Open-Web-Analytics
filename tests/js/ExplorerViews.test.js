// The pills import jQuery as a namespace and call it. Webpack makes that
// callable; babel-jest's interop does only when jQuery is marked an ES module.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/owa.resultSetExplorer.js');

/**
 * A view name the explorer does not know draws nothing and does not throw.
 *
 * A throw here would stop the rest of loader() -- the queued calls.
 */
describe( 'the result set explorer\'s views', () => {

    beforeEach( () => { document.body.innerHTML = '<div id="x"></div>'; } );

    test( 'sparkline is no longer offered', () => {
        const e = new OWA.resultSetExplorer( 'x' );
        expect( e.options.views ).not.toContain( 'sparkline' );
        expect( e.viewMethods ).not.toHaveProperty( 'sparkline' );
    } );

    test( 'every offered view has a method that draws it', () => {
        const e = new OWA.resultSetExplorer( 'x' );
        e.options.views.forEach( ( v ) => {
            expect( typeof e[ e.viewMethods[ v ] ] ).toBe( 'function' );
        } );
    } );

    test( 'an unknown view loads the data, runs the queue, and does not throw', () => {
        const e = new OWA.resultSetExplorer( 'x' );
        const queued = jest.fn();
        e.queuedProbe = queued;
        e.asyncQueue = [ [ 'queuedProbe', 'arg' ] ];
        jest.spyOn( e, 'setResultSet' ).mockImplementation( function ( d ) { this.resultSet = d; } );

        expect( () => e.loadFromArray( { resultsRows: [] }, 'sparkline' ) ).not.toThrow();
        expect( e.isLoaded ).toBe( true );
        expect( queued ).toHaveBeenCalledWith( 'arg' );
    } );
} );
