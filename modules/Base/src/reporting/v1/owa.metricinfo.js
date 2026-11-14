import { OWA } from './owa.js';

/**
 * A metric's description, opened from the metric's name.
 *
 * The name is a button with a dotted underline: a grid heading's label, a
 * metric box's label. Hovering it with a mouse shows the description, and
 * leaving the name and the description hides it again after a moment, so the
 * pointer can cross from one to the other. A click or a tap shows it and
 * keeps it open until Escape, a press anywhere else, or the name again.
 *
 * The description opens in a box placed under the name (above it when the
 * window has more room there), kept 8px inside the window. It is inserted
 * after the name, so a screen reader reads it next, and it is a polite status
 * region filled once it is in the page, so opening it is announced.
 *
 * One is open at a time.
 */

var current = null;
var seq = 0;
var closing = null;

var GUTTER = 8;

/** How long a pointer rests on a name before its description opens. */
export var HOVER_OPEN = 120;

/** How long after the pointer leaves the name and the description it closes. */
export var HOVER_CLOSE = 200;

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
 * window pixels, for a name at `r` and a box `width` by `height`.
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

function cancelClose() {

    if ( closing ) {
        clearTimeout( closing );
        closing = null;
    }
}

/** A hover-opened description closes a moment after the pointer leaves. */
function closeSoon( trigger ) {

    cancelClose();

    if ( current && current.trigger === trigger && ! current.pinned ) {
        closing = setTimeout( function () {
            closing = null;
            closeMetricInfo();
        }, HOVER_CLOSE );
    }
}

export function isMetricInfoOpen( trigger ) {

    return !! current && ( ! trigger || current.trigger === trigger );
}

export function closeMetricInfo( refocus ) {

    cancelClose();

    if ( ! current ) {
        return;
    }

    var c = current;

    current = null;
    c.off();

    if ( c.pop.parentNode ) {
        c.pop.parentNode.removeChild( c.pop );
    }

    c.trigger.setAttribute( 'aria-expanded', 'false' );
    c.trigger.removeAttribute( 'aria-controls' );

    if ( c.options.onClose ) {
        c.options.onClose();
    }

    if ( refocus ) {
        c.trigger.focus();
    }
}

/**
 * Open `trigger`'s description. Pinned stays open when the pointer leaves;
 * not pinned is a hover.
 */
export function openMetricInfo( trigger, pinned, options ) {

    options = options || {};

    closeMetricInfo();

    var pop = document.createElement( 'div' );

    pop.id = 'owa_metricInfo-' + ( ++seq );
    pop.className = 'owa_metricInfoPop';
    pop.setAttribute( 'role', 'status' );
    pop.setAttribute( 'data-ui', 'metric-description' );

    trigger.parentNode.insertBefore( pop, trigger.nextSibling );
    trigger.setAttribute( 'aria-expanded', 'true' );
    trigger.setAttribute( 'aria-controls', pop.id );

    var place = function () {

        var p = placeInfo( trigger.getBoundingClientRect(), pop.offsetWidth, pop.offsetHeight, window );

        pop.style.left   = p.left + 'px';
        pop.style.top    = p.top === null ? 'auto' : p.top + 'px';
        pop.style.bottom = p.bottom === null ? 'auto' : p.bottom + 'px';
    };

    // A press inside the trigger's own area (a grid heading, with its sort
    // arrow) is not a press elsewhere.
    var outside = function ( e ) {

        var within = ( options.within && options.within() ) || trigger;

        if ( ! pop.contains( e.target ) && ! within.contains( e.target ) ) {
            closeMetricInfo();
        }
    };

    var keys = function ( e ) {

        if ( e.key === 'Escape' ) {
            e.preventDefault();
            closeMetricInfo( true );
        }
    };

    var enter = function ( e ) { if ( e.pointerType === 'mouse' ) { cancelClose(); } };
    var leave = function ( e ) { if ( e.pointerType === 'mouse' ) { closeSoon( trigger ); } };

    // A live region announces what changes in it, so it is in the page,
    // empty, before the text arrives.
    var fill = setTimeout( function () {
        pop.textContent = trigger.getAttribute( 'data-description' ) || '';
        place();
    }, 50 );

    place();

    pop.addEventListener( 'pointerenter', enter );
    pop.addEventListener( 'pointerleave', leave );
    window.addEventListener( 'scroll', place, true );
    window.addEventListener( 'resize', place );
    document.addEventListener( 'pointerdown', outside, true );
    document.addEventListener( 'keydown', keys );

    current = {
        trigger: trigger,
        pop: pop,
        pinned: !! pinned,
        options: options,
        off: function () {
            clearTimeout( fill );
            window.removeEventListener( 'scroll', place, true );
            window.removeEventListener( 'resize', place );
            document.removeEventListener( 'pointerdown', outside, true );
            document.removeEventListener( 'keydown', keys );
        }
    };

    if ( options.onOpen ) {
        options.onOpen();
    }

    return pop;
}

/**
 * Make `trigger`, a button holding a metric's name, open `description`.
 *
 * @param HTMLElement trigger
 * @param string      description
 * @param object      options  within: a function returning the element a
 *                             press inside of keeps it open (default the
 *                             trigger); onOpen, onClose: called as it opens
 *                             and closes
 * @return HTMLElement the trigger
 */
export function describeMetric( trigger, description, options ) {

    var opening = null;

    trigger.classList.add( 'owa_metricDescribed' );
    trigger.setAttribute( 'aria-expanded', 'false' );
    trigger.setAttribute( 'data-description', description );

    trigger.addEventListener( 'pointerenter', function ( e ) {

        if ( e.pointerType !== 'mouse' ) {
            return;
        }

        cancelClose();

        if ( ! isMetricInfoOpen( trigger ) ) {
            opening = setTimeout( function () { openMetricInfo( trigger, false, options ); }, HOVER_OPEN );
        }
    } );

    trigger.addEventListener( 'pointerleave', function ( e ) {

        if ( e.pointerType !== 'mouse' ) {
            return;
        }

        clearTimeout( opening );
        closeSoon( trigger );
    } );

    trigger.addEventListener( 'click', function ( e ) {

        e.preventDefault();
        clearTimeout( opening );

        if ( ! isMetricInfoOpen( trigger ) ) {
            openMetricInfo( trigger, true, options );
        } else if ( current.pinned ) {
            closeMetricInfo();
        } else {
            current.pinned = true;
            cancelClose();
        }
    } );

    return trigger;
}

OWA.metricInfo = {
    describe: describeMetric,
    open: openMetricInfo,
    close: closeMetricInfo,
    isOpen: isMetricInfoOpen,
    description: metricDescription,
    place: placeInfo
};
