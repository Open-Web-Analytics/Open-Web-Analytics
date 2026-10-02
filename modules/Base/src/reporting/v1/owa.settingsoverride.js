/**
 * The Override switch on a setting rendered below the install.
 *
 * SettingsForm::scopedField() renders an inheriting setting disabled, showing
 * the value in force, with a switch beside it. Switching it on makes the
 * control editable; switching it off puts the inherited value back and
 * disables it again, so what the screen shows is what saving would leave in
 * force. A disabled control is not submitted, and the server reads the switch,
 * not the value, to decide whether this level holds one.
 *
 *   <select id="owa-setting-base-x" data-owa-inherited="1" disabled>...</select>
 *   <input type="checkbox" data-owa-override="owa-setting-base-x">
 *   <div data-owa-note-inherit="owa-setting-base-x">Currently set at the install level.</div>
 *   <div data-owa-note-override="owa-setting-base-x" hidden>Overrides the install level's On.</div>
 *
 * Delegated from the document, so a screen rendered after load is covered.
 */

/**
 * Apply one switch's state to its control and notes.
 *
 * @param {HTMLInputElement} sw
 */
export function applyOverride( sw ) {

    var id = sw.getAttribute( 'data-owa-override' );
    var control = id ? document.getElementById( id ) : null;

    if ( ! control ) {
        return;
    }

    control.disabled = ! sw.checked;

    if ( ! sw.checked ) {
        control.value = control.getAttribute( 'data-owa-inherited' ) || '';
    }

    var inherit = document.querySelector( '[data-owa-note-inherit="' + id + '"]' );
    var override = document.querySelector( '[data-owa-note-override="' + id + '"]' );

    if ( inherit ) {
        inherit.hidden = sw.checked;
    }

    if ( override ) {
        override.hidden = ! sw.checked;
    }

    if ( sw.checked ) {
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
