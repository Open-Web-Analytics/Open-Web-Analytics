import * as jQueryModule from 'jquery';
import { OWA } from './owa.js';

// Imported both ways the reporting modules import jQuery (default, and as a
// callable namespace), so whichever module loads this, it gets the function.
const $ = typeof jQueryModule === 'function' ? jQueryModule : jQueryModule.default;

/**
 * Size and place a jQuery UI dialog for the window it opens in.
 *
 * At most `preferred` wide, with a 16px gutter each side; at most the window's
 * height less 8px above and below, its content scrolling inside. On a short
 * window -- a phone held sideways -- it sits 8px from the top instead of
 * centred. Every dialog spreads this into its options:
 *
 *   $el.dialog( $.extend( { modal: true, ... }, OWA.dialogFit( 560 ) ) );
 *
 * `fitWidth` is not a jQuery UI option; jQuery UI keeps it with the rest, and
 * refitDialogs() reads it back to re-fit an open dialog when the window
 * changes size (a phone rotated with a dialog open).
 *
 * @param int    preferred  the width on a wide screen
 * @param object win        the window (tests pass a stand-in)
 * @return object dialog options
 */
export function dialogFit( preferred, win ) {

    win = win || window;

    var vw = win.innerWidth;
    var vh = win.innerHeight;

    return {
        fitWidth: preferred,
        width: Math.max( 0, Math.min( preferred, vw - 32 ) ),
        maxHeight: Math.max( 0, vh - 16 ),
        position: vh <= 500
            ? { my: 'center top', at: 'center top+8', of: win, collision: 'none' }
            : { my: 'center', at: 'center', of: win, collision: 'fit' }
    };
}

/** Re-fit one dialog sized by dialogFit() to the window as it is now. */
function refit( el ) {

    var $d = $( el );

    if ( ! $d.dialog( 'instance' ) || ! $d.dialog( 'isOpen' ) ) {

        return;
    }

    var preferred = $d.dialog( 'option', 'fitWidth' );

    if ( preferred ) {

        $d.dialog( 'option', dialogFit( preferred ) );
    }
}

/** Re-fit every open dialog that was sized by dialogFit(). */
export function refitDialogs() {

    if ( ! $.fn.dialog ) {

        return;
    }

    $( '.ui-dialog-content' ).each( function () {

        refit( this );
    } );
}

OWA.dialogFit = dialogFit;
OWA.refitDialogs = refitDialogs;

( function () {

    var timer = null;

    // A dialog built at page load (autoOpen: false) is sized again when it
    // opens, for the window it opens in.
    $( document ).on( 'dialogopen.owaDialogFit', '.ui-dialog-content', function () {

        refit( this );
    } );

    $( window ).on( 'resize.owaDialogFit orientationchange.owaDialogFit', function () {

        clearTimeout( timer );
        timer = setTimeout( refitDialogs, 120 );
    } );
}() );
