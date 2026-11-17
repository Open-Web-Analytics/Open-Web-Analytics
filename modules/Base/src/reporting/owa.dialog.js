import { OWA } from './owa.format.js';

var count = 0;

var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), '
    + 'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

function elementOf( x ) {

    return x && x.jquery ? x.get( 0 ) : x;
}

function firstFocusable( root ) {

    var found = root.querySelectorAll( FOCUSABLE );

    for ( var i = 0; i < found.length; i++ ) {

        if ( ! found[ i ].closest( '[hidden]' ) ) {

            return found[ i ];
        }
    }

    return null;
}

/**
 * A modal dialog: the browser's <dialog>, opened with showModal().
 *
 * The browser makes the page behind it inert, draws it above everything else
 * and closes it on Escape. This adds the frame every OWA dialog shares: a
 * heading the dialog is named by (aria-labelledby), an optional close button,
 * a row of buttons, and focus returned to whatever opened it.
 *
 *   var d = OWA.dialog( content, {
 *       title: 'Delete this user?',
 *       width: 440,
 *       buttons: [ { text: 'Cancel', className: 'owa-button owa-button-quiet', click: function ( d ) { d.close(); } } ]
 *   } );
 *   d.open( opener );
 *
 * `content` is an element or a jQuery object. The dialog takes its place in
 * the document, so rules scoped to an ancestor (`.owa .owa_...`) still match
 * it; content not yet in the document goes at the end of <body>. Content
 * declared hidden in a page (the hidden attribute, or an inline display:none)
 * is shown inside the dialog. With no `title`, the content's title attribute
 * is the heading, and is removed so it is not also a tooltip.
 *
 * Options:
 *   title          the heading
 *   width          the width on a wide screen, in px; on a narrower window it
 *                  is the window less 16px each side (owa.report.css)
 *   className      classes for the <dialog>
 *   closeButton    an X in the heading
 *   buttons        [ { text, className, click( dialog, event ) } ], in order
 *   onClose        called once each time it closes, however it was closed
 *   removeOnClose  the dialog leaves the document when it closes
 *
 * On opening, focus goes to the first [autofocus] element, else the first
 * control in the content, else the first button, else the close button.
 *
 * @param {Element|jQuery} content
 * @param {Object} options
 * @return {Object} { element, body, open( opener ), close(), isOpen(), setTitle( text ), setButtons( buttons ) }
 */
export function dialog( content, options ) {

    var opts = Object.assign( {
        title: '',
        width: 440,
        className: '',
        closeButton: false,
        buttons: [],
        onClose: null,
        removeOnClose: false
    }, options || {} );

    content = elementOf( content );

    var titleId = 'owa_dialogTitle_' + ( ++count );
    var el      = document.createElement( 'dialog' );
    var head    = document.createElement( 'div' );
    var heading = document.createElement( 'h2' );
    var body    = document.createElement( 'div' );
    var bar     = document.createElement( 'div' );
    var opener  = null;
    var shown   = false;
    var handle;

    el.className = ( 'owa_dialog ' + opts.className ).trim();
    el.setAttribute( 'aria-labelledby', titleId );
    el.style.setProperty( '--owa-dialog-width', opts.width + 'px' );

    head.className      = 'owa_dialogHead';
    heading.className   = 'owa_dialogTitle';
    heading.id          = titleId;
    heading.textContent = opts.title || content.getAttribute( 'title' ) || '';
    content.removeAttribute( 'title' );
    head.appendChild( heading );

    if ( opts.closeButton ) {

        var x = document.createElement( 'button' );

        x.type = 'button';
        x.className = 'owa_dialogClose';
        x.setAttribute( 'aria-label', OWA.l( 'Close' ) );
        x.setAttribute( 'title', OWA.l( 'Close' ) );
        x.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';
        x.addEventListener( 'click', function () { handle.close(); } );
        head.appendChild( x );
    }

    body.className = 'owa_dialogBody';
    bar.className  = 'owa_dialogButtons';

    if ( content.isConnected ) {

        content.parentNode.insertBefore( el, content );

    } else {

        document.body.appendChild( el );
    }

    content.hidden = false;

    if ( content.style.display === 'none' ) {

        content.style.display = '';
    }

    body.appendChild( content );
    el.appendChild( head );
    el.appendChild( body );
    el.appendChild( bar );

    // Once per opening, whether closed by a button, the X or Escape.
    function finish() {

        if ( ! shown ) {

            return;
        }

        shown = false;

        var back = opener;

        opener = null;

        if ( back && back.isConnected && typeof back.focus === 'function' ) {

            back.focus();
        }

        if ( opts.onClose ) {

            opts.onClose( handle );
        }

        if ( opts.removeOnClose ) {

            el.remove();
        }
    }

    // Escape closes the dialog itself; its close event arrives after.
    el.addEventListener( 'close', finish );

    handle = {

        element: el,

        body: body,

        open: function ( from ) {

            if ( el.open ) {

                return handle;
            }

            opener = elementOf( from ) || document.activeElement;
            shown  = true;

            el.showModal();

            var first = el.querySelector( '[autofocus]' )
                || firstFocusable( body ) || firstFocusable( bar ) || firstFocusable( head );

            if ( first ) {

                first.focus();
            }

            return handle;
        },

        // Focus is back on the opener when this returns, so whatever the
        // caller does next (focusing something else, navigating) wins.
        close: function () {

            if ( el.open ) {

                el.close();
            }

            finish();
        },

        isOpen: function () {

            return el.open;
        },

        setTitle: function ( text ) {

            heading.textContent = text;
        },

        setButtons: function ( buttons ) {

            bar.textContent = '';

            ( buttons || [] ).forEach( function ( b ) {

                var button = document.createElement( 'button' );

                button.type = 'button';
                button.className = b.className || 'owa-button';
                button.textContent = b.text;
                button.addEventListener( 'click', function ( event ) {

                    b.click( handle, event );
                } );
                bar.appendChild( button );
            } );

            bar.hidden = ! bar.children.length;
        }
    };

    handle.setButtons( opts.buttons );

    return handle;
}

OWA.dialog = dialog;

export { OWA };
