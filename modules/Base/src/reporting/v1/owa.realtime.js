// The realtime screen: a site's last thirty minutes, from raw (Classes\Realtime).
//
// Plain DOM, and every value from the server is written with textContent. The
// map is OWA.worldMap (owa.worldmap.js).
// Page titles, paths and campaign names are what visitors' browsers sent, so
// none of it may reach innerHTML.
import { OWA } from './owa.js';
import './owa.worldmap.js';

OWA.realtime = function ( root, options ) {

    this.root     = root;
    this.apiUrl   = options.apiUrl;
    this.timezone = options.timezone || undefined;
    this.interval = options.interval || 15000;
    this.timer    = null;
    this.visitor  = null;
    this.last     = null;
    this.map      = new OWA.worldMap( root.querySelector( '.owa_realtimeMap' ), { url: options.mapUrl } );
};

OWA.realtime.prototype = {

    start: function () {

        var self = this;

        this.load();

        // Again once the map is in: the country table takes its names from it.
        this.map.load().then( function () {

            if ( self.last && ! self.visitor ) {

                self.render( self.last );
            }
        } );

        this.timer = setInterval( function () {

            // Hidden tabs do not poll: nobody is looking, and every viewer is
            // a query per card on the site's newest rows.
            if ( document.visibilityState === 'visible' ) {

                self.load();
            }
        }, this.interval );

        document.addEventListener( 'visibilitychange', function () {

            if ( document.visibilityState === 'visible' && self.timer ) {

                self.load();
            }
        } );

        this.root.addEventListener( 'click', function ( e ) {

            var row = e.target.closest( '[data-visitor]' );

            if ( row ) {

                self.showVisitor( row.getAttribute( 'data-visitor' ) );
            }

            if ( e.target.closest( '.owa_realtimeBack' ) ) {

                self.visitor = null;
                self.render( self.last );
            }
        } );
    },

    stop: function ( message ) {

        clearInterval( this.timer );
        this.timer = null;
        this.status( message, true );
    },

    load: function () {

        var self = this;

        return fetch( this.apiUrl, { credentials: 'same-origin' } )
            .then( function ( r ) {

                // The page's nonce is good for two to four hours. Past that
                // every refresh is refused, so stop and say so rather than
                // leave a screen that looks live and is not.
                if ( r.status === 401 || r.status === 403 ) {

                    self.stop( 'This view has been open a long time. Reload the page to continue.' );

                    return null;
                }

                if ( ! r.ok ) {

                    throw new Error( 'HTTP ' + r.status );
                }

                return r.json();
            } )
            .then( function ( json ) {

                if ( ! json ) {

                    return;
                }

                self.last = json.data || json;
                self.status( '' );

                if ( ! self.visitor ) {

                    self.render( self.last );
                }
            } )
            .catch( function () {

                self.status( 'Could not refresh. Trying again shortly.' );
            } );
    },

    // ---- rendering ---------------------------------------------------------

    render: function ( d ) {

        if ( ! d ) {

            return;
        }

        this.show( 'summary' );

        this.text( '.owa_realtimeUsers30', this.number( d.activeUsers.last30 ) );
        this.text( '.owa_realtimeUsers5', this.number( d.activeUsers.last5 ) );

        this.bars( d.perMinute || [] );
        this.map.shade( d.countries || [] );

        this.table( 'pages', d.pages, function ( r ) {

            return [ r.title || r.path, r.views, r.users ];
        }, function ( r ) { return r.path; } );

        // No captured acquisition is (unknown), as in a report; an empty
        // medium or campaign is (not set).
        this.table( 'sources', d.sources, function ( r ) {

            return [ r.source === null ? '(unknown)' : r.source, r.medium, r.campaign, r.users ];
        } );

        this.table( 'events', d.events, function ( r ) { return [ r.name, r.count ]; } );

        this.text( '.owa_realtimeGoalTotal', this.number( d.goals ? d.goals.total : 0 ) );
        this.table( 'goals', d.goals ? d.goals.byGoal : [], function ( r ) { return [ r.name, r.count ]; } );

        // The map's name for the code: raw holds the name as the geo lookup
        // wrote it, lowercase.
        var map = this.map;

        this.table( 'countries', ( d.countries || [] ).slice( 0, 10 ), function ( r ) {

            return [ map.nameOf( r.code ) || r.name || r.code, r.users ];
        } );

        this.table( 'devices', d.devices, function ( r ) { return [ r.type, r.users ]; } );

        this.events( '.owa_realtimeRecent', d.recent || [], true );

        var unknown = d.activeUsers.last30 - ( d.located || 0 );
        var note    = '';

        if ( d.activeUsers.last30 > 0 && ! d.located ) {

            note = 'No locations: they need the MaxMind GeoIP module.';

        } else if ( unknown > 0 ) {

            note = unknown + ' of ' + d.activeUsers.last30 + ' users have no known location.';
        }

        this.text( '.owa_realtimeMapNote', note );

        this.root.querySelector( '.owa_realtimeQueued' ).hidden = ! d.queued;
    },

    /** Users per minute, oldest on the left; the last bar is this minute so far. */
    bars: function ( perMinute ) {

        var slot = this.root.querySelector( '.owa_realtimeBars' );
        var max  = Math.max.apply( null, perMinute.concat( [ 1 ] ) );

        slot.textContent = '';

        perMinute.forEach( function ( n, i ) {

            var bar  = document.createElement( 'span' );
            var ago  = perMinute.length - 1 - i;

            bar.className    = 'owa_realtimeBar';
            bar.style.height = Math.round( 100 * n / max ) + '%';
            bar.title        = n + ( n === 1 ? ' user, ' : ' users, ' )
                + ( ago === 0 ? 'this minute' : ago + ( ago === 1 ? ' minute ago' : ' minutes ago' ) );

            slot.appendChild( bar );
        } );
    },

    table: function ( name, rows, cells, tip ) {

        var body = this.root.querySelector( '.owa_realtimeTable[data-card="' + name + '"] tbody' );
        var self = this;

        if ( ! body ) {

            return;
        }

        body.textContent = '';

        if ( ! rows || ! rows.length ) {

            var empty = document.createElement( 'tr' );
            var cell  = document.createElement( 'td' );

            cell.colSpan     = body.parentNode.querySelectorAll( 'thead th' ).length || 1;
            cell.className   = 'owa_realtimeEmpty';
            cell.textContent = 'None in the last 30 minutes';
            empty.appendChild( cell );
            body.appendChild( empty );

            return;
        }

        rows.forEach( function ( r ) {

            var tr = document.createElement( 'tr' );

            cells( r ).forEach( function ( v ) {

                var td = document.createElement( 'td' );

                if ( typeof v === 'number' ) {

                    td.className   = 'n';
                    td.textContent = self.number( v );

                } else {

                    // NULL is absent, and is labelled at the edge (PLAN 2.11).
                    td.textContent = v === null || v === undefined || v === '' ? '(not set)' : String( v );
                }

                tr.appendChild( td );
            } );

            if ( tip ) {

                tr.title = String( tip( r ) || '' );
            }

            body.appendChild( tr );
        } );
    },

    events: function ( selector, rows, linkVisitors ) {

        var list = this.root.querySelector( selector );
        var self = this;

        list.textContent = '';

        if ( ! rows.length ) {

            var li = document.createElement( 'li' );

            li.className   = 'owa_realtimeEmpty';
            li.textContent = 'No events in the last 30 minutes';
            list.appendChild( li );

            return;
        }

        rows.forEach( function ( e ) {

            var li    = document.createElement( 'li' );
            var time  = document.createElement( 'time' );
            var type  = document.createElement( 'span' );
            var page  = document.createElement( 'span' );
            var where = document.createElement( 'span' );

            time.textContent  = self.clock( e.ts );
            type.className    = 'owa_realtimeType';
            type.textContent  = e.type;
            page.className    = 'owa_realtimePage';
            page.textContent  = e.title || e.path || '';
            page.title        = e.path || '';
            where.className   = 'owa_realtimeWhere';
            where.textContent = [ e.city, e.country ].filter( Boolean ).join( ', ' );

            li.appendChild( time );
            li.appendChild( type );
            li.appendChild( page );
            li.appendChild( where );

            if ( linkVisitors ) {

                li.setAttribute( 'data-visitor', e.visitor );
                li.title = 'Show this visitor’s events';
            }

            list.appendChild( li );
        } );
    },

    /** One visitor's last thirty minutes: GA's user snapshot. */
    showVisitor: function ( id ) {

        var self = this;

        this.visitor = id;

        return fetch( this.apiUrl + '&visitorId=' + encodeURIComponent( id ), { credentials: 'same-origin' } )
            .then( function ( r ) { return r.ok ? r.json() : null; } )
            .then( function ( json ) {

                if ( ! json || self.visitor !== id ) {

                    return;
                }

                var d = json.data || json;

                self.show( 'visitor' );
                self.text( '.owa_realtimeVisitorId', 'Visitor ' + id );
                self.events( '.owa_realtimeVisitorEvents', d.events || [], false );
            } );
    },

    // ---- helpers -----------------------------------------------------------

    show: function ( panel ) {

        this.root.querySelector( '.owa_realtimeSummary' ).hidden = panel !== 'summary';
        this.root.querySelector( '.owa_realtimeVisitor' ).hidden = panel !== 'visitor';
    },

    status: function ( message, sticky ) {

        var el = this.root.querySelector( '.owa_realtimeStatus' );

        el.textContent = message || '';
        el.hidden      = ! message;
        el.classList.toggle( 'is-stopped', !! sticky );
    },

    text: function ( selector, value ) {

        var el = this.root.querySelector( selector );

        if ( el ) {

            el.textContent = value;
        }
    },

    number: function ( n ) {

        return Number( n || 0 ).toLocaleString();
    },

    /** A raw ts (microseconds) as a wall-clock time in the installation's zone. */
    clock: function ( usec ) {

        return new Date( Math.floor( usec / 1000 ) ).toLocaleTimeString( undefined, { timeZone: this.timezone } );
    }
};

export { OWA };
