/**
 * The report nav on a narrow screen: a panel behind a menu button.
 *
 * Below the breakpoint (owa.report.css) the nav leaves the page, so the report
 * has the whole width, and slides in over it from the menu button, which stays
 * in reach as the page scrolls. Choosing a report closes it again, as do its
 * close button (the open panel covers the menu button), a tap outside it and
 * Escape. At full width none of this runs: every handler asks
 * the media query first, so the desktop nav is untouched.
 *
 *   <div class="owa_reportNavRail" id="owa_reportNavRail">...</div>
 *   <button class="owa_reportNavToggle" aria-controls="owa_reportNavRail">...</button>
 */

export const NARROW = '(max-width: 900px)';
export const OPEN = 'owa_reportNavOpen';

/** Whether the narrow layout is in force now. */
export function isNarrow() {

    return typeof window !== 'undefined' && typeof window.matchMedia === 'function'
        && window.matchMedia( NARROW ).matches;
}

/** The menu buttons that control this nav. */
function toggles( rail ) {

    return document.querySelectorAll( '.owa_reportNavToggle[aria-controls="' + rail.id + '"]' );
}

function setExpanded( rail, expanded ) {

    toggles( rail ).forEach( function ( toggle ) {
        toggle.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
        toggle.setAttribute( 'aria-label', expanded ? 'Hide the report menu' : 'Show the report menu' );
    } );
}

/** Slide the nav in. */
export function open( rail ) {

    rail.classList.add( OPEN );
    setExpanded( rail, true );
}

/** Slide it away again. */
export function close( rail ) {

    rail.classList.remove( OPEN );
    setExpanded( rail, false );
}

/**
 * What a click does, given where it landed. Returns true when the click was
 * consumed and must not go on to its own target.
 *
 * @param {Element} target
 * @param {HTMLElement} rail
 * @return {boolean}
 */
export function handleClick( target, rail ) {

    const isOpen = rail.classList.contains( OPEN );

    if ( target.closest( '.owa_reportNavClose' ) ) {
        close( rail );
        return true;
    }

    if ( target.closest( '.owa_reportNavToggle' ) ) {

        if ( isOpen ) {
            close( rail );
        } else {
            open( rail );
        }

        return true;
    }

    if ( ! isOpen ) {
        return false;
    }

    // A tap beside the open nav closes it, and does nothing else.
    if ( ! rail.contains( target ) ) {
        close( rail );
        return true;
    }

    // Choosing a report closes the nav as the page goes to it.
    if ( target.closest( 'a[href]' ) ) {
        close( rail );
    }

    return false;
}

function bind() {

    const rail = document.getElementById( 'owa_reportNavRail' );

    if ( ! rail ) {
        return;
    }

    // Capture, so a tap beside the open nav is stopped before whatever is
    // under it on the report acts on it.
    document.addEventListener( 'click', function ( event ) {

        if ( ! isNarrow() ) {
            return;
        }

        if ( handleClick( event.target, rail ) ) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true );

    document.addEventListener( 'keydown', function ( event ) {

        if ( event.key === 'Escape' && rail.classList.contains( OPEN ) ) {
            close( rail );
        }
    } );

    // Widened past the breakpoint while open: full width has no open state.
    if ( typeof window.matchMedia === 'function' ) {

        const mq = window.matchMedia( NARROW );

        if ( typeof mq.addEventListener === 'function' ) {
            mq.addEventListener( 'change', function () {
                if ( ! mq.matches ) {
                    close( rail );
                }
            } );
        }
    }
}

if ( typeof document !== 'undefined' ) {

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', bind );
    } else {
        bind();
    }
}
