import { OWA } from './owa.format.js';

/**
 * Tabs: a row of tabs over the panels they show, one panel at a time.
 *
 * The ARIA tabs pattern: role tablist, tab and tabpanel, each tab naming its
 * panel (aria-controls) and each panel named by its tab. The selected tab is
 * aria-selected and the only one in the tab order; Left and Right move to the
 * tab beside it and select it, wrapping at the ends, and Home and End go to
 * the first and last. A panel not selected is hidden.
 *
 *   OWA.tabs( container, [ { id: 'tab_site_usage', label: 'Site Usage' } ], function ( id ) {}, { label: 'Metrics' } );
 *
 * Each id is a panel's element id inside `container`; the tab list goes first
 * in it. onSelect is called with the panel's id each time a tab is selected,
 * the first time included. The first tab is selected unless the URL's
 * fragment names another's panel.
 *
 * @param {Element} container
 * @param {Array} items     [ { id, label } ], in order
 * @param {Function} onSelect
 * @param {Object} options  { label }: the tab list's accessible name
 * @return {Object} { select( id ), selected() }
 */
export function tabs( container, items, onSelect, options ) {

    var opts    = options || {};
    var list    = document.createElement( 'div' );
    var buttons = [];
    var current = null;

    list.className = 'owa_tabs';
    list.setAttribute( 'role', 'tablist' );

    if ( opts.label ) {

        list.setAttribute( 'aria-label', opts.label );
    }

    items.forEach( function ( item ) {

        var panel = document.getElementById( item.id );
        var tab   = document.createElement( 'button' );

        tab.type = 'button';
        tab.className = 'owa_tab';
        tab.id = item.id + '-tab';
        tab.textContent = item.label;
        tab.setAttribute( 'role', 'tab' );
        tab.setAttribute( 'aria-controls', item.id );

        if ( panel ) {

            panel.setAttribute( 'role', 'tabpanel' );
            panel.setAttribute( 'aria-labelledby', tab.id );
        }

        tab.addEventListener( 'click', function () {

            select( item.id );
        } );

        buttons.push( tab );
        list.appendChild( tab );
    } );

    list.addEventListener( 'keydown', function ( e ) {

        var at = buttons.indexOf( e.target );

        if ( at === -1 ) {

            return;
        }

        var last = buttons.length - 1;
        var to   = { ArrowLeft: at === 0 ? last : at - 1, ArrowRight: at === last ? 0 : at + 1, Home: 0, End: last }[ e.key ];

        if ( to === undefined ) {

            return;
        }

        e.preventDefault();
        select( items[ to ].id );
        buttons[ to ].focus();
    } );

    function select( id ) {

        if ( id === current ) {

            return;
        }

        current = id;

        items.forEach( function ( item, i ) {

            var on    = item.id === id;
            var panel = document.getElementById( item.id );

            buttons[ i ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
            buttons[ i ].tabIndex = on ? 0 : -1;

            if ( panel ) {

                panel.hidden = ! on;
            }
        } );

        if ( onSelect ) {

            onSelect( id );
        }
    }

    container.insertBefore( list, container.firstChild );

    var named = ( window.location.hash || '' ).replace( /^#/, '' );
    var first = items.some( function ( item ) { return item.id === named; } ) ? named : ( items[ 0 ] || {} ).id;

    if ( first ) {

        select( first );
    }

    return {

        select: select,

        selected: function () {

            return current;
        }
    };
}

OWA.tabs = tabs;

export { OWA };
