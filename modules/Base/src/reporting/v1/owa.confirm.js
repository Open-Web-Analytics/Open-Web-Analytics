import $ from 'jquery';
import { OWA } from './owa.js';

/**
 * The confirmation an irreversible action gets before it runs.
 *
 * window.confirm() was doing this job on the Profile delete. It cannot carry
 * more than one line, cannot be styled, cannot say what is about to be kept as
 * opposed to destroyed, and in an automated browser it is DISMISSED by default
 * -- so a test driving a delete passes while deleting nothing.
 *
 * Anything destructive opts in by carrying data-owa-confirm. Works on both
 * shapes a delete takes here: a link (users, custom reports) and a submit
 * button inside a form (Profile, Property). The handler is delegated from the
 * document, so markup rendered after load is covered too.
 *
 *   <a href="..."
 *      data-owa-confirm
 *      data-owa-confirm-title="Delete this user?"
 *      data-owa-confirm-body="They lose access immediately."
 *      data-owa-confirm-proceed="Delete user">Delete</a>
 *
 * Only the attribute is required; every string has a default.
 */

var DEFAULTS = {
    title: 'Are you sure?',
    body: 'This action cannot be undone from this screen.',
    proceed: 'Delete',
    cancel: 'Cancel'
};

/**
 * Read the strings off the element, falling back rather than rendering blanks.
 */
function settingsFor( el ) {

    var $el = $( el );

    return {
        title: $el.data( 'owa-confirm-title' ) || DEFAULTS.title,
        body: $el.data( 'owa-confirm-body' ) || DEFAULTS.body,
        proceed: $el.data( 'owa-confirm-proceed' ) || DEFAULTS.proceed,
        cancel: $el.data( 'owa-confirm-cancel' ) || DEFAULTS.cancel
    };
}

/**
 * Ask, then run onProceed if they say yes.
 *
 * Built fresh each time and destroyed on close. jQuery UI's .dialog() MOVES the
 * element it is given to the end of <body>, so a single reused node would
 * migrate out of whatever it was declared inside; making one per ask keeps that
 * from mattering.
 */
OWA.confirmAction = function ( options, onProceed ) {

    var opts = $.extend( {}, DEFAULTS, options || {} );

    var $dialog = $( '<div>' )
        .addClass( 'owa_confirmDialog' )
        .attr( 'id', 'owa_confirmDialog' )
        .attr( 'title', opts.title );

    $( '<p>' ).addClass( 'owa_confirmBody' ).text( opts.body ).appendTo( $dialog );

    $dialog.appendTo( 'body' ).dialog( {
        modal: true,
        resizable: false,
        draggable: false,
        width: 440,
        dialogClass: 'owa_confirmDialogFrame',
        // Cancel is FIRST in the DOM and focused, so the default answer to a
        // destructive question -- Enter, Escape, a stray click -- is "no".
        buttons: [
            {
                text: opts.cancel,
                class: 'owa-button owa-button-quiet owa_confirmCancel',
                click: function () {
                    $( this ).dialog( 'close' );
                }
            },
            {
                text: opts.proceed,
                class: 'owa-button owa-button-danger owa_confirmProceed',
                click: function () {
                    // Closed BEFORE proceeding: the action navigates, and a
                    // dialog left open flashes over the unloading page.
                    $( this ).dialog( 'close' );
                    onProceed();
                }
            }
        ],
        close: function () {
            $( this ).dialog( 'destroy' ).remove();
        }
    } );

    $( '.owa_confirmCancel' ).trigger( 'focus' );
};

$( function () {

    $( document ).on( 'click', '[data-owa-confirm]', function ( event ) {

        var el = this;

        // Already answered: the second click is the one we raised ourselves.
        if ( $( el ).data( 'owaConfirmed' ) ) {

            $( el ).removeData( 'owaConfirmed' );
            return true;
        }

        event.preventDefault();

        OWA.confirmAction( settingsFor( el ), function () {

            $( el ).data( 'owaConfirmed', true );

            var form = el.form;

            if ( form ) {

                /*
                 * Submitted through the button, not form.submit(): the button
                 * carries a name and value the controller reads (submit_btn),
                 * and form.submit() sends neither. requestSubmit() includes it;
                 * where it is missing, a click does the same and re-enters this
                 * handler, which the owaConfirmed flag lets through.
                 */
                if ( typeof form.requestSubmit === 'function' ) {

                    form.requestSubmit( el );

                } else {

                    el.click();
                }

                return;
            }

            el.click();
        } );

        return false;
    } );
} );

export { OWA };
