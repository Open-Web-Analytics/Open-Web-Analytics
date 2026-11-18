import $ from 'jquery';
import { OWA } from './owa.format.js';
import { dialog } from './owa.dialog.js';

/**
 * The confirmation an irreversible action gets before it runs.
 *
 * Not window.confirm(), which cannot carry more than one line, cannot be styled, cannot say what is about to be kept as
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

/** The strings an element leaves out, in the locale in use when asked. */
function defaults() {

    return {
        title: OWA.l( 'Are you sure?' ),
        body: OWA.l( 'This action cannot be undone from this screen.' ),
        proceed: OWA.l( 'Delete' ),
        cancel: OWA.l( 'Cancel' )
    };
}

/**
 * Read the strings off the element, falling back rather than rendering blanks.
 */
function settingsFor( el ) {

    var $el = $( el );
    var d   = defaults();

    return {
        title: $el.data( 'owa-confirm-title' ) || d.title,
        body: $el.data( 'owa-confirm-body' ) || d.body,
        proceed: $el.data( 'owa-confirm-proceed' ) || d.proceed,
        cancel: $el.data( 'owa-confirm-cancel' ) || d.cancel,
        opener: el
    };
}

/**
 * Ask, then run onProceed if they say yes.
 *
 * Built fresh each time and removed on close. Two options beyond the strings:
 * `paragraphs`, an array shown one paragraph each in place of `body`, and
 * `tone: 'notice'`, for a confirmation that is not a warning -- the proceed
 * button is then an ordinary one rather than red. `opener` is where focus
 * returns when it closes (the element focused when it opened, if not given).
 * Text is always set as text, never as HTML.
 */
OWA.confirmAction = function ( options, onProceed ) {

    var opts = $.extend( {}, defaults(), options || {} );

    var $content = $( '<div>' )
        .addClass( 'owa_confirmDialog' )
        .attr( 'id', 'owa_confirmDialog' )
        .attr( 'data-ui', 'dialog:confirm' );

    var paragraphs = $.isArray( opts.paragraphs ) && opts.paragraphs.length ? opts.paragraphs : [ opts.body ];

    $.each( paragraphs, function ( i, text ) {

        $( '<p>' ).addClass( 'owa_confirmBody' ).text( text ).appendTo( $content );
    } );

    return dialog( $content, {
        title: opts.title,
        width: 440,
        className: 'owa_confirmDialogFrame',
        removeOnClose: true,
        // Cancel is FIRST and focused, so the default answer to a destructive
        // question -- Enter, Escape, a stray click -- is "no".
        buttons: [
            {
                text: opts.cancel,
                className: 'owa-button owa-button-quiet owa_confirmCancel',
                click: function ( d ) {

                    d.close();
                }
            },
            {
                text: opts.proceed,
                className: 'owa-button ' + ( opts.tone === 'notice' ? '' : 'owa-button-danger ' ) + 'owa_confirmProceed',
                click: function ( d ) {

                    // Closed BEFORE proceeding: the action navigates, and a
                    // dialog left open flashes over the unloading page.
                    d.close();
                    onProceed();
                }
            }
        ]
    } ).open( opts.opener );
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
