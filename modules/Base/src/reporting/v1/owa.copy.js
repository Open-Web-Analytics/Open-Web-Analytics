/**
 * The copy button on a code block (Template::codeBlock()).
 *
 * Delegated from the document, so a block rendered after load is covered.
 * Copies the block's text exactly as shown.
 *
 * navigator.clipboard needs a secure context, and many installs are served
 * over plain http on a LAN, so the selection-and-execCommand path is kept as
 * the fallback rather than dropped as legacy.
 */

var RESET_AFTER_MS = 2000;

function copyText( text ) {

    if ( window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText ) {

        return navigator.clipboard.writeText( text );
    }

    return new Promise( function ( resolve, reject ) {

        var area = document.createElement( 'textarea' );

        area.value = text;
        area.setAttribute( 'readonly', '' );
        area.style.position = 'fixed';
        area.style.top = '-1000px';
        document.body.appendChild( area );
        area.select();

        var ok = false;

        try {

            ok = document.execCommand( 'copy' );

        } catch ( e ) {

            ok = false;
        }

        document.body.removeChild( area );

        if ( ok ) {

            resolve();

        } else {

            reject( new Error( 'copy refused' ) );
        }
    } );
}

function show( button, state ) {

    var label = button.querySelector( '.owa-codeBlock__copyLabel' );
    var icon  = button.querySelector( 'i' );

    button.setAttribute( 'data-owa-copy-state', state );

    if ( label ) {

        label.textContent = state === 'copied' ? 'Copied' : ( state === 'failed' ? 'Press Ctrl+C' : 'Copy' );
    }

    if ( icon ) {

        icon.className = state === 'copied' ? 'fas fa-check' : 'far fa-copy';
    }
}

document.addEventListener( 'click', function ( e ) {

    var button = e.target.closest ? e.target.closest( '[data-owa-copy]' ) : null;

    if ( ! button ) {

        return;
    }

    var block = button.closest( '.owa-codeBlock' );
    var code  = block ? block.querySelector( 'code' ) : null;

    if ( ! code ) {

        return;
    }

    copyText( code.textContent ).then( function () {

        show( button, 'copied' );

    }, function () {

        // Leave the code selected so the keyboard shortcut finishes the job.
        var range = document.createRange();
        range.selectNodeContents( code );
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange( range );

        show( button, 'failed' );
    } );

    clearTimeout( button._owaCopyReset );
    button._owaCopyReset = setTimeout( function () { show( button, 'idle' ); }, RESET_AFTER_MS );
} );
