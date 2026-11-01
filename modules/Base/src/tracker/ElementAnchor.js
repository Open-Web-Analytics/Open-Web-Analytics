/**
 * Where on an element a click landed, so the heatmap can put it back on that
 * element at whatever width the page is read at.
 *
 * A click's page coordinate places it only in a window the width of the one it
 * was recorded in. Measured on a centred layout, the same element sits 320px
 * further right at 1920 than at 1280, and a reflowing article moved elements up
 * to 858px down. So a click also records WHICH element it hit and where in that
 * element's box, and the heatmap draws it at that spot of the same element on
 * the reader's page.
 *
 * THE ELEMENT is named by its path from the nearest ancestor with an id of its
 * own, one tag and sibling position per step: `#main>div:2>a:1`. Starting from
 * an id rather than from the root keeps a template edit from renumbering every
 * click on the page -- only those under the part that changed.
 *
 * THE PATH TRAVELS AS A HASH. The tracker and the heatmap compute the same path
 * by the same function, and the heatmap finds an element by hashing every
 * element on the page and looking the stored number up. The path itself is
 * never stored or matched as a CSS selector, so nothing in it needs escaping and
 * the server holds eight bytes instead of a hundred characters.
 *
 * THE POSITION is a fraction of the box, in thousandths, so a link 200px wide on
 * a desktop and 350px on a phone keeps the click at the same spot on it.
 */

/** Thousandths of the element's box. Fits a SMALLINT. */
const SCALE = 1000;

/**
 * Whether an id is the page's own rather than one a script generated.
 *
 * A generated id changes on every load, so a click anchored to one could never
 * be found again. Rejected: a colon (React's useId, `:r1:`), and a run of eight
 * or more hex characters mixing letters and digits (random suffixes, UUIDs).
 * Numbered ids such as WordPress's `post-1234` keep: the number is the post's.
 *
 * @param {string} id
 * @return {boolean}
 */
function isStableId( id ) {

    if ( ! id || id.indexOf( ':' ) !== -1 ) {

        return false;
    }

    var runs = id.match( /[0-9a-f]{8,}/gi ) || [];

    for ( var i = 0; i < runs.length; i++ ) {

        if ( /[0-9]/.test( runs[ i ] ) && /[a-f]/i.test( runs[ i ] ) ) {

            return false;
        }
    }

    return true;
}

/**
 * The element's path: `#id` for one the page names by an id of its own, else
 * its parent's path and its own step.
 *
 * getAttribute('id'), not .id: on a form, an input named "id" shadows the
 * property. An id counts only for the FIRST element carrying it, which is the
 * one getElementById answers -- the heatmap applies the same rule, so a
 * duplicated id resolves the same way on both sides.
 *
 * @param {Element} el
 * @param {Map}     [memo]  element => path, for walking a whole document
 * @return {string}
 */
function path( el, memo ) {

    if ( ! el || el.nodeType !== 1 ) {

        return '';
    }

    if ( memo && memo.has( el ) ) {

        return memo.get( el );
    }

    var out;
    var id = el.getAttribute ? el.getAttribute( 'id' ) : null;

    if ( id && isStableId( id ) && el.ownerDocument.getElementById( id ) === el ) {

        out = '#' + id;

    } else {

        var n = 1;

        for ( var s = el.previousElementSibling; s; s = s.previousElementSibling ) {

            if ( s.tagName === el.tagName ) {

                n++;
            }
        }

        var step   = String( el.tagName ).toLowerCase() + ':' + n;
        var parent = path( el.parentElement, memo );

        out = parent ? parent + '>' + step : step;
    }

    if ( memo ) {

        memo.set( el, out );
    }

    return out;
}

/**
 * A 53-bit hash of a string, as a decimal string (cyrb53).
 *
 * 53 bits because that is what a JavaScript number holds exactly, and it fits a
 * signed BIGINT. A string because it travels on the wire and comes back from
 * the reports API as one, and the two have to compare equal.
 *
 * @param {string} str
 * @return {string}
 */
function hash( str ) {

    var h1 = 0xdeadbeef;
    var h2 = 0x41c6ce57;

    for ( var i = 0; i < str.length; i++ ) {

        var ch = str.charCodeAt( i );

        h1 = Math.imul( h1 ^ ch, 2654435761 );
        h2 = Math.imul( h2 ^ ch, 1597334677 );
    }

    h1  = Math.imul( h1 ^ ( h1 >>> 16 ), 2246822507 );
    h1 ^= Math.imul( h2 ^ ( h2 >>> 13 ), 3266489909 );
    h2  = Math.imul( h2 ^ ( h2 >>> 16 ), 2246822507 );
    h2 ^= Math.imul( h1 ^ ( h1 >>> 13 ), 3266489909 );

    return String( 4294967296 * ( 2097151 & h2 ) + ( h1 >>> 0 ) );
}

/**
 * The anchor of a click on an element, or null when it has none.
 *
 * None when the element has no box (nothing to place against) or the point is
 * outside it -- a click raised from the keyboard reports 0,0 wherever the
 * element is.
 *
 * @param {Element} el
 * @param {number}  clientX  the click, in viewport pixels
 * @param {number}  clientY
 * @return {{anchor: string, x: number, y: number}|null}
 */
function of( el, clientX, clientY ) {

    if ( ! el || typeof el.getBoundingClientRect !== 'function' ) {

        return null;
    }

    var r = el.getBoundingClientRect();

    if ( ! ( r.width > 0 && r.height > 0 ) ) {

        return null;
    }

    var fx = ( clientX - r.left ) / r.width;
    var fy = ( clientY - r.top ) / r.height;

    if ( ! ( fx >= 0 && fx <= 1 && fy >= 0 && fy <= 1 ) ) {

        return null;
    }

    return {
        anchor: hash( path( el ) ),
        x: Math.round( fx * SCALE ),
        y: Math.round( fy * SCALE )
    };
}

/**
 * Every element of a document by its anchor.
 *
 * @param {Document} doc
 * @return {Map} anchor => element
 */
function index( doc ) {

    var memo = new Map();
    var out  = new Map();
    var all  = doc.getElementsByTagName( '*' );

    for ( var i = 0; i < all.length; i++ ) {

        out.set( hash( path( all[ i ], memo ) ), all[ i ] );
    }

    return out;
}

/**
 * Where an anchored point falls on this page, in page pixels, or null when the
 * element has no box here (hidden at this width, say).
 *
 * @param {Element} el
 * @param {number}  x  thousandths of the box
 * @param {number}  y
 * @param {Window}  win
 * @return {{x: number, y: number}|null}
 */
function place( el, x, y, win ) {

    var r = el.getBoundingClientRect();

    if ( ! ( r.width > 0 && r.height > 0 ) ) {

        return null;
    }

    return {
        x: Math.round( r.left + ( win.pageXOffset || 0 ) + x / SCALE * r.width ),
        y: Math.round( r.top + ( win.pageYOffset || 0 ) + y / SCALE * r.height )
    };
}

export const ElementAnchor = { SCALE, isStableId, path, hash, of, index, place };
