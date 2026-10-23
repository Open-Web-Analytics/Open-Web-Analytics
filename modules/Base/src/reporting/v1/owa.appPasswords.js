import $ from 'jquery';
import { OWA } from './owa.js';

/**
 * The Add dialog on the Application Passwords screen (my_app_passwords.php).
 *
 * Add opens it; Create sends its form, nonce included, with fetch() to
 * base.myAppPasswordCreateAjax (Core\Controller\Ajax). The answer carries
 * the token, which the dialog shows once, and the new password's row
 * is added to the roster behind it.
 *
 * Nothing is posted to the page itself, so reloading it repeats nothing.
 */

var FALLBACK_ERROR = 'The application password could not be created.';

/** A roster row for a password just made, from the page's own row template. */
function addRow( made ) {

    var template = document.getElementById( 'owa_appPasswordRowTemplate' );
    var $row = $( template.content.firstElementChild.cloneNode( true ) );

    $row.attr( 'data-lookup-id', made.lookup_id );
    $row.find( '[data-field="name"]' ).text( made.name );
    $row.find( '[data-field="scope"]' ).text( made.scope_label );
    $row.find( '[data-field="created"]' ).text( made.created_display );
    $row.find( '[data-field="lookup_id"]' ).val( made.lookup_id );
    $row.find( '.owa_appPasswordRevoke' ).attr( 'data-owa-confirm-body',
        'Anything using “' + made.name + '” stops working immediately.' );

    // Newest first, as the server draws them.
    $( 'table.owa_appPasswords tbody' ).prepend( $row );
    $( 'table.owa_appPasswords' ).prop( 'hidden', false );
    $( '.owa_appPasswordsNone' ).prop( 'hidden', true );
}

function openAddDialog() {

    var $dialog = $( '#owa_appPasswordDialog' );
    var $form   = $dialog.find( '.owa_appPasswordForm' );
    var $result = $dialog.find( '.owa_appPasswordResult' );
    var $error  = $dialog.find( '.owa_appPasswordError' );
    var busy    = false;

    $form.prop( 'hidden', false ).get( 0 ).reset();
    $result.prop( 'hidden', true );
    $error.prop( 'hidden', true ).text( '' );
    $dialog.find( '.owa_appPasswordToken' ).val( '' );

    function showError( text ) {

        $error.text( text ).prop( 'hidden', false );
    }

    function create() {

        if ( busy ) { return; }

        var name = $.trim( $form.find( '[name="name"]' ).val() );

        if ( ! name ) {

            showError( 'Give the application password a name.' );
            $form.find( '[name="name"]' ).trigger( 'focus' );
            return;
        }

        busy = true;
        $error.prop( 'hidden', true );
        $( '.owa_appPasswordCreate' ).prop( 'disabled', true );

        var form = $form.get( 0 );

        fetch( form.action, {
            method: 'POST',
            credentials: 'same-origin',
            body: new URLSearchParams( new FormData( form ) )
        } )
            .then( function ( r ) {

                return r.json().catch( function () { return {}; } ).then( function ( json ) {

                    return { ok: r.ok, json: json };
                } );
            } )
            .then( function ( res ) {

                if ( ! res.ok || ! res.json.data || ! res.json.data.token ) {

                    showError( res.json.error || FALLBACK_ERROR );
                    return;
                }

                var made = res.json.data;

                addRow( made );

                $form.prop( 'hidden', true );
                $result.find( '.owa_appPasswordResultName' ).text( made.name );
                $result.prop( 'hidden', false );
                $dialog.dialog( 'option', 'buttons', doneButtons );
                $result.find( '.owa_appPasswordToken' ).val( made.token ).trigger( 'focus' ).trigger( 'select' );
            } )
            .catch( function () {

                showError( FALLBACK_ERROR );
            } )
            .then( function () {

                busy = false;
                $( '.owa_appPasswordCreate' ).prop( 'disabled', false );
            } );
    }

    var createButtons = [
        {
            text: 'Cancel',
            class: 'owa-button owa-button-quiet owa_appPasswordCancel',
            click: function () { $( this ).dialog( 'close' ); }
        },
        {
            text: 'Create',
            class: 'owa-button owa_appPasswordCreate',
            click: create
        }
    ];

    var doneButtons = [
        {
            text: 'Done',
            class: 'owa-button owa_appPasswordDone',
            click: function () { $( this ).dialog( 'close' ); }
        }
    ];

    // Enter in the name field.
    $form.off( 'submit.owaAppPassword' ).on( 'submit.owaAppPassword', function ( event ) {

        event.preventDefault();
        create();
    } );

    $dialog.prop( 'hidden', false ).dialog( {
        modal: true,
        resizable: false,
        draggable: false,
        // Wide enough to show a whole token, within a phone's screen.
        width: Math.min( 560, window.innerWidth - 32 ),
        dialogClass: 'owa_confirmDialogFrame owa_appPasswordDialogFrame',
        buttons: createButtons,
        close: function () {

            // The token is gone from the page once the dialog is.
            $dialog.find( '.owa_appPasswordToken' ).val( '' );
            $dialog.dialog( 'destroy' ).prop( 'hidden', true );
        }
    } );

    $form.find( '[name="name"]' ).trigger( 'focus' );
}

$( function () {

    $( document ).on( 'click', '.owa_appPasswordAdd', function ( event ) {

        event.preventDefault();
        openAddDialog();
    } );
} );

export { OWA };
