import jQuery from 'jquery';

/**
 * A success message (msgs.php) goes away on its own: long enough to read a
 * sentence, then a fade rather than a disappearance -- something vanishing on
 * its own reads as a glitch. Errors stay; they describe something still wrong.
 */

/** The success message's element. */
export const TRANSIENT_SELECTOR = '.owa_transientStatus';

/** How long a message stays before it fades, in milliseconds. */
export const SHOWN_FOR = 6000;

/**
 * Fade the page's success messages after SHOWN_FOR.
 *
 * @return {boolean} whether the page has one
 */
export function fadeTransientStatus() {

    if ( ! document.querySelector( TRANSIENT_SELECTOR ) ) {
        return false;
    }

    setTimeout( function () {
        jQuery( TRANSIENT_SELECTOR ).fadeOut( 600 );
    }, SHOWN_FOR );

    return true;
}

if ( typeof document !== 'undefined' ) {

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', fadeTransientStatus );
    } else {
        fadeTransientStatus();
    }
}
