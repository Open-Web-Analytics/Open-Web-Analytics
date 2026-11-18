import jQuery from 'jquery';

/**
 * The users of a Property (property_access.php): the filter box shows only
 * the rows whose text contains what is typed, ignoring case.
 */

/**
 * Show the user rows containing the needle, hide the rest.
 *
 * @param {string} needle
 */
export function filterUsers( needle ) {

    needle = String( needle || '' ).toLowerCase();

    jQuery( '.owa-allowed-users tr.owa-user-row' ).each( function () {

        jQuery( this ).toggle( jQuery( this ).text().toLowerCase().indexOf( needle ) !== -1 );
    } );
}

/** Filter as the box is typed in. */
export function startUserFilter() {

    jQuery( '#owa-user-filter' ).on( 'keyup', function () {

        filterUsers( jQuery( this ).val() );
    } );
}

if ( typeof document !== 'undefined' ) {

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', startUserFilter );
    } else {
        startUserFilter();
    }
}
