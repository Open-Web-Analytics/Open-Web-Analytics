import $ from 'jquery';
import { OWA } from './owa.js';

/**
 * Add and remove funnel steps on the goal event screen.
 *
 * The rows are .constraintRow, the same markup the report builder uses, so they
 * arrive already styled -- including the + and X buttons. Those buttons are
 * bound PER ROW inside the report builder and the result set explorer, though,
 * so reusing the markup does not reuse the behaviour: the buttons rendered,
 * looked live, and did nothing.
 *
 * Delegated from the document rather than bound per row, because rows are added
 * at runtime and a per-row binding would have to be repeated for each new one --
 * which is the shape that left the originals unbound here in the first place.
 *
 * Progressive enhancement: with no JavaScript the form still saves whatever
 * steps were rendered. The server validates by TYPE and numbers the steps it
 * keeps, so nothing here decides anything.
 */

var STEP_SELECTOR = '#owa_goalEventFunnel .constraintRow';

OWA.funnelBuilder = {

    /** A blank copy of a row, so the new step does not inherit its values. */
    blankCopy: function ( $row ) {

        var $copy = $row.clone();

        $copy.find( 'input' ).val( '' );

        return $copy;
    },

    /**
     * Never remove the last row.
     *
     * Emptying the funnel is done by clearing the fields -- the server treats a
     * step with no path as one nobody filled in, and removes the funnel when
     * none are left. Removing the last ROW would leave no way to add one back
     * without reloading.
     */
    remove: function ( $row ) {

        if ( $( STEP_SELECTOR ).length <= 1 ) {

            $row.find( 'input' ).val( '' );

            return;
        }

        $row.remove();
    }
};

$( function () {

    $( document ).on( 'click keypress', '#owa_goalEventFunnel .constraintAddButton',
        function ( event ) {

            // Space and Enter, because these are spans rather than buttons.
            if ( event.type === 'keypress' && event.which !== 13 && event.which !== 32 ) {

                return;
            }

            event.preventDefault();

            var $row = $( this ).closest( '.constraintRow' );

            OWA.funnelBuilder.blankCopy( $row ).insertAfter( $row );
        } );

    $( document ).on( 'click keypress', '#owa_goalEventFunnel .constraintRemoveButton',
        function ( event ) {

            if ( event.type === 'keypress' && event.which !== 13 && event.which !== 32 ) {

                return;
            }

            event.preventDefault();

            OWA.funnelBuilder.remove( $( this ).closest( '.constraintRow' ) );
        } );
} );

export { OWA };
