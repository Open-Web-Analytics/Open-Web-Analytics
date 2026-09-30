// A world map of countries, shaded by a count per ISO 3166 alpha-2 code.
//
// The map is modules/Base/i/world-110m.svg (tests/tools/generate_world_map.php),
// one <g data-cc="XX"> per country, fetched from this install rather than a map
// service: a tile server would be sent the address of everyone looking at it.
//
// Country names come from the SVG and counts from the caller; both are written
// with textContent.
import { OWA } from './owa.js';

OWA.worldMap = function ( slot, options ) {

    this.slot  = slot;
    this.url   = options.url;
    this.label = options.label || 'users';
    this.svg   = null;
    this.rows  = null;
};

OWA.worldMap.prototype = {

    /** Fetch and insert the map once; resolves when it is on the page. */
    load: function () {

        var self = this;

        if ( ! this.slot || ! this.url ) {

            return Promise.resolve( null );
        }

        return fetch( this.url )
            .then( function ( r ) { return r.ok ? r.text() : ''; } )
            .then( function ( text ) {

                var svg = new DOMParser().parseFromString( text, 'image/svg+xml' ).querySelector( 'svg' );

                if ( ! svg ) {

                    return null;
                }

                svg = document.importNode( svg, true );
                svg.setAttribute( 'role', 'img' );
                svg.setAttribute( 'aria-label', 'Active ' + self.label + ' by country' );

                // The names, kept before shade() rewrites each title.
                svg.querySelectorAll( 'g[data-cc]' ).forEach( function ( g ) {

                    var title = g.querySelector( 'title' );

                    g.setAttribute( 'data-name', title ? title.textContent : g.getAttribute( 'data-cc' ) );
                } );

                self.slot.textContent = '';
                self.slot.appendChild( svg );
                self.svg = svg;

                if ( self.rows ) {

                    self.shade( self.rows );
                }

                return svg;
            } )
            .catch( function () { return null; } );
    },

    /** A country's name from the map, or '' before the map has loaded. */
    nameOf: function ( code ) {

        var g = this.svg ? this.svg.querySelector( 'g[data-cc="' + String( code ).replace( /[^A-Z]/g, '' ) + '"]' ) : null;

        return g ? g.getAttribute( 'data-name' ) : '';
    },

    /**
     * Shade by count: [ { code, users } ]. A country absent from the rows is
     * cleared, so a country nobody is in any more goes back to the base fill.
     */
    shade: function ( rows ) {

        var label = this.label;

        this.rows = rows || [];

        if ( ! this.svg ) {

            return;
        }

        var counts = {};
        var max    = 1;

        this.rows.forEach( function ( r ) {

            counts[ r.code ] = r.users;
            max = Math.max( max, r.users );
        } );

        this.svg.querySelectorAll( 'g[data-cc]' ).forEach( function ( g ) {

            var n     = counts[ g.getAttribute( 'data-cc' ) ] || 0;
            var title = g.querySelector( 'title' );

            g.classList.toggle( 'is-active', n > 0 );

            // A quarter to full, so a country with one user still shows.
            g.style.fillOpacity = n > 0 ? String( 0.25 + 0.75 * n / max ) : '';

            if ( title ) {

                title.textContent = g.getAttribute( 'data-name' )
                    + ( n > 0 ? ': ' + n + ' ' + ( n === 1 ? label.replace( /s$/, '' ) : label ) : '' );
            }
        } );
    }
};

export { OWA };
