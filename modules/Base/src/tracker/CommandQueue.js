
import { OWA_instance as OWA } from '../common/owa.js';
import { Util } from '../common/Util.js';
import { OWATracker } from './Tracker.js';

class CommandQueue {

    /**
     * Split a queued command into the object it targets and the method to call.
     *
     * 'trackPageView'        -> OWATracker.trackPageView   (the default tracker)
     * 'siteB.trackPageView'  -> siteB.trackPageView        (a named one)
     */
    static parseCmd( cmd ) {

        if ( ! cmd || ! cmd[0] ) {
            return null;
        }

        var name = String( cmd[0] );

        // includes(), not the old strpos() port: that returned an INDEX, and
        // index 0 is falsy, so a name beginning with '.' answered "no dot".
        if ( ! name.includes( '.' ) ) {
            return { object: 'OWATracker', method: name };
        }

        var parts = name.split( '.' );

        return { object: parts[0], method: parts[1] };
    }


	constructor() {
    	
    	OWA.debug('Command Queue object created');
		this.asyncCmds = [];
		this.is_paused = false;
	}

    push(cmd, callback) {

        //alert(func[0]);
        var args = Array.prototype.slice.call(cmd, 1);
        //alert(args);

        var obj_name = '';
        var method = '';
        var check = String( cmd[0] ).includes( '.' );

        if ( ! check ) {
            obj_name = 'OWATracker';
            method = cmd[0];
        } else {
            var parts = cmd[0].split( '.' );
            obj_name = parts[0];
            method = parts[1];
        }

        OWA.debug('cmd queue object name %s', obj_name);
        OWA.debug('cmd queue object method name %s', method);

        if ( method === "pause-owa" ) {

            this.pause();
        }
	    
	if ( method === "unpause-owa") {

            this.unpause();
        }

        /*
         * ONE COMMAND MUST NOT TAKE THE REST OF THE QUEUE DOWN WITH IT.
         *
         * process() drains the queue by chaining through callbacks, and this
         * method is where a command is actually applied. An exception raised
         * anywhere below therefore escapes process() and abandons every command
         * still queued behind it. The snippet pushes trackPageView LAST, so
         * whatever went wrong, the visible result is the same: the page is not
         * tracked at all, and nothing anywhere says why.
         *
         * The unknown-method guard further down handles one cause of that. This
         * handles the rest, and they are not exotic: constructing the tracker
         * can throw on a hostile document.domain, a known command can throw on
         * an argument shape it did not expect, and a browser extension can
         * replace a global out from under us. None of those are reasons to stop
         * recording the page view that has already happened.
         *
         * Swallowed, not rethrown, and the callback below still runs so the
         * drain continues. The command that failed is lost, which is the
         * smallest thing that can be lost here.
         */
        try {

        // check to see if the command queue has been paused
        // used to stop tracking
        if ( ! this.is_paused && method !== "unpause-owa") {

            // is OWATracker created?
            if ( typeof window[obj_name] == "undefined" ) {

                // Built WITH its identity, not told afterwards. See
                // identityFor(): the constructor registers state stores and
                // fires cookieDomainEstablished, so a tracker that learns who
                // it is after construction has already made every decision
                // that depended on knowing.
                var identity = this.identityFor( obj_name, cmd );

                OWA.debug('making global object named: %s', obj_name);
                window[obj_name] = new OWATracker( identity );
            }

            // 'config' is a queue-level command, not a tracker method: it
            // exists to CREATE a tracker for a site, the way gtag('config', ID)
            // does. By the time we get here the tracker above already has the
            // site id, so there is nothing further to apply.
            if ( method === 'config' ) {

                if ( args.length && window[obj_name].getSiteId() !== args[0] ) {
                    window[obj_name].setSiteId( args[0] );
                }

            } else if ( typeof window[obj_name][method] === 'function' ) {

                window[obj_name][method].apply(window[obj_name], args);

            } else {

                /*
                 * A command this tracker does not implement is SKIPPED, not
                 * applied to undefined.
                 *
                 * This used to be `window[obj_name][method].apply(...)`
                 * unguarded, so an unknown name threw a TypeError -- and the
                 * throw escaped process(), so every command still queued behind
                 * it was abandoned. In practice that means trackPageView, which
                 * is pushed last: an unrecognised command anywhere in the
                 * snippet silently stopped tracking the page.
                 *
                 * That is not hypothetical, and it is not only about typos. The
                 * snippet is generated server-side and changes the instant PHP
                 * is upgraded; the tracker is a long-cached static file that a
                 * returning visitor may hold for days. Every snippet command
                 * added from now on therefore reaches some browsers running a
                 * tracker too old to have it -- which is exactly the window in
                 * which OWA would have stopped recording those visitors, with
                 * no error anywhere the site owner could see it.
                 *
                 * Skipping is the only behaviour that degrades sensibly: the
                 * new instruction is ignored by a tracker that cannot honour it
                 * anyway, and everything it already understood still runs.
                 */
                OWA.debug( 'Skipping unknown command %s.%s', obj_name, method );
            }
        }

        } catch ( e ) {

            // debug(), not an error report: this runs on someone else's page,
            // and a tracker that starts writing to their console over its own
            // failure to apply one command has made their problem worse.
            OWA.debug( 'Command %s.%s threw, skipping it: %s',
                obj_name, method, ( e && e.message ) ? e.message : e );
        }

        if ( callback && ( typeof callback == 'function') ) {
            callback();
        }

    }

    loadCmds( cmds ) {

        this.asyncCmds = cmds;
    }

    /**
     * The site id a tracker is going to be given, found BEFORE it is built.
     *
     * A tracker is constructed on the first command naming it, and the site id
     * arrives on whichever command carries it -- which is not reliably the
     * first. OWA's own snippet puts setDebug and setApiEndpoint ahead of
     * setSiteId whenever the install is in development mode or has a custom API
     * endpoint, and those snippets are already pasted into sites that will
     * never be regenerated.
     *
     * So this does not trust ordering. loadCmds() hands the queue the whole
     * array before process() starts shifting it, so the identity command can
     * simply be looked up. That is what lets the site id reach the constructor
     * without changing a single existing snippet.
     *
     * Commands pushed asynchronously AFTER processing begins cannot be seen
     * this way, but identity is never one of them in practice -- it is in the
     * static block the tag writes.
     */
    argFor( obj_name, methods, current ) {

        var self = this;

        var valueOf = function ( cmd ) {

            var parsed = CommandQueue.parseCmd( cmd );

            if ( ! parsed || parsed.object !== obj_name ) {
                return '';
            }

            if ( methods.indexOf( parsed.method ) === -1 ) {
                return '';
            }

            return cmd[1] || '';
        };

        // the command being applied right now counts too -- it has already been
        // shifted off the pending array
        var found = valueOf( current );

        if ( found ) {
            return found;
        }

        for ( var i = 0; i < self.asyncCmds.length; i++ ) {

            found = valueOf( self.asyncCmds[i] );

            if ( found ) {
                return found;
            }
        }

        return '';
    }

    /**
     * The value a queued setOption() will set, found BEFORE the tracker is built.
     *
     * argFor() cannot answer this: it returns cmd[1], which for setOption is the
     * option NAME. The value is cmd[2].
     *
     * Returns undefined when nothing sets it, which is distinct from a command
     * that sets it to false -- the caller has to be able to tell those apart, so
     * this cannot use the '' sentinel argFor() uses.
     */
    optionFor( obj_name, option_name, current ) {

        var valueOf = function ( cmd ) {

            var parsed = CommandQueue.parseCmd( cmd );

            if ( ! parsed || parsed.object !== obj_name || parsed.method !== 'setOption' ) {
                return undefined;
            }

            if ( cmd[1] !== option_name ) {
                return undefined;
            }

            return cmd[2];
        };

        var found = valueOf( current );

        if ( found !== undefined ) {
            return found;
        }

        for ( var i = 0; i < this.asyncCmds.length; i++ ) {

            found = valueOf( this.asyncCmds[i] );

            if ( found !== undefined ) {
                return found;
            }
        }

        return undefined;
    }

    /**
     * Everything a tracker must know before its constructor body runs.
     *
     * Two things qualify, and both used to arrive too late:
     *
     *   site_id       the five registerStateStore() calls, and any per-site
     *                 store naming, happen in the constructor.
     *   cookie_domain every store stamps a hash of it (cdh) at WRITE time, and
     *                 readPersistedStore() refuses a store whose hash does not
     *                 match. Left to the lazy path in trackEvent(), anything
     *                 written before the first event -- a custom var set the
     *                 way the docs describe -- is stamped against a domain that
     *                 is not yet the real one, and is silently unreadable on
     *                 the next page load.
     *
     * An explicit setCookieDomain() anywhere in the queue wins; otherwise the
     * constructor is told nothing and keeps its existing lazy behaviour.
     */
    identityFor( obj_name, current ) {

        var identity = { globalObjectName: obj_name };

        var site_id = this.argFor( obj_name, ['setSiteId', 'config'], current );

        if ( site_id ) {
            identity.site_id = site_id;
        }

        var domain = this.argFor( obj_name, ['setCookieDomain'], current );

        if ( domain ) {
            identity.cookie_domain_declared = domain;
        }

        /*
         * Cookie configuration, for the same reason the site id comes forward.
         *
         * Storage migrations are pegged to 'cookieDomainEstablished', which the
         * constructor fires, and they WRITE cookies -- collapsing the legacy 'b'
         * store into the session store, and moving a session store to its
         * per-site name. Any command, setOption included, arrives after
         * construction has finished. So a returning visitor holding a legacy
         * cookie would have the migrated one written at the shipped 364 days
         * even when the snippet asks for 90, which is precisely the upgrade path
         * this configuration exists to serve.
         *
         * Carried as options, so the constructor's existing merge puts them in
         * this.options before the migrations run. A tracker built by hand still
         * gets the shipped defaults.
         */
        var expirations = this.optionFor( obj_name, 'stateStoreExpirations', current );

        if ( expirations !== undefined ) {
            identity.stateStoreExpirations = expirations;
        }

        var persistence = this.optionFor( obj_name, 'cookiePersistence', current );

        if ( persistence !== undefined ) {
            identity.cookiePersistence = persistence;
        }

        return identity;
    }

    process() {

        var that = this;
        var callback = function () {
            // when the handler says it's finished (i.e. runs the callback)
            // We check for more tasks in the queue and if there are any we run again
            if (that.asyncCmds.length > 0) {
                that.process();
             }
        }
        
        // give the first item in the queue & the callback to the handler
        if (this.asyncCmds.length > 0) {
	        
        	this.push(this.asyncCmds.shift(), callback);
        }
     
        /*
        for (var i=0; i < this.asyncCmds.length;i++) {
            this.push(this.asyncCmds[i]);
        }
        */
    }

    pause() {

        this.is_paused = true;
        OWA.debug('Pausing Command Queue');
    }

    unpause() {

        this.is_paused = false;
        OWA.debug('Un-pausing Command Queue');
    }
}


export { CommandQueue };