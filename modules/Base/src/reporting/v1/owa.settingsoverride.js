/**
 * The Override switch on a setting rendered below the install.
 *
 * SettingsForm::scopedField() renders an inheriting setting as text showing the
 * value in force, with its control hidden and disabled and a switch beside it.
 * Switching it on hides the text and shows the control, enabled; switching it
 * off puts the inherited value back in the control and hides and disables it
 * again, so what the screen shows is what saving would leave in force. A
 * disabled control is not submitted, and the server reads the switch, not the
 * value, to decide whether this level holds one.
 *
 *   <select id="owa-setting-base-x" data-owa-inherited="1" disabled hidden>...</select>
 *   <output id="owa-setting-base-x-inherited">On</output>
 *   <span id="owa-setting-base-x-note-inherit" data-owa-note-inherit="owa-setting-base-x">Set at the install level.</span>
 *   <input type="checkbox" role="switch" data-owa-override="owa-setting-base-x"
 *          aria-describedby="owa-setting-base-x-inherited owa-setting-base-x-note-inherit">
 *   <div id="owa-setting-base-x-note-override" data-owa-note-override="owa-setting-base-x" hidden>
 *       Overrides the install level value of <code>On</code>.</div>
 *
 * The switch is described by whichever of the two states is showing.
 *
 * Delegated from the document, so a screen rendered after load is covered.
 */

/**
 * Apply one switch's state to its control, text and notes.
 *
 * @param {HTMLInputElement} sw
 */
export function applyOverride( sw ) {

    var id = sw.getAttribute( 'data-owa-override' );
    var control = id ? document.getElementById( id ) : null;

    if ( ! control ) {
        return;
    }

    var on = sw.checked;

    control.disabled = ! on;
    control.hidden = ! on;

    if ( ! on ) {
        control.value = control.getAttribute( 'data-owa-inherited' ) || '';
    }

    var text = document.getElementById( id + '-inherited' );
    var inherit = document.querySelector( '[data-owa-note-inherit="' + id + '"]' );
    var override = document.querySelector( '[data-owa-note-override="' + id + '"]' );

    if ( text ) {
        text.hidden = on;
    }

    if ( inherit ) {
        inherit.hidden = on;
    }

    if ( override ) {
        override.hidden = ! on;
    }

    var described = ( on ? [ override ] : [ text, inherit ] )
        .filter( function ( el ) { return el && el.id; } )
        .map( function ( el ) { return el.id; } )
        .join( ' ' );

    if ( described ) {
        sw.setAttribute( 'aria-describedby', described );
    }

    if ( on ) {
        control.focus();
    }
}

if ( typeof document !== 'undefined' ) {

    document.addEventListener( 'change', function ( e ) {

        var t = e.target;

        if ( t && t.matches && t.matches( 'input[data-owa-override]' ) ) {
            applyOverride( t );
        }
    } );
}
