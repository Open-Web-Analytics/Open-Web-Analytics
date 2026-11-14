import { OWA } from './owa.js';

/**
 * A metric's description, behind an (i) button beside its name.
 *
 * Shown on a tap or a click rather than on hover, so a touch screen shows it
 * too. The button is its own control: beside a grid heading's sort button,
 * not inside it, and inside a metric box without choosing that box.
 *
 * The description opens in a box placed under the button (above it when the
 * window has more room there), kept 8px inside the window. It is inserted
 * after the button, so a screen reader reads it next, and it is a polite
 * status region filled once it is in the page, so opening it is announced.
 *
 * One is open at a time. Escape, a press anywhere but the button or the box,
 * or the button again closes it.
 */

var current = null;
var seq = 0;

var GUTTER = 8;

/**
 * The description of a metric, from the metrics a result set relates
 * (ResultSetManager::getAllRelatedMetrics): '' when there is none.
 */
export function metricDescription( rs, name ) {

    var groups = ( rs && rs.relatedMetrics ) || {};

    for ( var group in groups ) {

        var list = groups[ group ] || [];

        for ( var i = 0; i < list.length; i++ ) {

            if ( list[ i ] && list[ i ].name === name ) {
                return list[ i ].description || '';
            }
        }
    }

    return '';
}

/**
 * Where the box goes: left and top (or bottom, when it opens upwards) in
 * window pixels, for a button at `r` and a box `width` by `height`.
 */
export function placeInfo( r, width, height, win ) {

    var vw    = win.innerWidth;
    var vh    = win.innerHeight;
    var left  = Math.max( GUTTER, Math.min( r.left, vw - width - GUTTER ) );
    var below = vh - r.bottom - GUTTER;
    var above = r.top - GUTTER;
    var up    = height > below && above > below;

    return {
        left: Math.round( left ),
        top: up ? null : Math.round( r.bottom + 4 ),
        bottom: up ? Math.round( vh - r.top + 4 ) : null
    };
}

export function closeMetricInfo( refocus ) {

    if ( ! current ) {
        return;
    }

    var c = current;

    current = null;
    c.off();

    if ( c.pop.parentNode ) {
        c.pop.parentNode.removeChild( c.pop );
    }

    c.button.setAttribute( 'aria-expanded', 'false' );
    c.button.removeAttribute( 'aria-controls' );

    if ( refocus ) {
        c.button.focus();
    }
}

export function openMetricInfo( button ) {

    if ( current && current.button === button ) {
        closeMetricInfo();
        return null;
    }

    closeMetricInfo();

    var pop = document.createElement( 'div' );

    pop.id = 'owa_metricInfo-' + ( ++seq );
    pop.className = 'owa_metricInfoPop';
    pop.setAttribute( 'role', 'status' );
    pop.setAttribute( 'data-ui', 'metric-info-text' );

    button.parentNode.insertBefore( pop, button.nextSibling );
    button.setAttribute( 'aria-expanded', 'true' );
    button.setAttribute( 'aria-controls', pop.id );

    var place = function () {

        var p = placeInfo( button.getBoundingClientRect(), pop.offsetWidth, pop.offsetHeight, window );

        pop.style.left   = p.left + 'px';
        pop.style.top    = p.top === null ? 'auto' : p.top + 'px';
        pop.style.bottom = p.bottom === null ? 'auto' : p.bottom + 'px';
    };

    var outside = function ( e ) {

        if ( ! pop.contains( e.target ) && ! button.contains( e.target ) ) {
            closeMetricInfo();
        }
    };

    var keys = function ( e ) {

        if ( e.key === 'Escape' ) {
            e.preventDefault();
            closeMetricInfo( true );
        }
    };

    // A live region announces what changes in it, so it is in the page,
    // empty, before the text arrives.
    var fill = setTimeout( function () {
        pop.textContent = button.getAttribute( 'data-description' ) || '';
        place();
    }, 50 );

    place();

    window.addEventListener( 'scroll', place, true );
    window.addEventListener( 'resize', place );
    document.addEventListener( 'pointerdown', outside, true );
    document.addEventListener( 'keydown', keys );

    current = {
        button: button,
        pop: pop,
        off: function () {
            clearTimeout( fill );
            window.removeEventListener( 'scroll', place, true );
            window.removeEventListener( 'resize', place );
            document.removeEventListener( 'pointerdown', outside, true );
            document.removeEventListener( 'keydown', keys );
        }
    };

    return pop;
}

/**
 * The (i) button for a metric called `label` with `description`.
 *
 * @return HTMLButtonElement
 */
export function metricInfoButton( label, description ) {

    var button = document.createElement( 'button' );

    button.type = 'button';
    button.className = 'owa_metricInfoToggle';
    button.setAttribute( 'aria-label', OWA.util.sprintf( OWA.l( 'About %s' ), label ) );
    button.setAttribute( 'aria-expanded', 'false' );
    button.setAttribute( 'data-ui', 'metric-info' );
    button.setAttribute( 'data-description', description );
    button.innerHTML = '<i class="fas fa-info-circle" aria-hidden="true"></i>';

    button.addEventListener( 'click', function () { openMetricInfo( button ); } );

    return button;
}

OWA.metricInfo = {
    button: metricInfoButton,
    open: openMetricInfo,
    close: closeMetricInfo,
    description: metricDescription,
    place: placeInfo
};
