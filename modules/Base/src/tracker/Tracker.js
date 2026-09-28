/**
 * Javascript Tracker Object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.openwebanalytics.com/licenses/ BSD-3 Clause
 */
 
import { OWA_instance as OWA } from '../common/owa.js';
import { Util } from '../common/Util.js';
import { OwaEvent } from './OwaEvent.js';
import { Uri } from './Uri.js';
 
class OWATracker  {
	
	constructor( options ) {
	
		this.id  =  '';
	    /*
	     * The site id, seeded HERE -- at the top of the constructor, before
	     * anything else runs.
	     *
	     * It used to arrive only via setSiteId(), which the command queue applies
	     * AFTER construction returns. That left the whole constructor body running
	     * with no identity: the five registerStateStore() calls below, the
	     * 'cookieDomainEstablished' action that storage migrations peg to, and
	     * 'tracker.init'. A store cannot be scoped to a site that is not known
	     * yet, and a migration cannot move a per-site cookie it cannot name.
	     *
	     * So the site id is an argument to the call that CREATES the tracker, and
	     * there is no window in which a tracker exists without knowing what it is.
	     *
	     * setSiteId() still works and is still supported; it means "reconfigure"
	     * now rather than "finally tell me who I am".
	     */
	    this.siteId  =  ( options && options.site_id ) ? options.site_id : '';
	    // ???
	    this.init =  0;
	    // flag to tell if client state has been set
	    this.stateInit =  false;
	    // properties that should be added to all events
	    this.globalEventProperties =  {};
	    // state sores that can be shared across sites
	    // Resolved per tracker, not a fixed list: each tracker contributes its
	    // OWN session store, so cross-domain linking carries site A's session
	    // and site B's session rather than one store both of them fought over.
	    this.sharableStateStores =  ['v', 's', 'b'],

	    /*
	     * Every property the tracker derives from state, on two axes.
	     *
	     *   scope      how long the value is VALID -- the set of events it must
	     *              be identical across. request / page / session / visitor.
	     *   permanent  whether the stored value ever CHANGES once written.
	     *
	     * Neither is "is it persisted". That is the store's business and
	     * registerStateStore() already answers it; declaring it here too would
	     * be two places to change and one to forget.
	     *
	     * The two axes constrain each other, and that is what makes them worth
	     * declaring: a value REWRITTEN more often than its scope cannot hold
	     * that scope. Visitor scope therefore requires permanence -- a value
	     * that has to be identical for the visitor's whole life cannot be
	     * rewritten with a different one. Only visitor_id and fsts qualify:
	     * everything else in the visitor store is recomputed.
	     *
	     * That constraint found two errors in the first version of this
	     * registry. dsfs was declared visitor-scoped but is rewritten on EVERY
	     * page load, so two page loads either side of a midnight disagree; it
	     * is page scope. nps was declared visitor-scoped but is rewritten at
	     * each session boundary; it is session scope. Both passed the invariant
	     * suite, because no scenario there spans a day or a session boundary.
	     *
	     * Scope cannot be read off the store either. last_req is page scope and
	     * lives in the session store, so it outlives its own validity -- safe
	     * only because it is re-captured each page load rather than read back.
	     *
	     * 'session' scope carries the contract that matters most: identical on
	     * every event sharing a session_id, a divergence being a regression.
	     * That is what rules out deriving days_since_prior_session as calendar
	     * days -- see TrackingEventHelpers::deriveDaysSincePriorSession().
	     *
	     * Enforced by tests/js/SessionScopedInvariant.test.js, which reads this
	     * rather than restating it.
	     */
	    this.trackingProperties = {

		    // Written once and never rewritten. The only two that are.
		    visitor_id:              { scope: 'visitor', permanent: true },
		    // The first-visit anchor itself. Sent raw: the server converts it
		    // to a date and does the day arithmetic, so no granularity is lost
		    // on the way and the day boundaries are the SERVER's -- the same
		    // ones every other date part on the row uses.
		    fsts:                    { scope: 'visitor', permanent: true },

		    // Rewritten at each session boundary.
		    session_id:              { scope: 'session', permanent: false },
		    prior_session_id:        { scope: 'session', permanent: false },
		    psts:                    { scope: 'session', permanent: false },
		    sts:                     { scope: 'session', permanent: false },
		    /*
		     * session_referer AND landing_url WERE HERE, and being in this map is
		     * what put them on every beacon of a session.
		     *
		     * Both were re-sent from session state so the server could attribute
		     * the session from any event. It never needed either: the tags are
		     * parsed from page_location on the session-starting beacon, which is
		     * the same URL landing_url held, and the session's referrer is the
		     * referer_host of its first row, which the pass already reads through
		     * the window it opens for the landing page. Session source is fixed by
		     * the session's first event, so no cookie needs to hold a URL.
		     *
		     * So this removes a URL and a referrer from the cookie and from every
		     * beacon of every session, for a read the pass performs anyway.
		     */
		    nps:                     { scope: 'session', permanent: false },
		    /*
		     * attribs WAS HERE, and being in this map is what put it on the
		     * beacon. It is the campaign attribution history, and the only
		     * thing that ever read it server-side was SessionHandlers --
		     * `latest_attributions` on the v1 session row -- which is not
		     * registered on v2. It reached no raw column and no cube pass.
		     *
		     * The whole client-side attribution stack went with it: the two
		     * models, the 'c' cookie, maxPriorCampaigns and
		     * trafficAttributionMode. The server resolves tags from page_location on the session-starting beacon.
		     */
		    // The site may set a different one, so it is not permanent.

		    // Rewritten every page load.
		    last_req:                { scope: 'page',    permanent: false },
		    page_url:                { scope: 'page',    permanent: false },
		    page_title:              { scope: 'page',    permanent: false },
		    page_type:               { scope: 'page',    permanent: false },
		    HTTP_REFERER:            { scope: 'page',    permanent: false },

		    // Consumed as applied, never stored.
		    is_new_session_start:    { scope: 'request', permanent: false },
		    is_new_visitor_created:  { scope: 'request', permanent: false }
	    },
	    // time when tracker is unloaded
	    this.endTime =  null;
	    // campaign state holder
	    // flag for new campaign status
	    // flag for new session status
	    this.isNewSessionFlag =  false;
	    /*
	     * Whether the event that CREATED this session is still waiting to be
	     * sent. Distinct from is_new_session, which is page-scoped and rides
	     * every event from the session's first page:
	     *
	     * True for exactly one beacon, which is what materialising a
	     * session_start event needs.
	     *
	     * THE PAGE-SCOPED TWIN IS GONE. is_new_session rode every event from
	     * the session's first page, and existed because v1's session listener
	     * decided create-vs-update on it -- badly, since "we are on the landing
	     * page" is true several times, so a second trackPageView() re-entered
	     * logSession() for a session that already existed. Splitting the two
	     * fixed that; removing v1's listener removes the need for a second flag
	     * at all.
	     *
	     * Nothing session-scoped is sent as a flag now. The tracker reports the
	     * EVENT -- this request started a session -- and the pass spreads what
	     * belongs to the whole session across its rows.
	     */
	    this.pendingSessionStart = false;
	    /*
	     * The visitor half of the same pair, and the same distinction:
	     *
	     * This REQUEST minted the visitor, true for one beacon, and first_visit
	     * is materialised from it on the server.
	     *
	     * Its session-scoped twin is gone too. The one thing that still wanted
	     * it -- writing the visitor's acquisition from any event of the first
	     * session -- reads prior_sessions == 0 instead, which rides every
	     * beacon and says the same thing.
	     */
	    this.pendingVisitorCreated = false;
	    // flag for whether or not traffic has been attributed
	    this.linkedStateSet =  false;
	    this.hashCookiesToDomain =  true;
	    	    
	    /**
	     * GET params parsed from URL
	     */
	    this.urlParams =  {};
	    /** The URL this.urlParams was parsed from, so a route change re-parses. */
	    this.urlParamsFrom = '';
	    /**
	     * Whether trackScroll() has bound its depth listener, so pushing the
	     * command twice does not report every threshold twice.
	     */
	    this.isScrollTrackingEnabled = false;
	    /**
	     * Whether trackForms() has bound its two listeners, for the same reason.
	     */
	    this.formTrackingEnabled = false;
	    /**
	     * Latest click event
	     */
	    this.click  =  '';
	    /**
	     * Latest Hover Event
	     */
	    this.hover  =  '';
	
	    this.last_event  =  '';
	    /**
	     * The last scroll depth REPORTED, as a percentage.
	     *
	     * 0 means nothing has been sent for this page. Reset on an SPA route
	     * change, because the new route is a new page and its depth starts
	     * again -- `last_scroll` used to be assigned by the scroll handler and
	     * never read by anything, which is how every scroll event queued.
	     */
	    this.last_scroll = 0;
	    /**
	     * ENGAGEMENT. Two values, and the distinction is the whole design.
	     *
	     * `engagementSince` is when the current visible stretch began, and it is
	     * null while the page is hidden -- time spent on a background tab is not
	     * time spent reading. `engagementReported` is how much of this page's
	     * time has already ridden out on a beacon.
	     *
	     * What goes on the wire is the DELTA between them, on every event, so
	     * losing a beacon costs one increment rather than one page. A cumulative
	     * total would make the last beacon of the page the only one that
	     * mattered, and that is the one most likely to be lost.
	     */
	    this.engagementSince = null;
	    this.engagementAccrued = 0;
	    this.engagementReported = 0;
	    /** Whether bindPageLifecycleEvents() has run; binding twice sends twice. */
	    this.pageLifecycleBound = false;
	    /**
	     * Whether the window has focus. Read from document.hasFocus() when the
	     * lifecycle is bound, then kept by the focus and blur events.
	     */
	    this.pageFocused = true;
	    /** Whether a page view has been sent; the first carries no engagement. */
	    this.pageViewSent = false;
	    /**
	     * Whether SPA route changes are being watched. Opt-in, and patching
	     * history.pushState twice would double every route change.
	     */
	    this.routeTrackingEnabled = false;
	    this.overlay =  '';
	
	
	
		//var OWA = owa;
		//OWA.event = event;
	
	    //this.setDebug(true);
	
	    // register cookies
	    //
	    // All JSON. Two of these used to be 'assoc', a bespoke
	    // key=>value|||key=>value string with no escaping of either separator --
	    // so a value containing '=>' or '|||' corrupted the whole store, and
	    // nothing detected it. JSON has one encoder, one decoder, and escapes.
	    //
	    // Safe to change under an existing installation because the loader
	    // SNIFFS the format of what it reads (Util.getCookieValueFormat: a
	    // leading '{' means JSON, anything else means assoc). A visitor holding
	    // an old assoc cookie has it parsed as assoc and rewritten as JSON on
	    // the next write. No migration, no flag day.
	    /*
	     * Which stores belong to ONE SITE rather than to the visitor.
	     *
	     * Declared here so the list sits beside the registrations it governs.
	     * storeName() turns a logical name into the physical one -- 's' becomes
	     * 's_<siteId>' -- which is what separates two trackers on a page: the
	     * map key in the state manager and the cookie name both follow from it,
	     * because the cookie is named ns + store name.
	     *
	     * 'v' stays global on purpose: it is the visitor, and two trackers SHOULD
	     * agree about who the visitor is. The visitor is shared and the session is
	     * per site. 'c' and 'd' stay global for now -- see the note in
	     * registerStore() about what that decision costs.
	     */
	    this.siteScopedStores = ['s'];

	    /*
	     * The numbers here are the SHIPPED defaults, not the last word: a store's
	     * lifetime is resolved at write time from this tracker's
	     * stateStoreExpirations option, falling back to what is registered here.
	     * `owner` and `logical` are what make that resolution possible -- the
	     * registry is keyed by physical name ('s_<siteId>'), while a snippet can
	     * only know the logical one.
	     */
	    OWA.registerStateStore('v', 364, '', 'json', { owner: this, logical: 'v' });
	    /*
	     * The 'c' campaign store is GONE. It held the attribution stack the
	     * client used to compute, which the server never read -- see
	     * setTrafficAttribution().
	     */

	    // The session store does not load its cookie on first touch, and does
	    // not write one until the session has been accepted for delivery.
	    //
	    // Both follow from one thing: holding a value back is what makes it
	    // distinguishable. A value in memory was set by THIS page load; one in
	    // the cookie was left by a previous session. Merge them on first touch
	    // -- which is what an eager load does -- and a new session can then
	    // neither keep the new values nor discard the old ones, because nothing
	    // tells them apart.
	    //
	    // persist:'session' also stops a half-session reaching disk. Writing a
	    // referer or a custom var while the sid is still withheld records state
	    // about a session whose identity was never recorded, and whatever reads
	    // it next attaches those values to a different session.
	    this.registerSiteScopedStores();
	    this.registerSessionStoreSiteMigration();

	    // 'b' held session-scoped custom variables, alongside 's' which is the
	    // session store -- two cookies for one concept. Session-scoped custom
	    // variables now live in 's'; see setCustomVar(). Still REGISTERED and
	    // still read, so a visitor mid-session keeps the variables they already
	    // have, but nothing is written to it any more and it is actively
	    // collapsed into 's' as soon as the cookie domain is known -- see
	    // StateManager.collapseLegacyStores().
	    //
	    // Reading it as a fallback was not enough on its own: because nothing
	    // cleared it at a session boundary, a variable left there by a session
	    // that had ended was still found by that read and put back on the wire.

	    // 'd' holds page-scoped custom variables. Memory only, for the life of
	    // the page -- the state manager never writes it to a cookie. Page scope
	    // used to be the ABSENCE of a case in setCustomVar(): the value fell
	    // through to a global event property and happened to work. Declaring it
	    // makes the three scopes symmetrical. The scoped custom-variable
	    // getters that read from it are gone; see setEventProperty().
	    OWA.registerStateStore('d', '', '', 'json', { persist: 'never' });
	
	    // Configuration options
	    this.options = OWA.applyFilters('tracker.default_options', {
	        logClicks: true,
	        logPage: true,
	        encodeProperties: false,
	        /*
	         * Whether the #fragment is part of a page's URL. It is not, by
	         * default -- see getCurrentUrl().
	         */
	        trackUrlFragments: false,
	        sessionLength: 1800,
	        /*
	         * Scroll depths, as percentages, that each raise ONE scroll event per
	         * page view.
	         *
	         * QUARTILES BY DEFAULT, ending at 90 rather than 100. A single 90% mark
	         * answered only "did they reach the end", which is not a distribution:
	         * nothing could say how far down people get. Every mark crossed is
	         * reported, so the count at each mark reads as "how many reached at
	         * least this far" and the four together are a depth funnel.
	         *
	         * 90, not 100, because a sticky footer, a rounding pixel or a trailing
	         * element keeps many pages from ever reading exactly 100.
	         *
	         * Up to four events per page view, and only on pages that scroll. A site
	         * that wants the old single mark sets [90].
	         */
	        scrollThresholds: [ 25, 50, 75, 90 ],
	        /*
	         * Extensions a click is treated as downloading. A list rather than
	         * "anything with a dot", which reads every /v1.2/ path and every
	         * .html as a download.
	         */
	        downloadExtensions: [
	            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt',
	            'rtf', 'zip', 'gz', 'tar', 'rar', '7z', 'dmg', 'pkg', 'exe',
	            'mp3', 'wav', 'mp4', 'mov', 'avi', 'wmv', 'epub', 'mobi',
	            'key', 'pps', 'mpeg', 'mpg', 'mid', 'midi', 'wma'
	        ],
	        /*
	         * Query parameters that carry a site-search term, tried in order.
	         *
	         * DEFAULTS NOW, and there were none. The reasoning was that no
	         * convention exists so a default would guess wrong -- and the cost of
	         * it was the whole feature: view_search_results could not fire on any
	         * install, because trackSiteSearch() had no caller either. An event
	         * nothing could raise, a property nothing could set, and a reserved
	         * name protecting neither.
	         *
	         * The guess is cheap and the miss is not. A page with ?q= that is not
	         * a search is one spurious event; no defaults is no site-search
	         * reporting anywhere, on any install. A site using ?kw= adds it.
	         */
	        siteSearchParams: [ 'q', 's', 'search', 'query', 'keyword' ],
	        cookie_domain: false,
	        /*
	         * How long each state store's cookie lives, by LOGICAL store name,
	         * and whether any of them may outlive the browser session.
	         *
	         * Options rather than methods of their own: setOption() already
	         * carries cookie_domain, campaignKeys, logger_endpoint, api_endpoint
	         * and baseUrl, and CommandQueue dispatches with .apply(), so
	         *
	         *   owa_cmds.push(['setOption', 'stateStoreExpirations', {v: 90}]);
	         *   owa_cmds.push(['setOption', 'cookiePersistence', false]);
	         *
	         * already reach here with no new public surface to deprecate later.
	         *
	         * Empty and true ship, so an install that says nothing keeps the
	         * lifetimes the stores are registered with. The values are READ WHERE
	         * THEY ARE USED -- see StateManager.getExpirationDays() -- and never
	         * copied into the store registry, because registerStore() replaces
	         * storeMeta[name] wholesale and this constructor re-registers the
	         * global 'v' and 'c' stores unconditionally. A second tracker on the
	         * page would otherwise reset the first one's configured lifetime with
	         * nothing to put it back.
	         */
	        stateStoreExpirations: {},
	        cookiePersistence: true,
	        /*
	         * campaignKeys WAS HERE, with six setters beside it.
	         *
	         * THE v2 TRACKER KNOWS NOTHING ABOUT ATTRIBUTION. It sends
	         * landing_url and session_referer; the server parses the tags out of
	         * the landing URL and decides source, medium and campaign. So the
	         * key list belongs where the parse is, and is now the `campaignKeys`
	         * setting -- scoped to a Property, so a site whose links use utm_*
	         * can say so without changing its links.
	         *
	         * Keeping the setters here was worse than not having them: the
	         * server built its own ns-prefixed list and never consulted these,
	         * so a site calling setCampaignSourceKey('utm_source') renamed a key
	         * nothing read and its campaigns silently stopped being attributed.
	         */
	        logger_endpoint: '',
	        api_endpoint: '',
	        maxCustomVars: 5,
	        getRequestCharacterLimit: 2000
	
	    });
	
	    // Endpoint URL of log service. needed for backwards compatability with old tags
	    var endpoint = window.owa_baseUrl || OWA.config.baseUrl ;
	    
	    if (endpoint) {
	        this.setEndpoint(endpoint);
	    } else {
	        OWA.debug('no global endpoint url found.');
	    }
	
	    this.endpoint = OWA.config.baseUrl;
	    // Active status of tracker
	    this.active = true;

	
	    if ( options ) {
	
	        for (var opt in options) {
	
	            this.options[opt] = options[opt];
	        }
	    }
	
	    /*
	     * ESTABLISH THE COOKIE DOMAIN NOW, before anything can write state.
	     *
	     * Every store stamps a hash of the cookie domain (cdh) into its value at
	     * WRITE time, and readPersistedStore() refuses a store whose hash does not
	     * match the current domain. This used to be resolved lazily, on the first
	     * tracked event -- so anything written before that, which includes the
	     * documented "set your custom vars, then track" flow, was stamped against
	     * a domain that was not yet the real one and was silently unreadable on
	     * the next page load.
	     *
	     * Moving it does not change the VALUE: setCookieDomain() with no argument
	     * resolves document.domain, exactly what the lazy path did later. Only the
	     * timing changes -- and with it the timing of 'cookieDomainEstablished',
	     * so storage migrations now run before anything reads rather than after
	     * something may already have written.
	     *
	     * trackEvent() still calls setCookieDomain() when it finds the domain
	     * unset. That is a safety net for a tracker built where no domain could
	     * be resolved, not the normal path any more.
	     */
	    if ( this.getOption('cookie_domain_declared') ) {

	    	// an explicit setCookieDomain found ahead of us in the command queue
	    	this.setCookieDomain( this.getOption('cookie_domain_declared') );

	    } else if ( this.getOption('cookie_domain_set') === true ) {

	    	// the caller declared it up front, so it is already established
	    	OWA.doAction('cookieDomainEstablished');

	    } else {

	    	this.setCookieDomain();
	    }

	    // private vars
	    this.ecommerce_transaction = '';
	    this.isClickTrackingEnabled = false;

	    // check to se if an overlay session is active
	    this.checkForOverlaySession();

	    /*
	     * THE ENGAGEMENT CLOCK STARTS HERE, with the page lifecycle bound.
	     *
	     * Not a snippet command: engagement time is what the engagement metrics
	     * are made of, not a feature a site switches on. Nothing called this
	     * before -- only the tests did, by hand -- so on a real page the clock
	     * never started, no user_engagement was sent and no event carried
	     * engagement_msec.
	     */
	    this.bindPageLifecycleEvents();

	    // Compiled-in plugins (see registerPlugin) set up on every tracker.
	    OWATracker.plugins().forEach( ( plugin ) => {

	        if ( typeof plugin.init === 'function' ) {

	            plugin.init( this );
	        }
	    } );

		OWA.doAction('tracker.init');
	}

    /**
     * Compiled into the tracker bundle from a module's source, registered here.
     *
     * A module contributes code to owa.tracker.js through its build manifest
     * (`contributes`), and that code registers itself with this rather than the
     * tracker naming it. A plugin is an object with:
     *
     *   name                a unique name
     *   reservedEventNames  event names it sends, which trackCustomEvent() then
     *                       refuses -- a site's event may not borrow one
     *   methods             { name: function } added to the tracker, so a
     *                       snippet command can call them. A method the tracker
     *                       already has is NOT replaced.
     *   init( tracker )     run for each tracker, at the end of its constructor
     *
     * Registering the same name twice keeps the first.
     *
     * @param {object} plugin
     * @return {boolean} whether it was registered
     */
    static registerPlugin( plugin ) {

        if ( ! plugin || typeof plugin.name !== 'string' || ! plugin.name ) {

            return false;
        }

        var registered = OWATracker.plugins();

        if ( registered.some( ( p ) => p.name === plugin.name ) ) {

            return false;
        }

        var methods = plugin.methods || {};

        Object.keys( methods ).forEach( ( name ) => {

            if ( typeof methods[ name ] === 'function' && ! ( name in OWATracker.prototype ) ) {

                OWATracker.prototype[ name ] = methods[ name ];
            }
        } );

        registered.push( plugin );

        return true;
    }

    /** @return {object[]} the registered plugins, in registration order */
    static plugins() {

        if ( ! Object.prototype.hasOwnProperty.call( OWATracker, '_plugins' ) ) {

            OWATracker._plugins = [];
        }

        return OWATracker._plugins;
    }

    setDebug(bool) {

        OWA.setSetting('debug', bool);
    }

    /**
     * Looks for shared state cookies passed on the URL from OWA running
     * under anohter domain.
     *
     * This method must be called explicitly before any of the tracking
     * methods if you want shared state cookies ot be respected.
     *
     */
    checkForLinkedState() {

        if ( this.linkedStateSet != true ) {

            var ls = this.getUrlParam(OWA.getSetting('ns') + 'state');

            if ( ! ls ) {
                ls = this.getAnchorParam(OWA.getSetting('ns') + 'state');
            }

            if ( ls ) {
                OWA.debug('Shared OWA state detected...');

                ls = Util.base64_decode(Util.urldecode(ls));
                OWA.debug('linked state: %s', ls);

                var state = ls.split('.');
                OWA.debug('linked state: %s', JSON.stringify(state));
                if ( state ) {

                    for (var i=0; state.length > i; i++) {

                        var pair = state[i].split('=');
                        OWA.debug('pair: %s', pair);
                        // add cookie domain hash for current cookie domain
                        var value = Util.urldecode(pair[1]);
                        OWA.debug('pair: %s', value);
                        //OWA.debug('about to decode shared link state value: %s', value);
                        var decodedvalue = Util.decodeCookieValue(value);
                        //OWA.debug('decoded shared link state value: %s', JSON.stringify(decodedvalue));
                        var format = Util.getCookieValueFormat(value);
                        //OWA.debug('format of decoded shared state value: %s', format);

                        // Only restore stores that decoded to a populated object.
                        // An empty sharable store (e.g. a visitor with no campaign
                        // state) serializes to "" and decodes to a string/empty
                        // value here; stamping .cdh onto that would throw in strict
                        // mode and there is nothing worth carrying across anyway.
                        if ( decodedvalue && typeof decodedvalue === 'object' ) {

                            decodedvalue.cdh = Util.getCookieDomainHash( this.getCookieDomain() );

                            // The wire carries LOGICAL names (see the send
                            // side); resolve against this page's site so an
                            // arriving session store lands where this tracker
                            // will actually look for it.
                            OWA.replaceState( this.storeName( pair[0] ), decodedvalue, true, format );
                        }
                    }
                }
            }

            this.linkedStateSet = true;
        }
    }

    /**
     * Shares User State cross domains using GET string
      *
     * gets cookies and concatenates them together using:
     * name1=encoded_value1.name2=encoded_value2
     * then base64 encodes the entire string and appends it
     * to an href
     *
     * @param    url    string
     */
    shareStateByLink(url) {

        OWA.debug( 'href of link: '+ url );
        if ( url ) {

            var state = this.createSharedStateValue();

            //check to see if we can just stick this on the anchor
            var anchor = this.getUrlAnchorValue();
            if ( ! anchor ) {

                OWA.debug('shared state: %s', state);
                document.location.href = url + '#' + OWA.getSetting('ns')+ 'state.' + state ;

            // if not then we need ot insert it into GET params
            } else {

            }
        }
    }

    createSharedStateValue() {

        var state = '';

        for (var i=0; this.sharableStateStores.length > i;i++) {

            /*
             * Read from the PHYSICAL store, send under the LOGICAL name.
             *
             * The receiving page resolves the name against its own tracker, so
             * putting this tracker's site id on the wire would hand the other
             * domain a store it cannot match. The site axis is local to each
             * page; what travels is 'this is the session store', and the
             * receiver decides whose session store that is.
             */
            var physical = this.storeName( this.sharableStateStores[i] );
            var value = OWA.getState( physical );
            value = Util.encodeJsonForCookie(value, OWA.getStateStoreFormat(physical));

            if (value) {
                state += this.sharableStateStores[i] + '=' + Util.urlEncode(value);
                if ( this.sharableStateStores.length != ( i + 1) ) {
                    state += '.';
                }
            }
        }

        // base64 for transport
        if ( state ) {
            OWA.debug('linked state to send: %s', state);

            state = Util.base64_encode(state);
            state = Util.urlEncode(state);
            return state;
        }
    }

    shareStateByPost(form) {

        var state = this.createSharedStateValue();
        form.action += '#' + OWA.getSetting('ns') + 'state.' + state;
        form.submit();
    }

    getCookieDomain() {

        return this.getOption('cookie_domain') || OWA.getSetting('cookie_domain') || document.domain;

    }

    setCookieDomain(domain) {

        var not_passed = false;

        if ( ! domain ) {
            domain = ( typeof document !== 'undefined' ) ? document.domain : '';
            not_passed = true;

            // Nothing to resolve from, so leave the domain unestablished rather
            // than crashing on substr() below. A real browser always has
            // document.domain; jsdom and non-DOM contexts do not, and this path
            // now runs at CONSTRUCTION where it used to run on the first event,
            // so it is reached far more often.
            if ( ! domain ) {
                OWA.debug( 'no document.domain to resolve a cookie domain from' );
                return;
            }
            //this.setOption('cookie_domain_mode', 'auto');
            //OWA.setSetting('cookie_domain_mode', 'auto');
        }

        // remove the leading period
        var period = domain.substr(0,1);
        if (period === '.') {
            domain = domain.substr(1);
        }

        var contains_www = false;
        var www = domain.substr(0,4);
        // check for www and eliminate it if no domain was passed.
        if (www === 'www.') {
            if ( not_passed ) {
                domain = domain.substr(4);
            }

            contains_www = true;
        }

        var match = false;
        if (document.domain === domain) {
             match = true;
        }

        // add the leading period back
        domain =  '.' + domain;
        this.setOption('cookie_domain', domain);
        this.setOption('cookie_domain_set', true);
        OWA.setSetting('cookie_domain', domain);
        OWA.debug('Cookie domain is: %s', domain);

        OWA.doAction('cookieDomainEstablished');
    }

    getCookieDomainHash(domain) {

        return Util.crc32(domain);
    }

    setCookieDomainHashing(value) {
	    
        this.hashCookiesToDomain = value;
        OWA.setSetting('hashCookiesToDomain', value);
    }

    checkForOverlaySession() {

        // check to see if overlay sesson should be created
        var a = this.getAnchorParam( OWA.getSetting('ns') + 'overlay');

        if ( a ) {
            a = Util.base64_decode(Util.urldecode(a));
            a = Util.urldecode( a );
            OWA.debug('overlay anchor value: ' + a);
            //var domain = this.getCookieDomain();

            // Deliberately NOT written to a cookie. The payload carries a
            // credential, and a cookie on the tracked site's own domain is
            // readable by every other script there and re-sent to that site
            // on every request. startOverlaySession() holds it in memory
            // instead, which is all its lifetime requires.
            // pause tracker so we dont log anything during an overlay session
            this.pause();
            // start overlay session
            OWA.startOverlaySession( Util.decodeCookieValue( a ) );
        }
    }

    getUrlAnchorValue() {

        var anchor = self.document.location.hash.substring(1);
        OWA.debug('anchor value: ' + anchor);
        return anchor;
    }

    getAnchorParam(name) {

        var anchor = this.getUrlAnchorValue();

        if ( anchor ) {
            OWA.debug('anchor is: %s', anchor);
            var pairs = anchor.split(',');
            OWA.debug('anchor pairs: %s', JSON.stringify(pairs));
            if ( pairs.length > 0 ) {

                var values = {};
                for( var i=0; pairs.length > i;i++ ) {

                    var pieces = pairs[i].split('.');
                    OWA.debug('anchor pieces: %s', JSON.stringify(pieces));
                    values[pieces[0]] = pieces[1];
                }

                OWA.debug('anchor values: %s', JSON.stringify(values));

                if ( values.hasOwnProperty( name ) ) {
                    return values[name];
                }
            }

        }
    }

    /**
     * One query parameter of the current URL, or false.
     *
     * The constructor seeds this.urlParams to {} -- a truthy value -- so the old
     * `this.urlParams || parseUrlParams()` guard ALWAYS short-circuited to the
     * empty object and never parsed the URL, making this return false for every
     * query param (e.g. the ?owa_state= cross-domain linking token in
     * checkForLinkedState).
     *
     * THE CACHE IS KEYED ON THE URL IT WAS PARSED FROM. It used to be filled once
     * and kept, which is correct for a document that loads once and wrong for an
     * SPA: after a route change the parameters are the FIRST page's, so a reader
     * gets a stale answer rather than no answer. Site search is where that showed
     * up -- every virtual page view would have reported the term from whatever
     * screen the visitor landed on first.
     *
     * Still one parse per URL, so the saving that motivated the cache is intact.
     */
    getUrlParam(name) {

        var href = ( typeof location !== 'undefined' && location ) ? location.href : '';

        if ( ! Util.is_object( this.urlParams )
             || this.urlParamsFrom !== href
             || Object.keys( this.urlParams ).length === 0 ) {

            this.urlParams = Util.parseUrlParams( href );
            this.urlParamsFrom = href;
        }

        if ( this.urlParams.hasOwnProperty( name ) ) {
            return this.urlParams[name];
        } else {
            return false;
        }
    }

    dynamicFunc(func){
        //alert(func[0]);
        var args = Array.prototype.slice.call(func, 1);
        //alert(args);
        this[func[0]].apply(this, args);
    }

    /**
     * Convienence method for setting page title
     *
     * Stored page-scoped rather than as a global event property on this
     * tracker. A page title is a fact about the PAGE, so a site that calls this
     * once should have every tracker on the page report it -- a private copy on
     * one tracker cannot do that. Measured before this moved: two trackers on
     * one page, one reporting the title the site set and the other reporting
     * nothing at all.
     */
    setPageTitle(title) {

        OWA.setState( 'd', 'page_title', String( title ).trim() );
    }

    /**
     * Convienence method for setting page type
     *
     * Page-scoped, as setPageTitle(). Note there is no DOM fallback for this
     * one -- unlike page_title, nothing derives a page type -- so the setter is
     * the only source and losing it to a tracker-private copy loses it
     * entirely.
     */
    setPageType(type) {

        OWA.setState( 'd', 'page_type', String( type ).trim() );
    }

    /**
     * The person's display name, as a custom USER property.
     *
     * @deprecated Use setUserProperty('user_name', value).
     *
     * IT USED TO WRITE THE VISITOR COOKIE -- OWA.setState('v', 'user_name') --
     * on the reasoning that an identified user outlives the page and the session.
     * That was wrong twice over. A display name is TEMPORAL: it can change, and a
     * different person can sign in on the same browser, so a cookie holding it
     * outlives the value's own meaning -- the exact failure v1's persisted custom
     * variables had. And it made the value visitor-scoped TRANSPORT feeding an
     * event-scoped destination, while trackingProperties declared it `session`, a
     * scope v2 does not offer for custom values at all (PLAN.html §2.26.1).
     *
     * setUserProperty() is page-lifetime and in memory: nothing is written to
     * a cookie, and the `up_` prefix routes it
     * to the visitor store at INGEST, where it is recorded with when it was set
     * (§2.26.5). So what persists is a server record that can say "this was true
     * from here on" rather than a cookie that cannot.
     */
    setUserName( value ) {

        /*
         * THE PAGE STORE, under the user-property prefix.
         *
         * Not setUserProperty(), which writes globalEventProperties -- and that is
         * per-TRACKER instance state, so a second tracker on the page would not
         * see it. This method's cross-tracker behaviour is deliberate and has a
         * test: a site calls it once and every tracker on the page reports it.
         *
         * The 'd' store keeps that and drops the cookie, which is the whole point:
         * it is registered persist:'never', so it is page-lifetime in memory and
         * shared through the OWA singleton, exactly like setPageTitle(). And
         * because collectPageProperties() copies the store onto the event key by
         * key, the prefixed name arrives as up_user_name -- admitted by prefix,
         * routed to the visitor store by writeUserProperties(), and stamped with
         * when it was set. No compat layer in the path.
         */
        OWA.setState( 'd', OWATracker.USER_PROPERTY_PREFIX + 'user_name',
            String( value ).trim() );
    }


    /**
     * The site's OWN id for a logged-in person.
     *
     * NOT setUserName, which is a display NAME and a custom user property --
     * page-lifetime, and recorded server side with when it was set. user_id is the
     * one field that outlives a cookie, which is what makes it the only honest
     * basis for joining a person's devices -- the alternative is a probabilistic
     * join producing numbers nobody can check, and v2 does not do that. It is also
     * why log_visitor_pii gates this one and not the display name: an install must
     * be able to stop storing the identifier.
     *
     * FORWARD-ONLY. Events collected before someone identified themselves stay
     * anonymous forever. Relabelling a visitor's earlier events would mean
     * rewriting rows in partitions the routine pass does not touch, and a fact
     * row that can change after it is written is the property this whole design
     * gives up.
     *
     * Visitor-scoped so it survives the page, and cleared by passing nothing --
     * which is what a logout should call.
     */
    setUserId( value ) {

        if ( value === undefined || value === null || value === '' ) {

            Util.clearState( 'v', 'user_id' );
            this.deleteGlobalEventProperty( 'user_id' );

            return;
        }

        OWA.setState( 'v', 'user_id', String( value ).trim() );
    }

    /**
     * An author-assigned grouping of pages -- section, template, topic.
     *
     * PAGE-SCOPED, because that is what it describes: a value set on one page
     * must not leak onto the next, and an SPA route change is a new page. A
     * site that wants one group for a whole section sets it on each page of
     * that section, which is also what makes it correct when someone lands
     * mid-section.
     */
    setContentGroup( value ) {

        this.setGlobalEventProperty( 'content_group', String( value ).trim() );
    }

    /**
     * The currency revenue is denominated in, as an ISO 4217 code.
     *
     * Stored beside the amount rather than assumed, because without it a
     * multi-currency store sums minor units of different things and the total
     * is meaningless in a way no report can show.
     */
    setCurrency( value ) {

        this.setGlobalEventProperty( 'currency', String( value ).trim().toUpperCase() );
    }

    /**
     * Record what the page's consent state is at this moment.
     *
     * RECORDED PER EVENT, not once per session, because it can change
     * mid-session -- a visitor accepts a banner on the third page -- and a row
     * collected before that is not retrospectively consented. A row with no
     * consent recorded is also not the same as one with consent denied, which
     * is why absent stays absent rather than defaulting to either.
     *
     * OWA RECORDS, IT DOES NOT ENFORCE. Whether to send at all is the site's
     * decision and its consent platform's; what this does is make the decision
     * visible in the data afterwards, so a question about a period can be
     * answered rather than assumed.
     */
    setConsentState( value ) {

        var state = String( value ).trim().toLowerCase();

        if ( [ 'granted', 'denied' ].indexOf( state ) === -1 ) {

            OWA.debug( 'Ignoring consent state "%s": expected granted or denied.', state );

            return;
        }

        this.setGlobalEventProperty( 'consent_state', state );
    }

    /**
     * Sets the siteId to be appended to all logging events
     */
    setSiteId(site_id) {
	    
        this.siteId = site_id;

        // Re-register under the new name. A site-scoped store is registered
        // under the name resolved from the site id, and READ under the name
        // resolved when it is accessed -- so a tracker told its site after
        // construction would otherwise register 's' and then read
        // 's_<siteId>', an unregistered name that silently falls back to
        // default behaviour. That would quietly undo the session store's
        // deferred hydrate/persist, which is the whole reason it is declared.
        //
        // The command queue now supplies the site id at construction, so this
        // is the reconfigure path rather than the normal one.
        this.registerSiteScopedStores();
    }

    /**
     * Carry an existing shared session into this site's store.
     *
     * Every visitor in the world holds an 'owa_s' cookie written before the
     * session store was scoped to a site. Renaming the store without moving it
     * would end every in-flight session on upgrade and drop last_req, the
     * session id and the session's attribution -- a real cost paid by every
     * single-tracker install, which is nearly all of them, for a fix aimed at
     * the two-tracker case.
     *
     * FIRST TRACKER WINS, and then the legacy store is gone. That is deliberate
     * rather than incidental: the old 'owa_s' was ONE store shared by whatever
     * trackers were on the page, so letting a second tracker inherit it too
     * would hand both of them the same session id -- reproducing exactly the
     * collision this change exists to remove. A second site never had a session
     * of its own under the old scheme, so starting one fresh is the honest
     * answer, not a loss.
     *
     * Pegged to cookieDomainEstablished like every other migration: it moves a
     * cookie, so it genuinely depends on knowing the domain, and now on knowing
     * the site too -- which is why identity had to reach the constructor first.
     */
    registerSessionStoreSiteMigration() {

        var tracker = this;

        OWA.registerStateMigration( 'session-store-per-site', function ( state ) {

            var target = tracker.storeName('s');

            // nothing to do for a tracker with no site, or one whose store is
            // already the shared name
            if ( target === 's' ) {
                return;
            }

            var legacy = state.readPersistedStore( 's' );

            if ( ! legacy || ! legacy.state ) {
                return;
            }

            // Do not overwrite a per-site store that already exists -- this
            // visitor has been here since the upgrade and the legacy cookie is
            // just residue.
            var existing = state.readPersistedStore( target );

            if ( ! existing || ! existing.state ) {

                OWA.debug( 'migrating shared session store into %s', target );

                // writePersistedStore, not replaceStore: the session store is
                // persist:'deferred', so an ordinary write is held back until
                // the session is accepted for delivery. That is right for a
                // session being DECIDED and wrong for one being MOVED -- the
                // cookie already exists, it is just under the old name, and a
                // visitor who leaves before the next beacon must not lose it.
                var carried = legacy.state;

                if ( OWA.getSetting('hashCookiesToDomain') && ! carried.hasOwnProperty('cdh') ) {
                    carried.cdh = Util.getCookieDomainHash( OWA.getSetting('cookie_domain') );
                }

                state.writePersistedStore( target, carried, true );
            }

            state.clear( 's' );
        } );
    }

    /**
     * Register the stores whose name depends on the site id.
     *
     * Split out of the constructor because setSiteId() has to be able to redo
     * it. Registration is idempotent -- it writes metadata for a name -- so
     * running it twice costs nothing. State written under a previous name is
     * left where it is: a tracker changing its site mid-flight is a different
     * tracker as far as session state is concerned.
     */
    registerSiteScopedStores() {

        OWA.registerStateStore( this.storeName('s'), 364, '', 'json', {
            owner:     this,
            logical:   's',
            scope:     'site',
            hydrate:   'deferred',
            hydrateOn: 'isSessionizationDone',
            persist:   'deferred',
            persistOn: 'persistSession'
        });

        /*
         * 'b' is registered here too, though it is not itself per-site.
         *
         * It stays GLOBAL on purpose: it is a legacy cookie that exists in the
         * wild as 'owa_b', with no site in the name, so looking for
         * 'owa_b_<siteId>' would never find the thing being migrated. But its
         * collapse TARGET moves with the site id, so the registration has to be
         * redone whenever that changes -- otherwise it keeps collapsing into
         * the store the tracker no longer reads.
         */
        OWA.registerStateStore('b', '', '', 'json', { collapseInto: this.storeName('s') });
    }

    /**
     * The physical name of a state store for THIS tracker.
     *
     * Site-scoped stores carry the site in the name ('s' -> 's_<siteId>'), so
     * two trackers on one page get separate entries in the state manager's map
     * AND separate cookies, since a cookie is named ns + store name. Global
     * stores are returned unchanged.
     *
     * Falls back to the bare name when no site is known. That is not a silent
     * failure mode any more -- the command queue resolves the site id before
     * the constructor runs -- but a tracker built by hand with no site should
     * still work rather than write to a store called 's_undefined'.
     */
    storeName( logical ) {

        if ( ! this.siteId ) {
            return logical;
        }

        if ( ! this.siteScopedStores || this.siteScopedStores.indexOf( logical ) === -1 ) {
            return logical;
        }

        return logical + '_' + this.siteId;
    }

    /**
     * Convienence method for getting siteId of the logger
     */
    getSiteId() {
	    
        return this.siteId;
    }

    setEndpoint(endpoint) {

        endpoint = ('https:' == document.location.protocol ? window.owa_baseSecUrl || endpoint.replace(/http:/, 'https:') : endpoint );
        this.setOption('baseUrl', endpoint);
        OWA.config.baseUrl = endpoint;
    }

    setLoggerEndpoint(url) {

        this.setOption( 'logger_endpoint', this.forceUrlProtocol( url ) );
    }

    getLoggerEndpoint() {

        var url = this.getOption( 'logger_endpoint') || this.getEndpoint() || OWA.getSetting('baseUrl') ;

        return url + 'log.php';
    }

    setApiEndpoint(url) {

        this.setOption( 'api_endpoint', this.forceUrlProtocol( url ) );
        OWA.setApiEndpoint(url);
    }

    /**
     * See the note on OWA.getApiEndpoint(): this fallback has never executed
     * and must not be relied upon. It also disagrees with that one about what
     * an API URL looks like ('api.php' here, 'api/' there), which is what dead
     * code does. The overlay's API URL comes from the admin interface, the only
     * origin that knows where reporting lives.
     */
    getApiEndpoint() {

        return this.getOption('api_endpoint') || this.getEndpoint() + 'api.php';
    }

    forceUrlProtocol(url) {

        url = ('https:' == document.location.protocol ? url.replace(/http:/, 'https:') : url );
        return url;
    }


    getEndpoint() {
	    
        return this.getOption('baseUrl');
    }

    /**
     * The page's URL, WITHOUT the fragment.
     *
     * Removed HERE, on the client, so the hash never reaches the wire at all,
     * rather than being removed by a server that has already received it.
     *
     * Nothing is lost by it. A fragment has never carried a campaign tag, so
     * the one thing page_location is EVIDENCE for is unaffected; and what it
     * buys is that page_location can be grouped by, which it cannot while
     * /pricing and /pricing#faq are two URLs for one page.
     *
     * ONE FUNCTION, SO THE ROUTE COMPARISON AGREES WITH THE REPORT. This is
     * also what trackRouteChanges() compares, which is the half that is easy to
     * get wrong: strip the fragment from the reported URL but compare the raw
     * one, and a hash-routed site fires a page view per anchor click with every
     * one of them reporting the same URL.
     *
     * trackUrlFragments turns both halves back on together, for a site that
     * genuinely routes on the hash.
     */
    getCurrentUrl() {

        var url = document.URL;

        if ( this.getOption( 'trackUrlFragments' ) ) {

            return url;
        }

        var hash = url.indexOf( '#' );

        return hash === -1 ? url : url.substring( 0, hash );
    }

    /**
     * Make the fragment part of the URL again.
     *
     * For a site that routes on the hash -- example.com/app#/settings -- where
     * the fragment IS the page. It moves the route comparison with it, so
     * turning this on gives both the page views and the URLs to tell them
     * apart, and leaving it off gives neither.
     */
    setTrackUrlFragments( value ) {

        this.setOption( 'trackUrlFragments', ! ! value );
    }

    bindClickEvents() {

        if ( ! this.isClickTrackingEnabled ) {
            var that = this;
            if (window.addEventListener) {
                window.addEventListener('click', function (e) {that.clickEventHandler(e);}, false);

                /*
                 * A MIDDLE-CLICK fires auxclick, not click, and opening a link
                 * in a new tab that way was never recorded. Links only: a middle
                 * button anywhere else is autoscroll, not a click on anything.
                 */
                window.addEventListener('auxclick', function (e) {

                    if ( e.button === 1 ) {

                        that.clickEventHandler( e, true );
                    }

                }, false);
            } else if(window.attachEvent) {
                document.attachEvent('onclick', function (e) {that.clickEventHandler(e);});
            }

            this.isClickTrackingEnabled = true;
        }

    }

    /**
     * Deprecated
     */
    log() {

        var event = new OwaEvent
        event.setEventType( 'page_view' );
        return this.logEvent(event);
    }
    
    isObjectType(obj, type) {
	    
        return !!(obj && type && type.prototype && obj.constructor == type.prototype.constructor);
    }
    
    /** 
     * Logs event by inserting 1x1 pixel IMG tag into DOM
     */
    logEvent(properties, block, callback) {

        if (this.active) {
			
			properties = OWA.applyFilters('tracker.log_event_properties', properties);

            var url = this._assembleRequestUrl(properties);
            var limit = this.getOption('getRequestCharacterLimit');
            if ( url.length > limit ) {
            	
                var data = this.prepareRequestData( properties );

                this.sendLargeRequest( data, properties['event_type'] );

            } else {

                OWA.debug('url : %s', url);
                this.sendRequest( url, properties['event_type'] );
            }

            if (callback && (typeof(callback) === "function")) {
                callback();
            }
        }
    }

    /**
     * Hands a request URL to the browser for delivery.
     *
     * Prefers navigator.sendBeacon: it is the only transport that survives page
     * unload, which is the dominant way a first page view is lost -- the visitor
     * clicks through (including an in-page anchor) while the pixel is still in
     * flight and the browser cancels it. Called with no body it issues a POST
     * with the query string intact, so log.php keeps reading $_GET unchanged.
     *
     * sendBeacon returns false when the browser refuses to queue the payload
     * (size caps, disabled by policy); in that case, and on older browsers, fall
     * back to the historical 1x1 pixel.
     *
     * The return value drives whether session identity may be persisted:
     * accepted -> commit; refused/errored -> abandon; neither (the page was torn
     * down mid-flight) -> nothing is committed, and the next page correctly
     * starts a new session.
     */
    /**
     * A request carrying this page's session identity was accepted for
     * delivery, so the session is worth writing down.
     *
     * Announced rather than called so that what counts as acceptance can move
     * without the state manager knowing about it. Until this fires nothing in
     * the session store reaches the cookie, which is what keeps an undelivered
     * session from being asserted on disk.
     */
    sendAccepted() {

        OWA.doAction( 'persistSession' );
    }

    /**
     * Delivers a payload too large for the query string.
     *
     * This path used to be the odd one out. A URL over
     * getRequestCharacterLimit fell back to a hidden-iframe POST, which yields
     * NO delivery signal, so it had to commit the session optimistically -- the
     * one transport that asserted a session on disk without knowing whether
     * anything arrived. Withholding was not an option either: a site whose
     * payloads always exceed the limit would then never persist a session at
     * all, minting a new one on every page view.
     *
     * sendBeacon with a body removes the dilemma rather than choosing a side of
     * it. It returns whether the browser queued the payload, which is the same
     * acceptance signal the query-string path already uses, so a large event now
     * persists a session on exactly the same terms as a small one.
     *
     * The body is form-urlencoded deliberately, on two counts: PHP populates
     * $_POST from it with no server change (RequestContainer already merges
     * $_GET and $_POST), and it is a CORS-safelisted content type, so a
     * cross-origin beacon does not trigger a preflight the browser would not
     * wait around to complete on unload.
     *
     * cdPost remains the fallback for browsers without sendBeacon, or when it
     * refuses the payload -- and keeps its optimistic commit, because the
     * reasoning above still applies to it.
     */
    sendLargeRequest( data, event_type ) {

        var that = this;
        var queued = false;
        var body = Util.buildPostBody( data );

        if ( typeof navigator !== 'undefined'
             && typeof navigator.sendBeacon === 'function'
             && typeof Blob === 'function' ) {

            try {
                queued = navigator.sendBeacon(
                    this.getLoggerEndpoint(),
                    new Blob( [ body ], { type: 'application/x-www-form-urlencoded' } )
                );
            } catch ( e ) {
                // Some browsers throw on an oversized payload rather than
                // returning false.
                queued = false;
            }
        }

        if ( queued ) {

            OWA.debug( 'Large beacon queued for %s', event_type );
            that.sendAccepted();
            return true;
        }

        OWA.debug( 'sendBeacon unavailable or refused; falling back to iframe POST for %s', event_type );
        this.cdPost( data );

        // No delivery signal from the iframe, so commit optimistically -- the
        // historical behaviour of this path, kept only for the fallback.
        this.sendAccepted();

        return false;
    }

    sendRequest( url, event_type ) {

        var that = this;
        var queued = false;

        if ( typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function' ) {

            try {
                queued = navigator.sendBeacon( url );
            } catch ( e ) {
                // Some browsers throw on a cross-origin or oversized payload
                // rather than returning false.
                queued = false;
            }
        }

        if ( queued ) {

            OWA.debug( 'Beacon queued for %s', event_type );
            that.sendAccepted();
            return true;
        }

        var image = new Image(1, 1);

        // NOTE: 'onload', not 'onLoad'. The latter is not a DOM property and
        // never fires -- it sat here unnoticed for years because nothing hung
        // off the success path until now.
        image.onload  = function () { that.sendAccepted(); };
        // No counterpart to onload: acceptance is what triggers persistence, so
        // a failure simply never triggers it. The session stays out of the
        // cookie, the next page finds no sid, treats itself as a new session,
        // and the server creates it properly -- one lost hit rather than a
        // stranded session.
        image.onerror = function () { OWA.debug( 'Web bug failed for %s', event_type ); };
        image.src = url;

        OWA.debug('Inserted web bug for %s', event_type);
        return false;
    }
        
    /**
     * Private method for helping assemble request params
     */
    _assembleRequestUrl(properties) {
    
        var get = this.prepareRequestDataForGet( properties );

        var log_url = this.getLoggerEndpoint();

        if (log_url.indexOf('?') === -1) {
            log_url += '?';
        } else {
            log_url += '&';
        }

        // add some radomness for cache busting
        var full_url = log_url + get;

        return full_url;
    }

    prepareRequestData( properties ) {
    
          var data = {};

        // The APP namespace, not the wire one -- and it is empty.
        //
        // These params go to log.php, whose query string the tracker writes and
        // OWA reads; nothing else puts a param on it, so there is nothing for
        // the prefix to protect against. The wire namespace still governs the
        // names OWA puts in a TRACKED PAGE's URL or cookie jar (owa_state,
        // owa_overlay, the cookies) and the campaignKeys a site owner writes
        // into their own marketing links -- those really are shared namespaces.
        //
        // Old trackers cached on customer sites keep sending the prefixed
        // spelling; RequestContainer reads both.
        var ns = OWA.getSetting('app_ns');

           //assemble query string
        for ( var param in properties ) {
            // print out the params
            var value = '';

            if ( properties.hasOwnProperty( param ) ) {

                  /*
                   * Built by concatenation rather than a format string. These
                   * three used to run the namespace through sprintf as PART of
                   * the format -- Util.sprintf( ns + '%s[%s]', ... ) -- so a '%'
                   * anywhere in the configured namespace would have been read as
                   * a specifier and eaten the argument after it.
                   */
                  if ( Array.isArray( properties[param] ) ) {

                    var n = properties[param].length;
                    for ( var i = 0; i < n; i++ ) {

                        if ( Util.is_object( properties[param][i] ) ) {
                            for ( var o_param in properties[param][i] ) {

                                data[ ns + param + '[' + i + '][' + o_param + ']' ] = properties[ param ][ i ][ o_param ];
                            }
                        } else {
                            // what the heck is it then. assume string
                            data[ ns + param + '[' + i + ']' ] = properties[ param ][ i ];
                        }
                    }
                // assume it's a string
                } else {
                    data[ ns + param ] = properties[ param ];
                }
            }
        }

        return data;
    }
    
    prepareRequestDataForGet( properties ) {

        var properties = this.prepareRequestData( properties );

        var get = '';

        for ( var param in properties ) {

            if ( properties.hasOwnProperty( param ) ) {

                var kvp = '';
                // URL-encode the VALUE only. Without this, any value containing a
                // query-structural character truncates or corrupts the beacon: '#'
                // starts a fragment (everything after it never leaves the browser),
                // '&' begins a bogus new param, '=' splits the pair. A clicked link
                // whose href held a '#' or '&' therefore lost click_x / site_id /
                // session_id off the wire. The server expects encoded values -- it
                // reads $_GET (PHP url-decodes) and decodeRequestParams() decodes
                // again -- so encodeURIComponent here is the symmetric half of that
                // contract. The KEY stays raw on purpose: the owa_* names are a fixed
                // vocabulary and the flattened array keys (owa_foo[0][bar]) rely on
                // PHP's $_GET bracket parsing, which encoded brackets would defeat.
                kvp = param + '=' + encodeURIComponent( properties[ param ] ) + '&';
                get += kvp;
            }
        }

        return get;
    }

    /** 
     * Issues a cross-domain http post
     *
     * This method generates a 1x1 iframe with a form in it that is
     * populated by whatever data is passed to it. The http response cannot be evaluated
     * So this is really only to be used as an alternative to the GET tracking request
     */
    cdPost( data ) {

        var container_id = "owa-tracker-post-container";
        var post_url = this.getLoggerEndpoint();

        var iframe_container = document.getElementById( container_id );

        // create iframe container if necessary
        if ( ! iframe_container ) {

            // create post frame container
            var div = document.createElement( 'div' );
            div.setAttribute( 'id', container_id );
            div.setAttribute('height', '0px');
            div.setAttribute('width','0px');
            div.setAttribute('style', 'border: none; overflow-x: hidden; overflow-y: hidden; display: none;');
            document.body.appendChild( div );
            iframe_container = document.getElementById( container_id );
        }

        // create iframe and post data once its fully loaded.
        this.generateHiddenIframe( iframe_container, data );
    }

    /**
     * Generates a hidden 1x1 pixel iframe
     */
    generateHiddenIframe( parentElement, data ) {

        var iframe_name = 'owa-tracker-post-iframe';

        var iframe = document.createElement("iframe");
        iframe.setAttribute('name', iframe_name);
        iframe.setAttribute('src', 'about:blank');
        iframe.setAttribute('width', 1);
        iframe.setAttribute('height', 1);

        iframe.setAttribute('class', iframe_name);
        iframe.setAttribute('style', 'border: none; overflow: hidden; ');
        iframe.setAttribute('scrolling', 'no');
        //iframe.onload = function () { this.postFromIframe( data );};

        var that = this;

        // If no parent element is specified then use body as the parent element
        if ( parentElement == null ) {
            parentElement = document.body;
         }
        // This is necessary in order to initialize the document inside the iframe
        parentElement.appendChild( iframe );

        // set a timer to check and see if the iframe is fully loaded.
        // without this there is a race condition in IE8
        var timer = setInterval( function() {

            var doc = that.getIframeDocument( iframe );
            
            if ( doc ) {
            clearInterval(timer); //clear the interval before submitting data, race condition could occur otherwise resulting in duplicate tracked events
                that.postFromIframe(iframe, data);

            }


            
        }, 1 );
        
        // needed to cleanup history items in browsers like Firefox
       
        var cleanuptimer = setInterval( function() {


             parentElement.removeChild(iframe);
             clearInterval(cleanuptimer);
            
        }, 1000 );
        
    }

    postFromIframe( ifr, data ) {

        var post_url = this.getLoggerEndpoint();
        var doc = this.getIframeDocument(ifr);
        // create form
        //var frm = this.createPostForm();
        var form_name = 'post_form' + Math.random();

        var frm = doc.createElement('form');
        frm.setAttribute( 'name', form_name );
        frm.setAttribute( 'id', form_name );
        frm.setAttribute("action", post_url);
        frm.setAttribute("method", "POST");

        // create hidden inputs, add them to form
        for ( var param in data ) {

            if (data.hasOwnProperty(param)) {

                // Created from the IFRAME's document, like the form it joins.
                // The old code created the form with doc.createElement and the
                // inputs with document.createElement, an asymmetry left behind
                // when the IE branch was written. Consistency only, not a fix:
                // appendChild adopts a node from another document, and adoption
                // leaves nothing behind to distinguish the two afterwards --
                // which is also why no test can tell them apart.
                var input = doc.createElement( "input" );
                input.setAttribute( "name", param );
                input.setAttribute( "type", "hidden" );
                input.setAttribute( "value", data[param] );

                frm.appendChild( input );

            }
        }

        // add form to iframe
        doc.body.appendChild( frm );

        //submit the form inside the iframe
        doc.forms[form_name].submit();

         // remove the form from iframe to clean things up
          doc.body.removeChild( frm );
    }

    getIframeDocument( iframe ) {

        // Initiate the iframe's document to null
        var doc = null;

        // Depending on browser platform get the iframe's document, this is only
        // available if the iframe has already been appended to an element which
        // has been added to the document
        if( iframe.contentDocument ) {
            // Firefox, Opera
            doc = iframe.contentDocument;
        } else if( iframe.contentWindow && iframe.contentWindow.document ) {
            // Internet Explorer
            doc = iframe.contentWindow.document;
        } else if(iframe.document) {
            // Others?
            doc = iframe.document;
        }

        // If we did not succeed in finding the document then throw an exception
        if( doc == null ) {
            OWA.debug("Document not found, append the parent element to the DOM before creating the IFrame");
        }

        doc.open();
        doc.close();

        return doc;
    }

    getViewportDimensions() {

        var viewport = new Object();
        viewport.width = window.innerWidth ? window.innerWidth : document.body.offsetWidth;
        viewport.height = window.innerHeight ? window.innerHeight : document.body.offsetHeight;
        return viewport;
    }

    /**
     * Sets the X coordinate of where in the browser the user clicked
     *
     */
    findPosX(obj) {

        var curleft = 0;
        if (obj.offsetParent)
        {
            while (obj.offsetParent)
            {
                curleft += obj.offsetLeft
                obj = obj.offsetParent;
            }
        }
        else if (obj.x)
            curleft += obj.x;
        return curleft;
    }

    /**
     * Sets the Y coordinates of where in the browser the user clicked
     *
     */
    findPosY(obj) {

        var curtop = 0;
        if (obj.offsetParent)
        {
            while (obj.offsetParent)
            {
                curtop += obj.offsetTop
                obj = obj.offsetParent;
            }
        }
        else if (obj.y)
            curtop += obj.y;
        return curtop;
    }

    /**
     * Get the HTML elementassociated with an event
     *
     */
    _getTarget(e) {

        // Determine the actual html element that generated the event
        var targ = e.target || e.srcElement;

        if( typeof targ == 'undefined' || targ==null ) {

            return null; //not all ie events provide srcElement
        }

        if (targ.nodeType == 3) {
            // defeat Safari bug
            targ = targ.parentNode;
        }

        return targ;
    }

    /**
     * Sets coordinates of where in the browser the user clicked
     *
     */
    getCoords(e) {

        var coords = new Object();

        if ( typeof( e.pageX ) == 'number' ) {
            coords.x = e.pageX + '';
            coords.y = e.pageY + '';
        } else {
            coords.x = e.clientX + '';
            coords.y = e.clientY + '';
        }

        return coords;
    }

    /**
     * Sets the tag name of html eleemnt that generated the event
     */
    getDomElementProperties(targ) {

        var properties = new Object();
        // Set properties of the owa_click object. Lower-case the tag so dom_element_tag
        // is stored consistently regardless of how the browser reports tagName.
        properties.dom_element_tag = String( targ.tagName ).toLowerCase();

        /*
         * Whitespace runs collapse to one space, then trimmed: an indented,
         * multi-line link is one value, not one per way it was formatted.
         */
        var text = function ( value ) {

            return String( value == null ? '' : value ).replace( /\s+/g, ' ' ).trim();
        };

        if (targ.tagName == "A" || targ.tagName == "AREA") {

            if (targ.textContent != undefined) {
                 properties.dom_element_text = text( targ.textContent );
            } else {
                 properties.dom_element_text = text( targ.innerText );
            }

            properties.target_url =  targ.href;

        } else if (targ.tagName == "INPUT") {

            properties.dom_element_text = text( targ.value );

        } else if (targ.tagName == "IMG") {

            properties.target_url = targ.parentNode.href;
            properties.dom_element_text = text( targ.alt );

        } else {

            //properties.target_url = targ.parentNode.href || null;

            if (targ.textContent != undefined) {
                 //properties.html_element_text = targ.textContent;
                 properties.html_element_text = '';
            } else {
                //properties.html_element_text = targ.innerText;
                properties.html_element_text = '';
            }
        }

        return properties;
    }

    /*
     * getElementPath() IS GONE. It built a CSS selector by walking up to eight
     * ancestors with :nth-of-type() indexes. Nothing on the server ever read the
     * column it fed -- no report, widget or overlay -- and a template edit
     * renumbers every path at once, so it was neither aggregable nor stable across
     * a deploy. The heatmap places clicks by coordinate.
     */

    /**
     * Whether a URL leaves this site.
     *
     * Compared on HOST, not on the full URL. Internal is the page's own host, or
     * the cookie domain or anything under it. Comparing to the page's host alone
     * reported every link between www and the apex, or to a sibling subdomain,
     * as outbound.
     *
     * THE CLIENT DECIDES THIS, and briefly the server did. Three reasons it
     * belongs here:
     *
     *   - a non-web client has no page_location and target_url to compare, but it
     *     knows perfectly well whether the thing it just raised left the property.
     *     A server derivation can only serve senders shaped like a browser;
     *   - it removes an ordering dependency. A server-derived is_outbound reads two
     *     OTHER derived properties' inputs, and the property pass walks the
     *     registry in key order, so correctness rested on where the entry sat;
     *   - only the client can see the DOM. An href rewritten by script, a target
     *     inside a shadow root: the server sees whatever string arrived.
     *
     * @param {string} url
     * @return {boolean}
     */
    isOutboundUrl( url ) {

        if ( ! url || typeof window === 'undefined' ) {

            return false;
        }

        var target;

        try {

            target = new URL( url, window.location.href );

        } catch ( e ) {

            return false;
        }

        // mailto:, tel:, javascript: -- no host, and not a departure.
        if ( target.protocol !== 'http:' && target.protocol !== 'https:' ) {

            return false;
        }

        var host = target.hostname.toLowerCase();

        if ( ! host || host === window.location.hostname.toLowerCase() ) {

            return false;
        }

        /*
         * THE COOKIE DOMAIN IS THE SITE. By default it is the page's host without
         * www., so from www.example.com a link to example.com or to
         * shop.example.com stays on the site. A site whose property spans other
         * hosts sets its cookie domain to say so.
         */
        var domain = String( this.getCookieDomain() || '' ).replace( /^\./, '' ).toLowerCase();

        if ( domain && ( host === domain
            || host.slice( - ( domain.length + 1 ) ) === '.' + domain ) ) {

            return false;
        }

        return true;
    }

    /**
     * The downloaded file's PATH -- no host, no query, no fragment.
     *
     * THE PATH, NOT THE BASENAME. It was the basename since download tracking was
     * added, so /2024/report.pdf and /2025/report.pdf collapsed into one row of a
     * downloads report. The path keeps them apart.
     *
     * The HOST is deliberately dropped: a file served from the site and the same
     * file served from a CDN are one document, and target_host on the same row
     * answers where it came from for anyone who needs that.
     *
     * A method, because the expression was inline and cut the same string THREE
     * times -- split('#')[0].split('?')[0] twice more inside its own
     * substring(lastIndexOf()).
     *
     * new URL() rather than string surgery, so a relative href resolves against
     * the page instead of being read as a path that happens to start with a
     * letter. It throws on input no base can absolutise, and this runs on whatever
     * href a page carries, so the cut is the fallback.
     *
     * Left percent-encoded. decodeURIComponent throws on a malformed sequence.
     *
     * @param {string} url
     * @return {string}
     */
    getDownloadFileName( url ) {

        var raw = String( url );

        try {

            return new URL( raw, ( typeof window !== 'undefined' && window.location )
                ? window.location.href : undefined ).pathname;

        } catch ( e ) {

            var path = raw.split( '#' )[0].split( '?' )[0];
            var at   = path.indexOf( '//' );

            // Drop scheme://host when there is one, so the fallback answers a path
            // as well. Without this a failed parse would return the whole URL.
            if ( at !== -1 ) {

                var slash = path.indexOf( '/', at + 2 );

                return slash === -1 ? '/' : path.substring( slash );
            }

            return path;
        }
    }

    /**
     * The file extension a URL downloads, or '' if it is not a download.
     *
     * A LIST rather than "anything with a dot in the last path segment",
     * because that reads every /v1.2/docs path and every .html as a download.
     * The list is an option so a site can add its own.
     *
     * @param {string} url
     * @return {string}
     */
    getDownloadExtension( url ) {

        if ( ! url ) {

            return '';
        }

        var path = String( url ).split( '#' )[0].split( '?' )[0];
        var last = path.substring( path.lastIndexOf( '/' ) + 1 );
        var dot  = last.lastIndexOf( '.' );

        if ( dot < 1 ) {

            return '';
        }

        var ext = last.substring( dot + 1 ).toLowerCase();

        return this.getOption( 'downloadExtensions' ).indexOf( ext ) > -1 ? ext : '';
    }

    /**
     * The link a click was on: the element itself, or the a[href] / area[href]
     * it sits inside, or null. A click on the <span> or <svg> inside a link is
     * a click on the link -- its URL, its id -- which is how every link with
     * markup inside it would otherwise lose its destination.
     *
     * @param {Element} targ
     * @return {Element|null}
     */
    getClickedLink( targ ) {

        if ( ! targ || typeof targ.closest !== 'function' ) {

            return null;
        }

        return targ.closest( 'a[href], area[href]' );
    }

    /**
     * @param {Event}   e
     * @param {boolean} linksOnly true for a middle-click, which counts only on a link
     */
    clickEventHandler( e, linksOnly ) {

        // hack for IE
        e = e || window.event;

        //clicked DOM element properties
        var clicked = this._getTarget(e);

        if ( ! clicked ) {

            return;
        }

        var link = this.getClickedLink( clicked );

        if ( linksOnly && ! link ) {

            return;
        }

        var targ = link || clicked;

        var click = new OwaEvent();

        // set event type
        click.setEventType( 'click' );

        /*
         * NOTHING IS SENT FOR AN ABSENT id, name OR class. It was the literal
         * '(not set)', which the row builder turns back into NULL: bytes on the
         * wire meaning nothing.
         */
        if ( targ.hasAttribute && targ.hasAttribute('name') && targ.getAttribute('name') ) {
            click.set("dom_element_name", targ.getAttribute('name'));
        }

        /*
         * THE ELEMENT'S VALUE IS NOT COLLECTED.
         *
         * A click on an input would have shipped whatever the visitor had typed
         * into it, and no report has ever shown it: it reached no column, and the
         * server's registry declares no destination for it. "We store it but
         * nothing reads it" is the worst version of that trade.
         */

        if ( targ.id ) {
            click.set("dom_element_id", targ.id);
        }

        // getAttribute, not className: an SVG element's className is an
        // SVGAnimatedString object, not the class list.
        var dom_class = targ.getAttribute ? targ.getAttribute('class') : '';

        if ( dom_class ) {
            click.set("dom_element_class", dom_class);
        }

        // dom_element_tag is set (lower-cased) by getDomElementProperties() below,
        // whose merge() would overwrite anything set here -- so no duplicate set.
        click.set("page_url", window.location.href);
        // view port dimensions - needed for calculating relative position
        var viewport = this.getViewportDimensions();
        click.set("page_width", viewport.width);
        click.set("page_height", viewport.height);
        var properties = this.getDomElementProperties(targ);
        click.merge(this.filterDomProperties(properties));

        // Whether this click left the site, decided where the DOM is.
        click.set( 'is_outbound', this.isOutboundUrl( click.get( 'target_url' ) ) ? 1 : 0 );

        // set coordinates
        /*
         * The ELEMENT's position is not collected either. The heatmap is an
         * ordinary dimensional query over click_x and click_y -- the CLICK's
         * coordinates, set below -- and the element's own offsets reached only
         * v1's owa_click columns, which v2 ingest does not write.
         */
        var coords = this.getCoords(e);
        click.set('click_x', coords.x);
        click.set('click_y', coords.y);

        // Anything compiled in that wants every click hears it here, before
        // it is sent or classified.
        OWA.doAction( 'tracker.click', { tracker: this, click: click, domEvent: e } );

        //if all that works then log
        if (this.getOption('logClicksAsTheyHappen')) {
            //this.trackEvent(full_click);
            this.trackEvent(click);
        }

        this.classifyClickTarget( click.get( 'target_url' ), click );


        //this.click = full_click;
        this.click = click;
    }

    // stub for a filter that will strip certain properties or abort the logging
    filterDomProperties(properties) {

        return properties;

    }

    /**
     * Raise a `scroll` event the first time the page passes a depth threshold.
     *
     * ONE EVENT PER PAGE PER THRESHOLD, not one per scroll tick. The handler
     * fired on every scroll event and queued one each time -- `last_scroll` was
     * assigned and never read, so nothing throttled it and nothing could: a
     * timestamp throttle would still send a stream of them.
     *
     * Depth is the right unit rather than time or pixels. It is comparable
     * across page lengths and viewport sizes, and it answers the question
     * anyone actually asks of it: did they reach the bottom.
     *
     * The threshold list is an option. The default is quartiles -- 25, 50, 75
     * and 90 -- so the marks read as a depth funnel; a site that wants the one
     * event at 90%, where "read to the end" becomes true, sets [90].
     */
    checkScrollDepth() {

        // A route change is settling; send() checks once its page view is out.
        if ( this.routePending ) {

            return;
        }

        var depth = this.getScrollDepth();

        if ( ! depth ) {

            return;
        }

        /*
         * ASCENDING, whatever order the site gave. The walk below relies on it:
         * last_scroll records the deepest mark reported, so with [90, 25] a jump to
         * the bottom reported 90 and then skipped 25 for good, because 90 is not
         * below 25.
         *
         * Every mark crossed is reported, not only the deepest -- so each mark's
         * count reads directly as "how many reached at least this far", and a
         * visitor who jumps to the bottom still counts as having passed halfway.
         */
        var thresholds = ( this.getOption( 'scrollThresholds' ) || [ 90 ] )
            .map( Number )
            .filter( function ( mark ) { return mark > 0 && mark <= 100; } )
            .sort( function ( a, b ) { return a - b; } );

        for ( var i = 0; i < thresholds.length; i++ ) {

            var mark = thresholds[ i ];

            if ( depth >= mark && this.last_scroll < mark ) {

                this.last_scroll = mark;

                var event = this.makeEvent();
                event.setEventType( 'scroll' );
                event.set( 'scroll_depth', mark );

                this.trackEvent( event );
            }
        }
    }

    /**
     * How far down the page the visitor has reached, as a percentage.
     *
     * The bottom of the VIEWPORT against the height of the document, so a page
     * that fits on one screen is 100 from the start -- which is true, and the
     * alternative (measuring the top of the viewport) says 0 for a page that
     * has been read in full.
     *
     * @return {number} 0 when the document has no measurable height
     */
    getScrollDepth() {

        if ( typeof document === 'undefined' || ! document.documentElement ) {

            return 0;
        }

        var body = document.body || {};
        var el   = document.documentElement;

        var height = Math.max(
            body.scrollHeight || 0, el.scrollHeight || 0,
            body.offsetHeight || 0, el.offsetHeight || 0 );

        if ( ! height ) {

            return 0;
        }

        var viewport = this.getViewportDimensions();
        var bottom   = this.getScrollingPosition().y + ( viewport.height || 0 );

        return Math.min( 100, Math.round( ( bottom / height ) * 100 ) );
    }

    /*
     * ---------------------------------------------------------------
     * ENGAGEMENT
     * ---------------------------------------------------------------
     */

    /**
     * Start counting engaged time, if it is not already running.
     *
     * Called when the page becomes visible: on load, on a tab switch back, and
     * on a bfcache restore. Idempotent, because all three can fire together.
     */
    startEngagement() {

        if ( this.engagementSince === null && this.isPageEngaged() ) {

            this.engagementSince = this.getTime();
        }
    }

    /**
     * Whether the page is being read: visible AND focused.
     *
     * Visible alone counted a window left open behind another application, or
     * on a second monitor, as reading time for as long as it stayed there.
     *
     * @return {boolean}
     */
    isPageEngaged() {

        if ( typeof document === 'undefined' ) {

            return true;
        }

        return document.visibilityState !== 'hidden' && this.pageFocused;
    }

    /**
     * Stop counting, banking whatever has accrued.
     *
     * Called when the page is hidden. The banked time is not SENT here -- the
     * hide-time event does that -- it just stops the clock, so a tab left open
     * in the background for an hour does not report an hour of reading.
     */
    pauseEngagement() {

        if ( this.engagementSince === null ) {

            return;
        }

        this.engagementAccrued += this.getTime() - this.engagementSince;
        this.engagementSince = null;
    }

    /**
     * Time accrued on this page and not yet sent, in milliseconds.
     *
     * CONSUMED by the caller: reading it marks the time as reported, so the
     * same milliseconds cannot ride two beacons. That is what makes the value
     * a delta rather than a running total, and what makes losing one beacon
     * cost one increment.
     *
     * @return {number}
     */
    consumeEngagementDelta() {

        var now = this.getTime();

        if ( this.engagementSince !== null ) {

            this.engagementAccrued += now - this.engagementSince;
            this.engagementSince = now;
        }

        var delta = this.engagementAccrued - this.engagementReported;

        if ( delta <= 0 ) {

            return 0;
        }

        this.engagementReported = this.engagementAccrued;

        return Math.round( delta );
    }

    /**
     * Reset the engagement clock for a new page.
     *
     * An SPA route change is a new page: its time starts at zero, and the
     * previous route's residue has already been delivered by the page_view
     * that ends it.
     */
    resetEngagement() {

        this.engagementAccrued  = 0;
        this.engagementReported = 0;
        this.engagementSince    = null;
        this.startEngagement();
    }

    /**
     * Deliver the engagement residue, once, at hide.
     *
     * FIRES ON ANY HIDE AT ANY SIZE. The engaged threshold is a READ-TIME
     * classification -- a session is engaged if its total crosses it -- and
     * gating the send on it would bake the threshold into collection, so it
     * could never be retuned afterwards.
     *
     * DELIVERED ONCE. An earlier design also piggybacked the residue onto the
     * next request, which meant one stretch of time arriving twice and needing
     * a derived id and a counter to collapse. Sending it here and nowhere else
     * removes the second arrival and everything it would have needed.
     *
     * Two tabs of one session each send their own residue and BOTH are real --
     * not a duplicate to collapse, because they differ in the instant they
     * arrive, which is what the event id is derived from.
     *
     * Final-page dwell is the one irreducible lossy attempt: a browser torn
     * down without firing pagehide reports nothing, and no transport fixes it.
     */
    trackEngagement() {

        this.pauseEngagement();

        /*
         * UNDER A SECOND IS NOT AN EVENT OF ITS OWN. The residue stays
         * unreported, so the next event carries it if there is one; if the page
         * is leaving, under a second of it is lost. Without the floor a brief
         * tab switch sends a user_engagement carrying a few milliseconds.
         */
        if ( this.engagementAccrued - this.engagementReported
             < OWATracker.MIN_ENGAGEMENT_EVENT_MSEC ) {

            return;
        }

        var delta = this.consumeEngagementDelta();

        var event = this.makeEvent();
        event.setEventType( 'user_engagement' );
        event.set( 'engagement_msec', delta );

        return this.trackEvent( event );
    }

    /*
     * ---------------------------------------------------------------
     * PAGE LIFECYCLE
     * ---------------------------------------------------------------
     */

    /**
     * Bind the events that tell us the page is being read, left, or replaced.
     *
     * OWA bound NONE of these. visibilitychange, pagehide, pageshow, popstate
     * and hashchange had zero occurrences in this file, which is why there was
     * no engagement measurement, no SPA page view and no bfcache handling.
     *
     * visibilitychange AND pagehide, not either alone: visibilitychange is the
     * only one a mobile browser reliably fires when the user switches app, and
     * pagehide is the one that fires on a real navigation away. Both are
     * idempotent here -- trackEngagement() sends nothing when the delta is
     * zero -- so the overlap costs a no-op rather than a duplicate.
     *
     * beforeunload is deliberately NOT used: it is unreliable on mobile, and
     * registering it opts the page out of the bfcache in some browsers, which
     * would break the restore path below to measure it.
     */
    bindPageLifecycleEvents() {

        if ( this.pageLifecycleBound
             || typeof document === 'undefined' || typeof document.addEventListener !== 'function' ) {

            return;
        }

        this.pageLifecycleBound = true;

        this.pageFocused = typeof document.hasFocus === 'function' ? document.hasFocus() : true;

        var that = this;

        document.addEventListener( 'visibilitychange', function () {

            if ( document.visibilityState === 'hidden' ) {

                that.flushPendingRouteView();
                that.trackEngagement();

            } else {

                that.startEngagement();
            }

        }, false );

        /*
         * A FALLBACK. On every unload the current spec fires visibilitychange
         * (hidden) first, and this finds nothing left to send. Safari has unloaded
         * pages without it, and then this is the only notice the page is leaving.
         */
        window.addEventListener( 'pagehide', function () {

            that.flushPendingRouteView();
            that.trackEngagement();

        }, false );

        /*
         * Focus pauses and resumes the clock without sending: a visible window
         * behind another application is not being read. The time banked before
         * a blur rides the next event, or the hide.
         */
        window.addEventListener( 'blur', function () {

            that.pageFocused = false;
            that.pauseEngagement();

        }, false );

        window.addEventListener( 'focus', function () {

            that.pageFocused = true;
            that.startEngagement();

        }, false );

        /*
         * A bfcache restore is a page that was never torn down coming back.
         * event.persisted says so. Without this the visitor reads the page
         * again and OWA records nothing at all -- no page view, and an
         * engagement clock that has been stopped since they left.
         *
         * NOT a new page view: the URL has not changed and the session has not
         * restarted, so counting one would inflate pageviews on every back
         * button. Resuming the clock is the whole of it.
         */
        window.addEventListener( 'pageshow', function ( e ) {

            if ( e && e.persisted ) {

                that.startEngagement();
            }

        }, false );

        this.startEngagement();
    }

    /**
     * Track route changes in a single-page application as page views.
     *
     * The gap this closes is large and easy to miss: an SPA tracked by OWA
     * today records ONE page view per session, because the only page view is
     * the one the snippet fires on load and nothing notices the route change
     * afterwards. Every subsequent screen is invisible.
     *
     * pushState and replaceState are patched rather than polled, and popstate
     * and hashchange are listened for, because those four are the complete set
     * of ways a route changes without a document load. Patching is how every
     * analytics tracker does this -- there is no event for pushState.
     *
     * OPT-IN, because a route change is a claim about what the site means by a
     * page and only the site can make it.
     *
     * In-page anchors are no longer the reason. getCurrentUrl() leaves the
     * fragment out, so a hash change that is only an anchor produces the same
     * URL and is not a route change -- the guard below sees to that. A site
     * that routes on the hash turns trackUrlFragments on, which makes those
     * URLs differ again and the page views appear.
     */
    trackRouteChanges() {

        if ( this.routeTrackingEnabled || typeof window === 'undefined' ) {

            return;
        }

        this.routeTrackingEnabled = true;

        var that = this;

        // The URL of the last page view SENT. Intermediate URLs a route passes
        // through inside the settle window never become page views.
        var lastTracked = this.getCurrentUrl();
        var pending = null;

        /*
         * The page view goes once the URL has been still for ROUTE_SETTLE_MSEC,
         * and reads the URL and title then. Frameworks set document.title
         * AFTER pushState, so a page view sent from inside the patched call
         * carried the previous screen's title; and a redirect pushing twice in
         * quick succession was two page views.
         */
        var send = function () {

            if ( pending ) {

                clearTimeout( pending );
                pending = null;
            }

            that.flushRouteView = null;
            that.routePending = false;

            var url = that.getCurrentUrl();

            // Left and came back inside the window: no route change happened.
            if ( url === lastTracked ) {

                return;
            }

            // The previous page is this page's referrer, for the page view and
            // every event after it on this route. document.referrer still
            // names the page the DOCUMENT loaded from.
            that.routeReferrer = lastTracked;
            lastTracked = url;

            that.trackPageView( url );

            /*
             * A ROUTE CHANGE TO A RESULTS URL IS A NEW SEARCH, so this is called
             * per route the way trackPageView is. trackSiteSearch() reads the URL
             * and raises an event; it binds no listener, so there is nothing that
             * would have noticed the change by itself.
             *
             * This is why getUrlParam()'s cache is keyed on the URL it parsed:
             * filled once and kept, every route change here would have reported
             * the term from whatever screen the visitor landed on first.
             */
            that.trackSiteSearch();

            /*
             * THE ROUTE'S DEPTH, measured as a loaded page's is: its marks start
             * again, and a route that fits the viewport reports them now rather
             * than waiting for a scroll that will never come. After the page
             * view, in the order a loaded page sends them.
             */
            that.last_scroll = 0;

            if ( that.isScrollTrackingEnabled ) {

                that.checkScrollDepth();
            }
        };

        var changed = function () {

            var url = that.getCurrentUrl();

            // A route change that does not change the URL is not one. Guarded
            // because replaceState is used for things other than navigation --
            // storing filter state, for instance -- and each of those would
            // otherwise be a page view.
            if ( ! pending && url === lastTracked ) {

                return;
            }

            // The residue of the route being LEFT, delivered at the moment of
            // leaving, so the time lands against the page it was spent on.
            if ( ! pending ) {

                that.trackEngagement();
                that.resetEngagement();
            }

            // No scroll event for a route before its page view: depth checks
            // wait until the page view is sent (see send()).
            that.routePending = true;

            if ( pending ) {

                clearTimeout( pending );
            }

            pending = setTimeout( send, OWATracker.ROUTE_SETTLE_MSEC );

            // Sent early if the page is hidden or unloads inside the window.
            that.flushRouteView = send;
        };

        if ( typeof window.history === 'object' && window.history ) {

            ['pushState', 'replaceState'].forEach( function ( method ) {

                var original = window.history[ method ];

                if ( typeof original !== 'function' ) {

                    return;
                }

                window.history[ method ] = function () {

                    var result = original.apply( window.history, arguments );

                    // After the call, so getCurrentUrl() reads the new URL.
                    changed();

                    return result;
                };
            } );
        }

        window.addEventListener( 'popstate', changed, false );
        window.addEventListener( 'hashchange', changed, false );
    }

    getScrollingPosition() {

        var position = [0, 0];
        if (typeof window.pageYOffset != 'undefined') {
            position = {x: window.pageXOffset, y: window.pageYOffset};
        } else if (typeof document.documentElement.scrollTop != 'undefined' && document.documentElement.scrollTop > 0) {
            position = {x: document.documentElement.scrollLeft, y: document.documentElement.scrollTop};
        } else if (typeof document.body.scrollTop != 'undefined') {
            position = {x: document.body.scrollLeft, y:    document.body.scrollTop};
        }
        return position;
    }

    bindHoverEvents() {

        //handler = handler || this.hoverEventHandler;
        //document.onmousemove = handler;

    }

    // utc epoch in seconds
    getTimestamp() {

        return Util.getCurrentUnixTimestamp();
    }

    // utc epoch in milliseconds
    getTime() {

        return Math.round(new Date().getTime());
    }

    getOption(name) {

        if ( this.options.hasOwnProperty(name) ) {
            return this.options[name];
        }
    }

    setOption(name, value) {

        this.options[name] = value;
    }

    setLastEvent(event) {
	    
        return;
    }

    sleep(delay) {
        var start = new Date().getTime();
        while (new Date().getTime() < start + delay);
    }

    pause() {

        this.active = false;
    }

    restart() {
	    
        this.active = true;
    }

    // Event object Factory
    makeEvent() {
        return new OwaEvent();
    }

    /*
     * getCampaignProperties() WAS HERE and had no caller left.
     *
     * The tracker does not read owa_* tags off the URL at all any more. It
     * sends nothing of the kind: taggedValue() parses the tags out of page_location
     * server-side -- where a corrected rule reaches data already collected,
     * which the browser cannot do. The parse survived only because the
     * attribution models called it, and they are gone for the same reason:
     * nothing they computed ever reached the server.
     */










    /**
     * Record what the session arrived from.
     *
     * THE CLIENT NO LONGER ATTRIBUTES ANYTHING. This loaded a campaign stack
     * out of the `c` cookie, parsed the URL's owa_* tags, ran one of two
     * attribution models over them and wrote the stack back -- and none of it
     * reached the server. NO tracker generation ever put the tags on the wire:
     * the server parses the tags out of the landing beacon's own page_location
     * in taggedColumns(), where a corrected rule can reach data already
     * collected.
     *
     * So the models, the stack, the cookie, maxPriorCampaigns and
     * trafficAttributionMode were a browser deciding an answer nobody read.
     * What remains is the one thing that does ride the beacon and that the
     * server cannot derive: the referrer this session arrived on.
     */
    setTrafficAttribution( event, callback ) {


        /*
         * The session's referrer is recorded whether or not a campaign was
         * attributed.
         *
         * This used to sit in an `else`, so a landing page carrying campaign
         * tags recorded no referrer at all. That was right while the BROWSER
         * decided attribution: campaign beat referrer, so the referrer was not
         * part of the answer and dropping it lost nothing anyone read.
         *
         * Since #812 the server resolves instead, and it wants both for
         * different jobs -- resolveSource()/resolveMedium() read the tagged_*
         * params first and fall back to the referrer, while the referrer itself
         * is what fills owa_referer.url, decides is_searchengine, and puts the
         * session in the referring-sites report. The gate was a client-side
         * arbitration with nothing left to arbitrate: the server already decides
         * precedence, so recording the referrer cannot override the campaign.
         *
         * It bit hardest on the hit that mattered most. isTrafficAttributed is
         * set only when a NEW campaign is seen, so the first hit of a campaign
         * touch -- the one whose referrer identifies where the campaign was
         * clicked -- was the one that lost it, while later hits on the same
         * campaign fell through and recorded it.
         *
         * The new-session guard stays, and not merely because document.referrer
         * on a later page is an internal URL. session_referer is DECLARED
         * `scope: 'session'` on trackingProperties, and a session-scoped
         * property must be identical on every event sharing a session_id --
         * a divergence is a regression whatever causes it. Writing this key
         * again mid-session would make a session-scoped value vary within its
         * own session, which is the scope contract broken, not just a wrong
         * value. It is written once and re-sent from session state thereafter.
         */

        // apply traffic attribution realted properties to events
        // all properties should be set in the state store by this point.
        // The campaign keys and the session referer are no longer copied onto
        // globals here. They were already being READ out of 's' at this point --
        // the loop that stood here did nothing but move them into a
        // tracker-private cache -- so collectStateProperties() reads the same
        // values from the same place, for every event and every tracker.



        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }
    }




    

    /**
	 * DEPRICATED. Functionality moved to server side.
	 */
    addOrganicSearchEngine( domain, query_param, prepend) {

        return;
    }

    addTransaction( order_id, order_source, total, tax, shipping, gateway, city, state, country ) {
	    
        this.ecommerce_transaction = new OwaEvent();
        this.ecommerce_transaction.setEventType( 'purchase' );
        this.ecommerce_transaction.set( 'ct_order_id', order_id );
        this.ecommerce_transaction.set( 'ct_order_source', order_source );
        this.ecommerce_transaction.set( 'ct_total', total );
        this.ecommerce_transaction.set( 'ct_tax', tax );
        this.ecommerce_transaction.set( 'ct_shipping', shipping );
        this.ecommerce_transaction.set( 'ct_gateway', gateway );
        this.ecommerce_transaction.set( 'page_url', this.getCurrentUrl() );

        /*
         * THE BILLING ADDRESS IS NOT COLLECTED.
         *
         * city, state and country are still accepted as arguments, because this
         * is a public API called positionally and dropping three parameters
         * would shift `gateway` under `city` in every integration that passes
         * them. They are discarded here instead.
         *
         * Not collected because nothing reports on them: a billing address is
         * not a reporting dimension, and v2's country
         * and city are the geolocation readings from the observed IP. The two
         * facts used to share three names, so a transaction's billing address
         * silently replaced the visitor's location -- and only on transactions.
         */

        OWA.debug('setting up ecommerce transaction');

        this.ecommerce_transaction.set( 'ct_line_items', [] );
        OWA.debug('completed setting up ecommerce transaction');
    }

    /**
     * Add an item to the transaction addTransaction() opened.
     *
     * Stored in the same shape trackPurchase() takes -- item_id, item_name,
     * item_category, price, quantity -- so every purchase's items read alike.
     *
     * REFUSED WITHOUT A TRANSACTION. It used to open one called 'none set', so an
     * item added out of order became a purchase of its own with no order id,
     * total or currency.
     */
    addTransactionLineItem( order_id, sku, product_name, category, unit_price, quantity ) {

        if ( ! this.ecommerce_transaction ) {

            OWA.debug( 'addTransactionLineItem: no transaction is open; call addTransaction() first.' );

            return false;
        }

        var items = this.ecommerce_transaction.get( 'ct_line_items' );

        items.push( OWATracker.purchaseItem( {
            item_id:       sku,
            item_name:     product_name,
            item_category: category,
            price:         unit_price,
            quantity:      quantity
        } ) );

        this.ecommerce_transaction.set( 'ct_line_items', items );

        return true;
    }

    /**
     * Record a purchase in one call.
     *
     *   owa_cmds.push( [ 'trackPurchase', {
     *       transaction_id: 'T-1001',
     *       value:    59.98,         // the items: tax and shipping NOT included
     *       currency: 'USD',
     *       tax:      4.90,
     *       shipping: 5.99,
     *       coupon:   'SPRING',
     *       affiliation: 'Web store',
     *       items: [ { item_id: 'SKU-1', item_name: 'Blue mug', price: 19.99, quantity: 2 } ]
     *   } ] );
     *
     * Amounts in MAJOR units, as a price is written; the server converts by the
     * currency's decimal places. A transaction_id is required: it is what keeps
     * a reloaded receipt page from recording the purchase twice.
     *
     * @param {Object} purchase
     * @return {boolean} false when refused
     */
    trackPurchase( purchase ) {

        var p = purchase || {};
        var id = p.transaction_id === undefined || p.transaction_id === null
            ? '' : String( p.transaction_id ).trim();

        if ( id === '' ) {

            OWA.debug( 'trackPurchase: a purchase needs a transaction_id.' );

            return false;
        }

        var event = new OwaEvent();
        event.setEventType( 'purchase' );
        event.set( 'ct_order_id', id );
        event.set( 'page_url', this.getCurrentUrl() );

        var money = { ct_value: p.value, ct_tax: p.tax, ct_shipping: p.shipping };

        for ( var key in money ) {

            var amount = OWATracker.amount( money[ key ] );

            if ( amount !== null ) {

                event.set( key, amount );
            }
        }

        if ( p.currency ) {

            event.set( 'currency', String( p.currency ).trim().toUpperCase() );
        }

        if ( p.affiliation ) {

            event.set( 'ct_order_source', String( p.affiliation ) );
        }

        if ( p.coupon ) {

            event.set( 'coupon', String( p.coupon ) );
        }

        var items = [];

        for ( var i = 0; Array.isArray( p.items ) && i < p.items.length; i++ ) {

            var item = OWATracker.purchaseItem( p.items[ i ] );

            if ( item ) {

                items.push( item );
            }
        }

        event.set( 'ct_line_items', items );

        this.trackEvent( event );

        return true;
    }

    /**
     * Record a refund of a purchase, whole or in part.
     *
     *   owa_cmds.push( [ 'trackRefund', { transaction_id: 'T-1001' } ] );             // all of it
     *   owa_cmds.push( [ 'trackRefund', { transaction_id: 'T-1001', value: 19.99 } ] );
     *   owa_cmds.push( [ 'trackRefund', { transaction_id: 'T-1001',
     *       items: [ { item_id: 'SKU-1', price: 19.99, quantity: 1 } ] } ] );
     *
     * The amount refunded is the value when one is given, else the items' price
     * times quantity, else -- the transaction id alone -- the whole purchase,
     * which the server looks up. A transaction_id is required: a refund is of
     * something.
     *
     * @param {Object} refund
     * @return {boolean} false when refused
     */
    trackRefund( refund ) {

        var r = refund || {};
        var id = r.transaction_id === undefined || r.transaction_id === null
            ? '' : String( r.transaction_id ).trim();

        if ( id === '' ) {

            OWA.debug( 'trackRefund: a refund needs the transaction_id of the purchase.' );

            return false;
        }

        var event = new OwaEvent();
        event.setEventType( 'refund' );
        event.set( 'ct_order_id', id );
        event.set( 'page_url', this.getCurrentUrl() );

        var money = { ct_value: r.value, ct_tax: r.tax, ct_shipping: r.shipping };

        for ( var key in money ) {

            var amount = OWATracker.amount( money[ key ] );

            if ( amount !== null ) {

                event.set( key, amount );
            }
        }

        if ( r.currency ) {

            event.set( 'currency', String( r.currency ).trim().toUpperCase() );
        }

        var items = [];

        for ( var i = 0; Array.isArray( r.items ) && i < r.items.length; i++ ) {

            var item = OWATracker.purchaseItem( r.items[ i ] );

            if ( item ) {

                items.push( item );
            }
        }

        if ( items.length ) {

            event.set( 'ct_line_items', items );
        }

        this.trackEvent( event );

        return true;
    }

    /**
     * One item, in the shape every purchase stores: the known fields only, and
     * numbers as numbers. NULL for an item naming neither an id nor a name,
     * which identifies nothing.
     *
     * @param {Object} item
     * @return {Object|null}
     */
    static purchaseItem( item ) {

        if ( ! item || typeof item !== 'object' ) {

            return null;
        }

        var out = {};

        [ 'item_id', 'item_name', 'item_category', 'item_brand', 'item_variant', 'coupon' ]
            .forEach( function ( key ) {

                if ( item[ key ] !== undefined && item[ key ] !== null && String( item[ key ] ) !== '' ) {

                    out[ key ] = String( item[ key ] );
                }
            } );

        [ 'price', 'quantity', 'discount' ].forEach( function ( key ) {

            var n = OWATracker.amount( item[ key ] );

            if ( n !== null ) {

                out[ key ] = n;
            }
        } );

        return ( out.item_id || out.item_name ) ? out : null;
    }

    /** A finite number, or null. */
    static amount( value ) {

        if ( value === undefined || value === null || value === '' ) {

            return null;
        }

        var n = Number( value );

        return isFinite( n ) ? n : null;
    }

    trackTransaction() {

        if ( this.ecommerce_transaction ) {
            this.trackEvent( this.ecommerce_transaction );
            this.ecommerce_transaction = '';
        }
    }

    /**
     * How many sessions this visitor had BEFORE this one.
     *
     * Counts from zero, which is the whole difficulty: a visitor's first
     * session stores 0, so "never counted" and "counted once" are not
     * distinguishable by truthiness. This used to write the STRING "0" the
     * first time and a NUMBER every time after, and read it back with
     * `! nps` -- which worked only because "0" is truthy in JavaScript while
     * 0 is not. The value's type was carrying the distinction, and anything
     * that normalised the store -- a JSON round-trip, a store that coerces
     * numeric-looking strings -- would have turned the first session's 0 back
     * into "absent" and reset the count on every visit. Every session would
     * then report prior_sessions = 0, and newVsReturning would read New
     * forever, with nothing anywhere saying so.
     *
     * Absence is now tested for directly and the value is a number both ways.
     * A store still holding the old "0" reads as seen and increments to 1,
     * which is the right answer for it.
     *
     * Reading 0 back out is safe on the wire: collectStateProperties() omits a
     * property only when it is undefined or '', never when it is falsy.
     */
    setNumberPriorSessions( event, callback ) {

        OWA.debug('setting number of prior sessions');

        var store = this.storeName( 'v' );
        var nps   = OWA.getState( store, 'nps' );

        if ( this.isNewSessionFlag ) {

            var counted = nps !== undefined && nps !== null && nps !== ''
                       && ! isNaN( nps * 1 );

            nps = counted ? ( nps * 1 ) + 1 : 0;

            OWA.setState( store, 'nps', nps, true );
        }

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }
    }

    setVisitorId( event, callback ) {

        var visitor_id =  OWA.getState( 'v', 'vid' );
        //OWA.debug('vid: '+ visitor_id);
        if ( ! visitor_id ) {
            var old_vid_test =  OWA.getState( 'v' );
            //OWA.debug('vid: '+ visitor_id);

            if ( ! Util.is_object( old_vid_test ) ) {
                visitor_id = old_vid_test;
                OWA.clearState( 'v' );
                OWA.setState( 'v', 'vid', visitor_id, true );

            }
        }

        if ( ! visitor_id ) {
            visitor_id = Util.generateRandomGuid();

            this.pendingVisitorCreated = true;
            OWA.debug('Creating new visitor id');
        }
        // set property on event object
        OWA.setState( 'v', 'vid', visitor_id, true );

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }
    }

    setFirstSessionTimestamp( event, callback ) {

        // set first session timestamp
        var fsts = OWA.getState( 'v', 'fsts' );
        if ( ! fsts ) {
            fsts = event.get('timestamp');
            OWA.debug('setting fsts value: %s', fsts);
            OWA.setState('v', 'fsts', fsts , true);
        }


        // calc days since first session
        /*
         * The DELTA is not computed here. This runs on every page load, so
         * computing it here re-exposed the visitor's clock every page and made
         * the value change mid-session. It is computed once per session, at the
         * boundary, alongside the other one -- see setSessionId().
         */

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }
    }

    setLastRequestTime( event, callback ) {

        /*
         * Memory first, then the cookie.
         *
         * The cookie is the usual source: this runs BEFORE the session
         * decision, and the session store is not hydrated until that decision
         * is made, so a previous page's last_req is only in the cookie. Reading
         * it directly is safe because a read merges nothing -- the invariant is
         * that nothing MERGES into memory before the decision, not that nothing
         * reads.
         *
         * But memory takes precedence when it has one, and that is not a
         * fallback ordering, it is the point: memory holds what THIS page load
         * set. A second tracker on the same page (two site ids, sharing the
         * state stores) finds the first tracker's last_req there and correctly
         * continues its session. Reading only the cookie made it miss -- the
         * first tracker's value has not been persisted yet, since that waits on
         * a beacon being accepted -- so it declared a NEW session, minted a
         * second sid for one page view, and overwrote the first tracker's sid
         * in the shared store.
         */
        var last_req = OWA.getState( this.storeName('s'), 'last_req') || OWA.getPersistedState( this.storeName('s'), 'last_req');
        OWA.debug('last_req from cookie: %s', last_req);
        // suppport for old style cookie
        if ( ! last_req ) {
            var state_store_name = 'ss_' + this.siteId;
            last_req = OWA.getState( state_store_name, 'last_req' );
        }

        // set property on for all events
        OWA.debug('setting prior last_req of %s', last_req);

        /*
         * Stored under its OWN key. 's.last_req' is about to be advanced to
         * this event's timestamp, so the prior value needs somewhere else to
         * live -- the session row keeps the same pair apart the same way, as
         * last_req and prior_session_lastreq.
         *
         * Written only if this page load has not established it already. The
         * session store is not hydrated at this point, so a value in memory
         * here can only have been put there by ANOTHER TRACKER on this page,
         * which has already advanced s.last_req to now -- without the guard the
         * second tracker would overwrite the true prior with now, and the first
         * tracker's later events would then report that.
         */
        var session_store = OWA.getState( this.storeName('s') );
        var already_established = session_store
            && typeof session_store === 'object'
            && session_store.hasOwnProperty( 'prior_last_req' );

        if ( ! already_established ) {
            OWA.setState( this.storeName('s'), 'prior_last_req', last_req );
        }

        // The advance itself is NOT here: it happens for every event, in
        // manageState(). This runs only on the first event of a page load, and
        // its job is to capture the prior value before anything moves.

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }
    }

    setSessionId( event, callback ) {
	    
        var session_id = '';
        var state_store_name = '';
        var previous_request = OWA.getState( this.storeName('s'), 'last_req' )
            || OWA.getPersistedState( this.storeName('s'), 'last_req' );

        var is_new_session = this.isNewSession( event.get( 'timestamp' ), previous_request );

        if ( is_new_session ) {

            /*
             * How long since the previous session, measured HERE and sent as a
             * duration.
             *
             * Both stamps come from this browser's clock, moments or days apart
             * but from one clock, so any skew cancels and what is sent is a
             * real elapsed time. The server used to compute this itself as
             * `session.timestamp - event.last_req` -- its own clock minus the
             * browser's -- which is wrong by the visitor's skew and can come out
             * NEGATIVE. It feeds the 'Time Since Last Visit' dimension
             * (timeSinceLastVisit / time_sinse_priorsession), so that was the
             * one prior-session value anybody reports on.
             *
             * A duration rather than a timestamp on purpose. It makes no claim
             * about whose clock it is, so nothing downstream has to know; and
             * it is computed once here and carried, so a queued event replayed
             * later reuses the same value instead of recomputing against a
             * different "now".
             *
             * Session state: how long this session waited for its predecessor
             * stays true for as long as it lasts.
             */
            var now = event.get( 'timestamp' ) || this.getTimestamp();

            /*
             * The DATE of the session that just ended, taken from its stored
             * start time before the boundary discards it -- the same move as
             * prior_session_id just below, and read for the same reason.
             *
             * A date, like first_session_date, and coarse for the same reason:
             * the anchor is stamped by the visitor's clock, so a date absorbs
             * anything short of a midnight-crossing error. The server counts
             * days from it.
             *
             * This replaces sending an interval in seconds. That interval fed
             * timeSinceLastVisit, which was never really a dimension -- a
             * continuous seconds value gives one bucket per distinct second, so
             * it was a metric wearing a dimension's clothes. Days bucket;
             * seconds do not.
             */
            var prior_session_start = OWA.getPersistedState( this.storeName('s'), 'sts' );

            if ( prior_session_start ) {

                OWA.setState( this.storeName('s'), 'psts', prior_session_start );
            }


            /*
             * This session's start is what the day counts are measured TO.
             * Fixed for the session, so days_since_first_session and
             * days_since_prior_session are identical on every event sharing this
             * session_id -- measuring to the EVENT's time instead would tick
             * them over at midnight part way through a visit, and both
             * dimensions are family 'visit'.
             */
            OWA.setState( this.storeName('s'), 'sts', now );


            // Persisted read, for the same reason as last_req above: the id of
            // the session that just ended was written by a previous page load,
            // so it is in the cookie and not in memory.
            var prior_session_id = OWA.getPersistedState( this.storeName('s'), 'sid');
            if ( ! prior_session_id ) {
                state_store_name = 'ss_' + this.getSiteId();
                prior_session_id = OWA.getState(state_store_name, 's');
            }
            if ( prior_session_id ) {

                /*
                 * Session state, not a fact about this request: it names the
                 * session THIS one succeeded, which stays true for as long as
                 * this session lasts. So it is also still reported on later
                 * pages of the session, hydrated back out of the cookie --
                 * where it was only ever on the page load that crossed the
                 * boundary before.
                 *
                 * What matters for ordering is the READ above, not this write:
                 * the value comes out of a cookie that the announcement below
                 * is about to erase. The write can go either side, because
                 * discardPersisted() only erases the cookie and never touches
                 * memory.
                 */
                OWA.setState( this.storeName('s'), 'prior_session_id', prior_session_id );
            }
        }

        /*
         * Announce the decision. The state manager listens and settles the
         * session store on the strength of it: a new session means the
         * persisted values described a session that has ended, so they are
         * discarded and memory -- holding only what THIS page load set -- is
         * kept; a continuing session means they are still current, so they are
         * merged in behind what this page load set.
         *
         * This is what replaced resetSessionState(). That method had to clear
         * the store and then put back the values set during this page load,
         * because by the time it ran both were already mixed together in one
         * store and nothing distinguished them. Keeping them apart until here
         * removes the problem rather than compensating for it.
         *
         * Fired rather than called directly so that moving the moment of
         * sessionization -- today it happens only because trackPageView() ran
         * -- does not mean rewriting the state manager.
         */
        OWA.doAction( 'isSessionizationDone', {
            'is_new_session': is_new_session,
            /*
             * The storage instruction, and deliberately not the same field.
             * A store waiting on this action needs to be told whether its
             * persisted values still apply; it does not need to know they are
             * sessions. Keeping the two apart is what lets another store hook
             * its hydration to some entirely different decision and be settled
             * by the same machinery.
             */
            'discard': is_new_session
        } );

        if ( is_new_session ) {

            session_id = Util.generateRandomGuid();
            // it's a new session. generate new session ID
               //mark new session flag on current request
            this.pendingSessionStart = true;
            this.isNewSessionFlag = true;
            OWA.setState( this.storeName('s'), 'sid', session_id, true );
            
        } else {
	        
            // Must be an active session so just pull the session id from the state store
            session_id = OWA.getState( this.storeName('s'), 'sid');
            // support for old style cookie
            if ( ! session_id ) {
                state_store_name = 'ss_' + this.getSiteId();
                session_id = OWA.getState(state_store_name, 's');
                OWA.setState( this.storeName('s'), 'sid', session_id, true );
            }
        }

        // Fail-safe just in case there is no session_id. Checks the local
        // rather than a global event property: the id is state, and the two
        // branches above are what decide whether there is one.
        if ( ! session_id ) {
            session_id = Util.generateRandomGuid();
            //mark new session flag on current request
            this.pendingSessionStart = true;
            this.isNewSessionFlag = true;
            OWA.setState( this.storeName('s'), 'sid', session_id, true );
        }

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }

    }

    isNewSession( timestamp, last_req ) {

        var is_new_session = false;

        if ( ! timestamp ) {
            timestamp = Util.getCurrentUnixTimestamp();
        }

        if ( ! last_req ) {
            last_req = 0;
        }

        var time_since_lastreq = timestamp - last_req;
        var len = this.options.sessionLength;
        if ( time_since_lastreq < len ) {
            OWA.debug("This request is part of a active session.");
            return false;
        } else {
            //NEW SESSION. prev session expired, because no requests since some time.
            OWA.debug("This request is the start of a new session. Prior session expired.");
            return true;
        }
    }

    getGlobalEventProperty( name ) {

        if ( this.globalEventProperties.hasOwnProperty(name) ) {

            return this.globalEventProperties[name];
        }
    }

    setGlobalEventProperty(name, value) {

        this.globalEventProperties[name] = value;
    }

    deleteGlobalEventProperty( name ) {

        if ( this.globalEventProperties.hasOwnProperty( name ) ) {

            delete this.globalEventProperties[name];
        }
    }

    /**
     * The wire prefixes that carry scope.
     *
     * Scope lives in the NAME, at every layer -- the beacon, the store and
     * eventually the registered dimension -- so nothing downstream has to infer
     * which bag a value belongs to, and the same name in two scopes is two
     * different things all the way down. Underscores rather than dots because
     * OWA's own params are read as bare keys.
     */
    static get EVENT_PROPERTY_PREFIX() { return 'ep_'; }
    static get USER_PROPERTY_PREFIX()  { return 'up_'; }

    /**
     * And the numeric halves, `epn_` and `upn_`.
     *
     * THE TYPE IS IN THE NAME for the same reason the scope is: a query string
     * has no numbers, so without a prefix every value arrives as text and
     * `params` stores "42" where the site set 42. Nothing downstream can tell
     * that from a string that merely looks numeric -- a version, a postcode,
     * an order id with leading zeros -- so guessing at the far end is worse
     * than being told at this one.
     */
    static get EVENT_PROPERTY_NUMBER_PREFIX() { return 'epn_'; }
    static get USER_PROPERTY_NUMBER_PREFIX()  { return 'upn_'; }

    /*
     * THERE IS NO CAP HERE, deliberately. How many custom properties an event
     * may carry is enforced at INGEST, because the tracker is not the only
     * thing that can post to the endpoint and a limit only this file honours
     * is a limit only well-behaved callers meet. It was implemented in both
     * places first, which is worse than either: two numbers that can drift,
     * and a client-side one that reads like a guarantee while guaranteeing
     * nothing.
     */


    /** Names must survive becoming a JSON key and then a column. */
    static get PROPERTY_NAME_PATTERN() { return /^[A-Za-z][A-Za-z0-9_]{0,39}$/; }

    /**
     * A custom value describing THIS event.
     *
     * Rides every event this tracker sends for the life of the page, and lands
     * in `params` on the raw row. The server never has to guess the scope: the
     * `ep_` prefix says it.
     *
     * Page-lifetime and in memory -- nothing is written to a cookie, so a value
     * set here cannot outlive its own meaning
     * the way v1's persisted custom variables could.
     *
     * @param  name   string  letters, digits and underscores; must start with a letter
     * @param  value  string
     */
    setEventProperty( name, value ) {

        if ( ! OWATracker.PROPERTY_NAME_PATTERN.test( String( name ) ) ) {

            OWA.debug( 'Event property name must start with a letter and contain only letters, digits and underscores (max 40).' );

            return;
        }

        /*
         * A JS number goes to the numeric prefix, `epn_`. NaN and Infinity are
         * NOT numbers here: neither survives
         * JSON, so both would arrive as null and read as absence.
         */
        var numeric = typeof value === 'number' && isFinite( value );

        var key = ( numeric ? OWATracker.EVENT_PROPERTY_NUMBER_PREFIX
                            : OWATracker.EVENT_PROPERTY_PREFIX ) + name;

        this.setGlobalEventProperty( key, numeric ? value : String( value ) );
    }

    /**
     * A custom value describing the VISITOR.
     *
     * Also page-lifetime on the client, and deliberately so: held in memory for
     * the page, stamped on each event, and persisted server side against the
     * user. Set one, navigate, and the next page's beacons carry nothing until it
     * is set again.
     *
     * The `up_` prefix routes it to the visitor store at
     * ingest, where it is written last-value-wins with the event's timestamp,
     * so what persists is a server record rather than a cookie that can outlive
     * the value it holds.
     *
     * @param  name   string
     * @param  value  string
     */
    setUserProperty( name, value ) {

        if ( ! OWATracker.PROPERTY_NAME_PATTERN.test( String( name ) ) ) {

            OWA.debug( 'User property name must start with a letter and contain only letters, digits and underscores (max 40).' );

            return;
        }

        /*
         * A JS number goes to the numeric prefix, `upn_`. NaN and Infinity are
         * NOT numbers here: neither survives
         * JSON, so both would arrive as null and read as absence.
         */
        var numeric = typeof value === 'number' && isFinite( value );

        var key = ( numeric ? OWATracker.USER_PROPERTY_NUMBER_PREFIX
                            : OWATracker.USER_PROPERTY_PREFIX ) + name;

        this.setGlobalEventProperty( key, numeric ? value : String( value ) );
    }

    /**
     * Set a custom variable
     *
     * @deprecated Use setEventProperty() or setUserProperty().
     *
     * THREE SCOPES BECOME TWO, and the slot number goes.
     *
     *   page, session, anything else  ->  setEventProperty()
     *   visitor                       ->  setUserProperty()
     *
     * SESSION SCOPE MAPS TO EVENT, which is the one that looks like a loss and
     * is not. A session-scoped value carried by the CLIENT is exactly what the
     * v2 design refuses: it is something the server can derive from the
     * session's own events, and something the client can get wrong -- v1's own
     * session store had a variable outliving the session that set it, because
     * nothing cleared it at a session boundary. So site authors get event and
     * user scope, and session scope is derived.
     *
     * The slot is ignored. It was v1 storage -- five numbered columns on a fact
     * table -- and never information; two calls with the same name now mean the
     * same property rather than colliding on a slot.
     *
     * @param    slot    int        ignored; kept so existing calls still parse
     * @param    name    string    the key of the custom variable.
     * @param    value    string    the value of the varible
     * @param    scope    string    the scope of the variable. can be page, session, or visitor
     */
    setCustomVar(slot, name, value, scope) {

        if ( scope === 'visitor' ) {

            this.setUserProperty( name, value );

            return;
        }

        this.setEventProperty( name, value );
    }

    /**
     * The custom variables that apply to an event, read from the state stores.
     *
     * Applied WIDEST SCOPE FIRST -- visitor, then session, then page -- so a
     * narrower scope overwrites a wider one and page scope always wins. That
     * ordering IS the scope precedence; nothing else enforces it.
     *
     * Collected fresh for every event rather than cached as a global event
     * property, which is what setCustomVar() used to do. A cached copy is a
     * second source of truth and behaves like one: it goes stale when a slot is
     * re-scoped, and it hides the stores from any reader that is not the
     * tracker that made the call -- so a second tracker on the same page saw
     * different values, and the page store's contribution was unobservable.
     *
     * Bounded by maxCustomVars, matching the rehydration loop this replaced.
     */
    /**
     * Page-scoped properties for this event, read from the 'd' store.
     *
     * Anything put in 'd' rides the page's events, so a page-scoped property
     * added later needs no plumbing here. Two keys are excluded: custom
     * variables, which span three stores and are collected with their own
     * precedence, and the state manager's own
     * bookkeeping -- 'cdh', the cookie domain hash, and 'sv', the format
     * version (StateManager.VERSION_KEY). Both describe the STORE; neither is
     * a tracking property, and sending either would put a private
     * implementation detail in the fact table under its own dimension name.
     */
    /**
     * Properties that are COPIES of state, read from the stores for this event.
     *
     * Each of these was derived once during manageState() and then cached as a
     * global event property on the tracker. The cache was never wrong -- every
     * branch that set it also wrote the same value to the store -- but it was a
     * second copy, private to one tracker, of something the stores already hold
     * and every tracker on the page shares. Reading it here is the same value
     * from the one place that owns it.
     *
     * Names differ from store keys for three of them, which is why this is a
     * map rather than a loop over key names.
     */
    collectStateProperties() {

        var collected = {};
        var map = [
            { store: 'v', key: 'vid',  name: 'visitor_id' },
            // The site's own id for the person, when they have identified
            // themselves. Visitor-scoped, so it rides every beacon from the
            // moment setUserId() is called and not before.
            { store: 'v', key: 'user_id', name: 'user_id' },
            { store: 'v', key: 'nps',  name: 'nps' },
            { store: 's', key: 'sid',     name: 'session_id' },
            { store: 's', key: 'prior_session_id', name: 'prior_session_id' },
            { store: 's', key: 'psts', name: 'psts' },
            { store: 's', key: 'sts',  name: 'sts' }
        ];

        for ( var i = 0; i < map.length; i++ ) {

            // resolve through storeName(): the map names stores LOGICALLY, and
            // a site-scoped one lives under '<name>_<siteId>'
            var value = OWA.getState( this.storeName( map[i].store ), map[i].key );

            // Defined rather than truthy: nps is legitimately 0 on a
            // visitor's first session, and dsfs is 0 on their first day.
            if ( value !== undefined && value !== '' ) {
                collected[ map[i].name ] = value;
            }
        }

        /*
         * last_req is no longer collected onto the event.
         *
         * THE STORE KEY STAYS. `s.last_req` is how the DEVICE decides a session
         * has timed out -- isNewSession() compares it with this request -- and
         * `prior_last_req` still holds the previous session's last hit so the
         * boundary can read it before it is overwritten. Both are state; neither
         * needs to be on the wire.
         *
         * What the SERVER did with it: v1's logSession() wrote
         * prior_session_lastreq and six date parts formatted from it, and
         * visitDuration was AVG(last_req - timestamp). Those handlers are
         * unregistered, and engagement_msec replaced visitDuration because the
         * old one could not see the final page. Nothing on v2 reads it, and the
         * prior session's start arrives as `psts` with a column of its own.
         *
         * It was also the one CLIENT-clock value reaching the schema, subtracted
         * from a SERVER clock -- see project_clock_provenance. Removing it ends
         * that mixing rather than documenting it.
         */


        /*
         * The visitor's first-visit DATE, derived from the stored anchor rather
         * than stored beside it, so the two cannot drift apart.
         *
         * A date, not a timestamp and not an elapsed count. Coarsening is the
         * whole point: the anchor is stamped by the visitor's clock, and a clock
         * wrong by minutes or hours yields the same date -- only an error
         * crossing midnight costs anything, and only ever one day, once.
         *
         * It is also a pure function of a value that never changes, so it is
         * permanent and visitor-scoped: identical on every event this visitor
         * ever sends. The elapsed version it replaces was recomputed on every
         * page load, so the clock was consulted afresh each page and the value
         * could change part way through a session.
         *
         * The server does the day arithmetic against its own calendar -- see
         * TrackingEventHelpers::deriveDaysSinceFirstSession().
         */
        var first_seen = OWA.getState( 'v', 'fsts' );

        if ( first_seen ) {

            collected.fsts = first_seen;
        }

        // Campaign keys are session state too, written into 's' by the
        // attribution model. Their names are configured rather than fixed, so
        // they cannot go in the map above.
        /*
         * The tagged_* keys are no longer collected, because nothing writes
         * them to session state any more -- the server parses page_location
         * instead. The loop that stood here read six keys that are now always
         * absent.
         *
         * Beacons from an OLD tracker still carry them, and the server still
         * prefers what it was sent over what it re-derives, so those installs
         * are unaffected until their cached tracker expires.
         */

        return collected;
    }

    /**
     * Put a one-event flag on this event and clear it, so no later event
     * repeats it.
     *
     * The counterpart to collectPageProperties() / collectStateProperties():
     * those read state that persists, this spends something that does not.
     */
    consumePendingFlag( event, name, property ) {

        if ( ! this[ property ] ) {
            return;
        }

        this[ property ] = false;

        if ( ! event.isSet( name ) ) {
            event.set( name, true );
        }
    }

    collectPageProperties() {

        /*
         * The DOM is the base layer -- what the page actually IS -- and the
         * page store is laid over it, because a site that called setPageTitle()
         * meant it. Stating it in that order puts the precedence in one place.
         * It used to be inverted and split: the override was applied here and
         * the DOM value backfilled afterwards by addDefaultsToEvent(), guarded
         * so it would not overwrite. Same result, read backwards.
         *
         * addDefaultsToEvent() still backfills these. In the normal chain it
         * runs after this and finds them already set, so it is a no-op; it
         * stays because it is also reachable on its own.
         */
        var collected = {
            'page_url':     this.getCurrentUrl(),
            'page_title':   String( document.title ).trim(),
            'HTTP_REFERER': this.getPageReferrer()
        };

        var store = OWA.getState( 'd' );

        if ( ! store || typeof store !== 'object' ) {
            return collected;
        }

        for ( var key in store ) {

            if ( store.hasOwnProperty( key )
                 && key !== 'cdh'
                 && key !== 'sv'
                 && ! /^cv[0-9]+$/.test( key ) ) {

                collected[ key ] = store[ key ];
            }
        }

        return collected;
    }

    /**
     * Applies default values for required properties 
     * to any event where the properties were not
     * already set globally or locally.
     */
    addDefaultsToEvent( event, callback ) {

        event.set( 'site_id', this.getSiteId() );

        if ( ! event.get( 'page_url') && ! this.getGlobalEventProperty('page_url') ) {

            event.set('page_url', this.getCurrentUrl() );
        }

        if ( ! event.get( 'HTTP_REFERER') && ! this.getGlobalEventProperty('HTTP_REFERER')) {

            event.set('HTTP_REFERER', this.getPageReferrer() );
        }

        if ( ! event.get( 'screen_resolution' ) ) {

            var resolution = this.getScreenResolution();

            if ( resolution ) {

                event.set( 'screen_resolution', resolution );
            }
        }

        if ( ! event.get( 'page_title') && ! this.getGlobalEventProperty('page_title') ) {

            event.set('page_title', String( document.title ).trim() );
        }

        if ( ! event.get( 'timestamp') ) {

            event.set('timestamp', this.getTimestamp() );
        }

        /*
         * The COMPLETE URL, query and all.
         *
         * page_url is the one the server canonicalises -- it strips the
         * campaign parameters and whatever the site put in query_string_filters
         * -- which is right for v1, where page_url IS the page's identity.
         * page_location is the evidence the campaign tags are parsed back out
         * of, and a URL whose query has already been removed cannot answer that
         * a second time. Sent rather than reconstructed, because by the time a
         * handler runs the only copy left is the filtered one.
         */
        if ( ! event.get( 'page_location' ) ) {

            event.set( 'page_location', event.get( 'page_url' ) || this.getCurrentUrl() );
        }

        /*
         * client_ts_usec was stamped here and is not sent any more.
         *
         * It existed for ONE column: the server subtracted it from its own
         * receipt time into owa_event_raw.clock_offset_usec, so skew would be a
         * stored number rather than a silent error. Update046 dropped that
         * column -- ordering is settled by event_seq, a counter that needs no
         * clock -- and the field went on being sent for every beacon afterwards,
         * read by nothing on either side. The tracker never read it back either.
         *
         * If skew is wanted again it needs the column back, and then this line;
         * sending the number to nowhere is not a step toward that.
         */

        /*
         * ENGAGEMENT RIDES EVERY EVENT, as a delta.
         *
         * Time accrued on this page since the last report, consumed as it is
         * read so the same milliseconds cannot ride two beacons. Losing a
         * beacon therefore costs one increment rather than one page -- where a
         * cumulative total would make the last beacon of the page the only one
         * that mattered, and that is the one most likely to be lost.
         *
         * Not set on user_engagement, which carries its own residue and has
         * already consumed it.
         */
        if ( ! event.isSet( 'engagement_msec' ) ) {

            var delta = this.consumeEngagementDelta();

            if ( delta > 0 ) {

                event.set( 'engagement_msec', delta );
            }
        }


           if (callback && ( typeof( callback ) == 'function' ) ) {

               callback( event );
           }

    }

    /**
     * Applies global properties to any event that 
     * were not already set locally by the method that
     * created the event.
     *
     */
    addGlobalPropertiesToEvent( event, callback ) {

        OWA.debug( 'Adding global properties to event: %s', JSON.stringify(this.globalEventProperties) );
        for ( var prop in this.globalEventProperties ) {

            // only set global properties is they are not already set on the event
            if ( this.globalEventProperties.hasOwnProperty( prop )
                 && ! event.isSet( prop ) )
            {
                event.set( prop, this.globalEventProperties[prop] );
            }
        }

        /*
         * Properties read from the state stores for this event. After the
         * globals loop so that anything set directly with
         * setGlobalEventProperty() keeps the precedence it had, and guarded the
         * same way so a value already on the event still wins over both.
         *
         * Page properties before custom vars only for readability; the two sets
         * of keys are disjoint by construction.
         */
        /*
         * Consumed rather than collected: these belong to ONE event, and taking
         * them off the tracker as they are applied is what makes that true.
         */
        this.consumePendingFlag( event, 'is_new_session_start', 'pendingSessionStart' );
        this.consumePendingFlag( event, 'is_new_visitor_created', 'pendingVisitorCreated' );

        var collected = this.collectPageProperties();
        var state_properties = this.collectStateProperties();

        for ( var sp_name in state_properties ) {
            if ( state_properties.hasOwnProperty( sp_name ) ) {
                collected[ sp_name ] = state_properties[ sp_name ];
            }
        }

        /*
         * Custom values are global event properties now -- `ep_` and `up_` --
         * so they are already on the event by the time this runs and need no
         * collection pass. The cv1..cvN stores they replaced were read from
         * three places with a shadowing order, which is the machinery the two
         * prefixes remove.
         */

        /*
         * user_name is no longer collected from the visitor store.
         *
         * setUserName() writes the PAGE store under the up_ prefix, so
         * collectPageProperties() has already put it on the event as up_user_name
         * by the time this runs -- the same shape every other custom user property
         * arrives in. Reading it back out of a cookie here is what made it
         * visitor-scoped transport for a value that is page-scoped and temporal.
         */

        for ( var name in collected ) {

            if ( collected.hasOwnProperty( name ) && ! event.isSet( name ) ) {
                event.set( name, collected[ name ] );
            }
        }

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }

    }

    manageState( event, callback ) {

        var that = this;
        if ( ! this.stateInit ) {

            /*
             * Session identity is derived here and lands in MEMORY only. It
             * reaches the cookie when a request carrying it is accepted -- see
             * sendAccepted() -- so every event on this page reads the same
             * session while none of it is asserted on disk undelivered.
             *
             * Nothing needs arming for that: the session store is withheld by
             * default and released by the acceptance, rather than a flag being
             * raised here and lowered again later.
             */
            this.setVisitorId( event, function(event) {

                that.setFirstSessionTimestamp( event, function( event ) {

                    /*
                     * Sessionization BEFORE the last-request advance. The
                     * decision is made against s.last_req, which still holds
                     * the previous request at this point; setLastRequestTime()
                     * then captures that value for the wire and advances the
                     * store to now.
                     *
                     * The other order needed the prior value promoted onto the
                     * tracker so the decision could still see it after the
                     * store had been overwritten -- which is what a global
                     * event property was doing here, and why the second tracker
                     * on a page saw a different one.
                     */
                    that.setSessionId( event, function( event ) {

                        that.setLastRequestTime( event, function( event ) {

                            that.setNumberPriorSessions( event, function( event ) {

                                that.setTrafficAttribution( event, function( event ) {

                                    that.stateInit = true;

                                });
                            });
                        });
                    });
                });
            });
        }

        /*
         * Advance the session's last-request time for EVERY event, not just the
         * first of the page.
         *
         * It used to sit inside the block above, which runs once per tracker
         * under the stateInit guard -- so last_req was the timestamp of the
         * page's FIRST tracked event and never moved after that. The timeout
         * was therefore measured from when the previous page started rather
         * than from the last thing the visitor did: someone active for 25
         * minutes on one page who navigated 10 minutes later got a new session
         * at 35 minutes from page start, despite having been active 10 minutes
         * ago.
         *
         * isNewSession() already reads as though this were the case -- its
         * variable is time_since_lastreq and its own comment says "prev session
         * expired, because no requests since some time" -- and sessionLength
         * means an inactivity window. This makes the value match the name.
         *
         * Placed after the identity block so the first event of a page still
         * decides sessionization against the PREVIOUS request before this one
         * overwrites it.
         */
        this.advanceLastRequestTime( event );

        // Per event, and for the same reason: a page's events must not share a
        // position any more than they share a last-request time.
        this.stampEventSequence( event );

        // Which beacon format this is. Every event, because a row is what the
        // question gets asked of, not a session.
        event.set( 'beacon_version', OWATracker.BEACON_FORMAT_VERSION );

        if (callback && ( typeof( callback ) === "function" ) ) {
            callback( event );
        }
    }

    /**
     * Move the session's last-request time up to this event.
     *
     * Falls back to the tracker's clock because addDefaultsToEvent(), which
     * stamps a missing timestamp, runs after manageState() -- an event that
     * arrived without one would otherwise write undefined into the store and
     * break the next page's session decision.
     */
    advanceLastRequestTime( event ) {

        OWA.setState( this.storeName('s'), 'last_req', event.get( 'timestamp' ) || this.getTimestamp(), true );
    }

    /**
     * The event's position in its session, counted on the DEVICE.
     *
     * WHY A COUNTER AND NOT A TIME. The server stamps `ts` at edge receipt --
     * it is environmental, so a request cannot set it and a queue drain cannot
     * restamp it -- and the cube's window sorts a session on that. So events
     * order by ARRIVAL, and a beacon that lands late sorts after ones that
     * happened after it. The unload beacon is the standing example: it puts
     * is_exit on the wrong event and mis-orders any funnel spanning it.
     *
     * A client TIMESTAMP would not fix that. A device clock can be wrong,
     * skewed, or set by hand, and two events a second apart can carry times in
     * the wrong order. A counter is monotonic whatever the clock says, which is
     * the only property the sort actually needs.
     *
     * STAMPED AT CREATION, NOT AT SEND. This runs on the event as it is built,
     * so a beacon that is deferred, queued or retried carries the number it had
     * when it happened. Incrementing at transport time would reproduce exactly
     * the bug it exists to fix.
     *
     * Per EVENT, not per page: it sits beside advanceLastRequestTime() outside
     * the stateInit guard for that reason. Inside it, every event of a page
     * would share one number.
     *
     * Counts from 1, so 0 is never a legitimate value and absence stays
     * distinguishable -- unlike nps, which counts from zero and needed the
     * explicit test this one does not.
     *
     * TWO TABS SHARE THE STORE, so both can read n and write n+1, and a
     * duplicate is possible. Not solved here: the cube's sort keeps `id` as its
     * final tiebreak, so a duplicate is ordered deterministically rather than
     * arbitrarily, and the pair is still ordered correctly against every other
     * event of the session. A lock would cost more than the collision does.
     */
    /**
     * The beacon FORMAT generation this tracker speaks.
     *
     * Bumped when the shape of a beacon changes in a way a server has to bridge
     * -- a renamed token, a changed unit, a re-encoded value -- and not for
     * ordinary releases. One integer for the whole message, rather than a flag
     * per field.
     *
     * IT IS NOT CONSULTED TO DECIDE WHETHER A BEACON IS ACCEPTABLE. Whether one
     * beacon can become a row is the server's identity guard, which knows
     * nothing of versions. This exists so that "has generation N died out yet"
     * is a query against stored rows instead of a guess about how long a
     * customer's cache policy lets an old tracker live -- and OWA does not
     * control that policy.
     *
     * ALIGNED TO THE OWA MAJOR, so a beacon format version and the tracker
     * generation that emitted it are the same number. 2 is this wire. 1 is the
     * v1 line, which predates the field, sends nothing and lands as NULL.
     *
     * Each version's emitted set is recorded standalone in
     * tests/fixtures/beacon_contracts.json -- a version does not inherit from
     * another, because the point of keeping an old one is to know what
     * actually arrived.
     */
    static get BEACON_FORMAT_VERSION() {

        return 2;
    }

    stampEventSequence( event ) {

        var store = this.storeName( 's' );
        var seq   = OWA.getState( store, 'seq' );

        seq = ( seq === undefined || seq === null || seq === '' || isNaN( seq * 1 ) )
            ? 1
            : ( seq * 1 ) + 1;

        OWA.setState( store, 'seq', seq, true );

        // On the event rather than collected from the store later: the value is
        // THIS event's, and a second tab writing between the two reads would
        // otherwise hand it somebody else's number.
        event.set( 'event_seq', seq );
    }

    /**
     * Sends an OWA event to the server for processing using GET
     * inserts 1x1 pixel IMG tag into DOM
     */
    /**
     * Logs a custom event from an event type and a plain properties object.
     *
     * This is the queue-friendly path for custom events: the async owa_cmds
     * command queue is fire-and-forget and cannot carry an Event instance built
     * by makeEvent(), so it could not previously log a custom event. This builds
     * the Event internally (like trackAction does) so a custom event can be
     * logged with owa_cmds.push( ['trackCustomEvent', 'type', { ..props.. } ] ).
     * For advanced use that needs the Event object directly, makeEvent() +
     * trackEvent( event ) is still available.
     */
    trackCustomEvent(event_type, properties, block) {

        if ( ! OWATracker.isLegalCustomEventName( event_type ) ) {

            return;
        }

        return this.raiseEvent( event_type, properties, block );
    }

    /**
     * Send an event by name, with no reserved-name check.
     *
     * THE INTERNAL HALF of trackCustomEvent(). The guard there exists to stop a
     * SITE claiming a name OWA defines -- once a beacon arrives the server cannot
     * tell a site's `click` from its own. OWA raising its OWN first-class event is
     * not that case, and routing it through the public method made the tracker
     * refuse form_start, form_submit, view_search_results and exception, which are
     * reserved precisely because they are ours.
     *
     * Measured as four failing specs the moment the guard was added, which is the
     * distinction the two methods now carry.
     */
    raiseEvent( event_type, properties, block ) {

        var event = this.makeEvent();
        event.setEventType( event_type );

        if ( properties && typeof properties === 'object' ) {
            event.merge( properties );
        }

        return this.trackEvent( event, block );
    }

    /**
     * The least unreported engagement, in milliseconds, that is sent as a
     * user_engagement event of its own. Less than this rides the next event.
     */
    static get MIN_ENGAGEMENT_EVENT_MSEC() {

        return 1000;
    }

    /**
     * How long a route's URL must be still before its page view is sent. Long
     * enough for a framework to set the title after pushState; rapid pushes
     * inside it are one page view, for the URL they settle on.
     */
    static get ROUTE_SETTLE_MSEC() {

        return 500;
    }

    /** Send a route page view still waiting to settle, if there is one. */
    flushPendingRouteView() {

        if ( typeof this.flushRouteView === 'function' ) {

            this.flushRouteView();
        }
    }

    /**
     * The page this one was reached from: the previous route after a route
     * change, else the document's referrer.
     */
    getPageReferrer() {

        return this.routeReferrer || document.referrer;
    }

    /**
     * The screen as WIDTHxHEIGHT in CSS pixels, or '' where there is none.
     *
     * The SCREEN, not the viewport: the device's size, which is what
     * screenResolution reports. The viewport is a click's frame and rides
     * clicks only.
     */
    getScreenResolution() {

        if ( typeof window === 'undefined' || ! window.screen ) {

            return '';
        }

        var w = Math.round( Number( window.screen.width ) );
        var h = Math.round( Number( window.screen.height ) );

        return w > 0 && h > 0 ? w + 'x' + h : '';
    }

    /**
     * The event names v2 defines for itself, which a custom event may not take.
     *
     * The reserved list IS the first-class list -- page_view, click, scroll,
     * file_download, form_start, form_submit, session_start, first_visit,
     * user_engagement, view_search_results. Reusing one of them would put a
     * site's own counts into a report measuring
     * something else, and on the server it would claim a property vocabulary the
     * event does not have.
     *
     * KEPT IN STEP BY A TEST, not by hand: the server derives the same set from
     * the property registry -- an event is first-class exactly when some property
     * declares it -- and TrackerReservedEventNamesTest asserts the two agree. So
     * adding a first-class event on the server fails here until this is updated,
     * which is the only direction that can go wrong silently.
     *
     * The two markers are included though no browser sends them: they are event
     * TYPES on stored rows, materialised by the server, so a custom event using
     * one would be indistinguishable from the real thing.
     */
    static get RESERVED_EVENT_NAMES() {

        var names = [
            'page_view', 'click', 'purchase', 'refund',
            'user_engagement', 'scroll', 'file_download',
            'form_start', 'form_submit', 'view_search_results',
            'session_start', 'first_visit'
        ];

        // And whatever a compiled-in plugin sends (registerPlugin).
        OWATracker.plugins().forEach( ( plugin ) => {

            ( plugin.reservedEventNames || [] ).forEach( ( name ) => {

                if ( names.indexOf( name ) === -1 ) {

                    names.push( name );
                }
            } );
        } );

        return names;
    }

    /**
     * A name starts with a letter, uses only letters, numbers and underscores,
     * and is at most 40 characters. Case-sensitive, so my_event and My_Event are
     * two events.
     *
     * The same pattern the server applies to a custom PROPERTY name -- one rule,
     * stated once on each side.
     */
    static isLegalCustomEventName( name ) {

        name = String( name === undefined || name === null ? '' : name );

        if ( ! /^[A-Za-z][A-Za-z0-9_]{0,39}$/.test( name ) ) {

            OWA.debug( 'Custom event name must start with a letter, contain only letters, digits and underscores, and be at most 40 characters: ' + name );

            return false;
        }

        if ( OWATracker.RESERVED_EVENT_NAMES.indexOf( name ) !== -1 ) {

            OWA.debug( 'Custom event name is reserved by OWA and cannot be used: ' + name );

            return false;
        }

        /*
         * AND THE `owa_` PREFIX IS RESERVED, which is forward protection rather
         * than a rule about today.
         *
         * It keeps room to name a future first-class event without colliding
         * with one a site has already been sending for years. OWA's own events
         * are unprefixed -- page_view, click -- so the prefix is not how OWA names
         * things now; it is how a later addition stays safe.
         *
         * A leading underscore and a dotted name are refused by the pattern above
         * already.
         */
        if ( name.indexOf( OWATracker.RESERVED_EVENT_PREFIX ) === 0 ) {

            OWA.debug( 'Custom event names may not start with '
                + OWATracker.RESERVED_EVENT_PREFIX + ', which OWA reserves: ' + name );

            return false;
        }

        return true;
    }

    /** Reserved so a future first-class event cannot collide with a site's. */
    static get RESERVED_EVENT_PREFIX() { return 'owa_'; }

    trackEvent(event, block) {
        //OWA.debug('pre global event: %s', JSON.stringify(event));

        if ( this.getOption('cookie_domain_set') != true ) {
            // set default cookie domain
            this.setCookieDomain();
        }

        var block_flag = false;

        if ( this.active ) {
            if ( block ) {

                block_flag = true;
            }

            /*
             * State is managed on the client. There used to be a 'thirdParty'
             * branch here that instead stamped a flag and handed campaign
             * params upstream for someone else to evaluate -- and never called
             * logEvent(), so turning it on made the tracker silently stop
             * sending. Long dead, and removed rather than repaired.
             */
            var that = this;
            this.manageState( event, function(event) {
                that.addGlobalPropertiesToEvent( event, function(event) {
                    that.addDefaultsToEvent( event, function(event) {
                        return that.logEvent( event.getProperties(), block_flag );
                    });
                });
            });
        }
    }
    
    /**
     * Logs a page view event
     */
    trackPageView( url ) {

        var event = new OwaEvent;

        if (url) {
            event.set('page_url', url);
        }

        event.setEventType( 'page_view' );

        /*
         * THE FIRST PAGE VIEW CARRIES NO ENGAGEMENT. What has accrued before it is
         * the milliseconds between the tracker being built and the snippet asking
         * for a page view, which is not reading time. The clock restarts here.
         */
        if ( ! this.pageViewSent ) {

            this.pageViewSent = true;
            this.resetEngagement();
        }

        var ret = this.trackEvent( event );

        // Sent, with its event_seq and session stamped: anything compiled in
        // that attaches to the page view reads them here.
        OWA.doAction( 'tracker.pageView', { tracker: this, event: event } );

        return ret;
    }

    /**
     * A tracked action.
     *
     * @deprecated Use trackCustomEvent( name, { ep_*: ... } ).
     *
     * THE ACTION'S NAME IS NOW THE EVENT'S NAME. This sent event_type
     * 'custom_event' with the name as a property, which is v1's shape: one event
     * type for everything a site tracks, told apart by a field. v2 retires it --
     * an event name is a name, and the group, label and value are parameters
     * describing it, which is the reason eventName is a dimension.
     *
     * So this maps onto trackCustomEvent(): the name becomes the event, and the
     * other three become custom event properties in `params`. A site that was
     * calling it keeps working and its data lands under its own action names
     * instead of pooled under one.
     *
     * An action name that is not a legal event name, or that collides with a
     * reserved one, is refused by trackCustomEvent() rather than silently
     * reshaped.
     */
    trackAction(action_group, action_name, action_label, numeric_value) {

        var properties = {};

        if ( action_group !== undefined && action_group !== null && action_group !== '' ) {
            properties.ep_action_group = String( action_group );
        }

        if ( action_label !== undefined && action_label !== null && action_label !== '' ) {
            properties.ep_action_label = String( action_label );
        }

        if ( typeof numeric_value === 'number' && isFinite( numeric_value ) ) {
            properties.epn_numeric_value = numeric_value;
        }

        return this.trackCustomEvent( action_name, properties );
    }

    /**
     * Raise file_download for a click that fetches a file.
     *
     * A SEPARATE EVENT beside the click, not a flag on it. The click is what
     * happened in the DOM; the download is what it MEANT, and the two are
     * counted differently -- a downloads report counts the second and would
     * have to filter the first. It is also how the vocabulary already works:
     * every other tracker names these as events.
     *
     * Outbound is the opposite call: it IS a property of the click, because
     * "clicks that left the site" is the same count as "clicks", narrowed. So it
     * is a column on the click row rather than an event of its own, set by
     * isOutboundUrl() where the DOM is -- and NOT on this event. A download is a
     * file arriving, not the visitor leaving; the click that raised it answers
     * that question.
     *
     * @param {string} url
     */
    classifyClickTarget( url, click ) {

        if ( ! url ) {

            return;
        }

        var extension = this.getDownloadExtension( url );

        if ( extension ) {

            var event = this.makeEvent();
            event.setEventType( 'file_download' );
            event.set( 'target_url', url );
            event.set( 'file_extension', extension );
            event.set( 'file_name', this.getDownloadFileName( url ) );

            // WHICH LINK, copied from the click: two links to one file are told
            // apart by these.
            if ( click ) {

                [ 'dom_element_id', 'dom_element_text' ].forEach( function ( name ) {

                    if ( click.get( name ) ) {

                        event.set( name, click.get( name ) );
                    }
                } );
            }

            this.trackEvent( event );
        }
    }

    /**
     * Track form interaction: one form_start per form, and form_submit on send.
     *
     * form_start fires on the first `change` event in a given form and not
     * again, which is what makes start/submit a funnel rather than two counts
     * of the same thing. Tracked per form element, so two forms on one page
     * each get their own start.
     *
     * `change`, NOT FOCUS. A text field raises `change` when the visitor leaves
     * it with a new value; a select, checkbox or radio when its value changes.
     * Focus alone, tabbing through, or typing without leaving the field is not a
     * start -- clicking into a form and walking away is not having begun it.
     *
     * A SUBMIT WITH NO START RAISES ONE FIRST, so every form_submit has a
     * form_start and the funnel cannot show more submits than starts. That
     * happens when nothing changed (a one-button form) or the value was set by
     * script. It carries no first-field properties: no field was interacted with.
     *
     * Bound at the document with capture rather than per form, so forms added
     * to the page after load are covered without re-binding -- which is the
     * normal case on anything component-rendered.
     */
    trackForms() {

        if ( this.formTrackingEnabled || typeof document === 'undefined' ) {

            return;
        }

        this.formTrackingEnabled = true;

        var that    = this;
        var started = [];

        var formOf = function ( node ) {

            while ( node && node.nodeType === 1 ) {

                if ( String( node.tagName ).toLowerCase() === 'form' ) {

                    return node;
                }

                node = node.parentElement;
            }

            return null;
        };

        var start = function ( form, field ) {

            if ( started.indexOf( form ) > -1 ) {

                return;
            }

            started.push( form );

            that.raiseEvent( 'form_start', that.formProperties( form, field ) );
        };

        document.addEventListener( 'change', function ( e ) {

            var form = formOf( e.target );

            if ( form ) {

                // e.target is the field whose value changed, which is what makes
                // the first-field properties mean anything.
                start( form, e.target );
            }

        }, true );

        document.addEventListener( 'submit', function ( e ) {

            var form = formOf( e.target );

            if ( form ) {

                start( form );

                var properties = that.formProperties( form );

                /*
                 * WHICH BUTTON SENT IT. A form with Save and Save-and-publish is
                 * two different submissions, and nothing else on the event tells
                 * them apart. e.submitter is the button on a real submit; a
                 * dispatched Event has none, and then this is simply absent
                 * rather than guessed at.
                 *
                 * Absent on a script-dispatched submit rather than guessed, which
                 * is why the jest case for it sets `submitter` explicitly.
                 */
                var text = that.submitterText( e );

                if ( text ) {

                    properties.form_submit_text = text;
                }

                that.raiseEvent( 'form_submit', properties );
            }

        }, true );
    }

    /**
     * What a form says about itself.
     *
     * BOTH IDENTIFIERS, because either may be absent and a report keyed on a
     * missing one has nothing to group by.
     *
     * `form_destination` is where it submits, resolved to an absolute URL, and it
     * is the one of these that identifies a form across pages: two pages can each
     * carry a form with no id and no name, and the action distinguishes them.
     * Falls back to the page's own URL, which is what a form with no action
     * actually submits to.
     *
     * `form_length` counts the fields a visitor can interact with, so a
     * form_start on a two-field signup and one on a fourteen-field application are
     * distinguishable. Buttons are EXCLUDED: a button is not a field to fill in,
     * and counting it makes the number mean nothing in particular.
     *
     * The first-field properties say where the visitor started. On a long form
     * that is a real signal -- someone who begins at field nine skipped eight --
     * and it is why form_start carries them and form_submit does not.
     *
     * `first_field_type` is the element's `type` property: the input type
     * (text, email, checkbox ...; 'text' when the attribute is absent), or
     * 'select-one', 'select-multiple' or 'textarea'.
     *
     * ALL PARAMS, NOT COLUMNS. Every one of these rides `params`: most installs
     * will never group by a form's name, let alone the position of its first
     * field, and a column is width on every row of every Property. A site that
     * does want one registers it as a custom dimension.
     *
     * @param {Element} form
     * @param {Element} [field]  the field whose change started the form
     * @return {Object}
     */
    formProperties( form, field ) {

        var fields = this.formFields( form );

        var properties = {
            form_id:          form.id || '',
            form_name:        form.getAttribute( 'name' ) || '',
            form_destination: this.formDestination( form ),
            form_length:      fields.length
        };

        if ( field ) {

            properties.first_field_id       = field.id || '';
            properties.first_field_name      = field.getAttribute( 'name' ) || '';
            properties.first_field_type     = String( field.type || '' ).toLowerCase();
            // ONE-BASED: "the first field" is position 1, not position 0.
            properties.first_field_position = fields.indexOf( field ) + 1;
        }

        return properties;
    }

    /**
     * The fields of a form, in document order, excluding anything that submits.
     *
     * @param {Element} form
     * @return {Element[]}
     */
    formFields( form ) {

        var out = [];
        var all = form.querySelectorAll( 'input, select, textarea' );

        for ( var i = 0; i < all.length; i++ ) {

            var type = String( all[i].getAttribute( 'type' ) || '' ).toLowerCase();

            if ( type === 'submit' || type === 'button' || type === 'image'
                 || type === 'reset' || type === 'hidden' ) {

                continue;
            }

            out.push( all[i] );
        }

        return out;
    }

    /**
     * The label of the control that submitted the form, or '' if none did.
     *
     * `submitter` is on the real submit event. A form submitted by script has no
     * submitter, and a value is not invented for it.
     *
     * @param {Event} e
     * @return {string}
     */
    submitterText( e ) {

        var button = e && e.submitter;

        if ( ! button ) {

            return '';
        }

        var text = button.value || button.textContent || '';

        return String( text ).trim();
    }

    /**
     * Where the form submits, absolute.
     *
     * A relative action is resolved against the page, so two sites' /subscribe do
     * not read as one destination. A form with no action submits to the page
     * itself, which is what the fallback says.
     *
     * @param {Element} form
     * @return {string}
     */
    formDestination( form ) {

        var action = form.getAttribute( 'action' );
        var here = ( typeof window !== 'undefined' && window.location )
            ? window.location.href : '';

        if ( ! action ) {

            return here;
        }

        try {

            return new URL( action, here || undefined ).href;

        } catch ( e ) {

            return action;
        }
    }

    /**
     * Which query parameters carry a site-search term.
     *
     * AN ARRAY, AND IT REPLACES THE DEFAULTS rather than adding to them. A site
     * that uses ?kw= gets kw and nothing else, so its ?q= -- which may mean
     * something entirely different -- stops being read as a search. Merging would
     * make the shipped list impossible to get rid of.
     *
     * An empty array switches site-search tracking off.
     *
     * A NAMED SETTER, like setTrackUrlFragments, because
     * this is site-facing and setOption() takes any key with no checking. It
     * REFUSES anything that is not an array, which is the reason it is worth
     * having: trackSiteSearch() walks the value by index, and a string has a length
     * and indexes to characters, so setOption('siteSearchParams', 'query') searched
     * for q, u, e, r and y -- found nothing, reported nothing, said nothing. The
     * refusal is logged and the previous value stands.
     *
     * CALL IT BEFORE trackPageView(), which is where the search is read from the
     * URL. The standard snippet configures then tracks, so that is the normal
     * order; a site that calls this afterwards has already had the defaults applied
     * to its first page view.
     *
     * @param {string[]} params  parameter names, tried in order
     */
    setSearchQueryParams( params ) {

        if ( ! Array.isArray( params ) ) {

            OWA.debug( 'setSearchQueryParams needs an ARRAY of parameter names. '
                + 'A bare string indexes to its own characters.' );

            return;
        }

        var names = [];

        for ( var i = 0; i < params.length; i++ ) {

            var name = String( params[ i ] ).trim();

            if ( name ) {

                names.push( name );
            }
        }

        this.setOption( 'siteSearchParams', names );
    }

    /**
     * Raise view_search_results when the page is a site-search results page.
     *
     * 1.x has the search-term DIMENSIONS and never emits an event, so the
     * dimensions have nothing to describe. The query parameters are a setting
     * because there is no convention -- q, s, search, query and keywords are
     * all common, and a site knows which one it uses.
     *
     * @param {Array} params  query parameter names, defaults to the option
     */
    trackSiteSearch( params ) {

        params = params || this.getOption( 'siteSearchParams' );

        if ( ! params || ! params.length ) {

            return;
        }

        for ( var i = 0; i < params.length; i++ ) {

            var term = this.getSearchParam( params[ i ] );

            if ( term.trim() ) {

                return this.raiseEvent( 'view_search_results', { search_term: term } );
            }
        }
    }

    /**
     * One query-string parameter, decoded the way a form submits it.
     *
     * NOT getUrlParam(), whose parser left `+` as `+` and `%26` encoded, read
     * parameters out of the fragment, lower-cased names, took the LAST of a
     * repeated parameter and threw on a malformed escape. A search box submits
     * `Red+Shoes`; the term is `Red Shoes`.
     *
     * The query string only, the first value, the name as written. '' when the
     * parameter is absent.
     *
     * @param {string} name
     * @return {string}
     */
    getSearchParam( name ) {

        var search = ( typeof location !== 'undefined' && location ) ? location.search : '';
        var value = null;

        try {

            value = new URLSearchParams( search ).get( name );

        } catch ( e ) {

            return '';
        }

        return value === null ? '' : value;
    }

    /**
     * Report an uncaught script error as an `exception` event.
     *
     * NO STACK TRACE ON THE WIRE. A stack from a minified bundle is noise to
     * anyone reading a report, and it is the field most likely to carry a URL
     * with a token in it. Message, file and line answer "is my site broken"
     * without that risk.
     */
    trackExceptions() {

        if ( this.exceptionTrackingEnabled || typeof window === 'undefined' ) {

            return;
        }

        this.exceptionTrackingEnabled = true;

        var that     = this;
        var previous = window.onerror;

        window.onerror = function ( message, source, line ) {

            try {

                /*
                 * PREFIXED, because these are custom event properties.
                 *
                 * They were sent bare -- description, source, line -- and not one
                 * of them is a declared property or carries a prefix, so
                 * admitRequestParams() dropped all three and every exception event
                 * arrived carrying nothing but its site id. Measured.
                 */
                that.raiseEvent( 'exception', {
                    ep_description: String( message ).substring( 0, 255 ),
                    ep_source:      String( source || '' ).substring( 0, 255 ),
                    epn_line:       line || 0
                } );

            } catch ( e ) {
                // An error raised while reporting an error must not become a
                // loop, and must not replace the page's own handler.
            }

            if ( typeof previous === 'function' ) {

                return previous.apply( window, arguments );
            }

            return false;
        };
    }

    trackClicks(handler) {
        // flag to tell handler to log clicks as they happen
        this.setOption('logClicksAsTheyHappen', true);
        this.bindClickEvents();

    }

    /**
     * Raise a `scroll` event when the page passes a depth threshold.
     *
     * ITS OWN LISTENER. Scroll depth once shared a single `window.onscroll`
     * slot with another feature and fired only where that feature was active,
     * for its sampled fraction of visitors -- a first-class event gated on an
     * unrelated feature's sample rate.
     *
     * Idempotent, like trackClicks(): the snippet pushes each command once, but a
     * site can push one twice and two listeners would report every threshold twice.
     */
    trackScroll() {

        if ( this.isScrollTrackingEnabled || typeof window === 'undefined' ) {

            return;
        }

        this.isScrollTrackingEnabled = true;

        var that    = this;
        var pending = false;

        /*
         * ONE CHECK PER FRAME. `scroll` fires many times a second and every check
         * reads scrollHeight and offsetHeight, which forces layout. Nothing can
         * change the answer faster than the page repaints, so a check per frame
         * loses nothing and a check per event is pure cost for the whole visit.
         */
        var onScroll = function () {

            if ( pending ) {

                return;
            }

            if ( typeof window.requestAnimationFrame !== 'function' ) {

                that.checkScrollDepth();

                return;
            }

            pending = true;

            window.requestAnimationFrame( function () {

                pending = false;
                that.checkScrollDepth();
            } );
        };

        window.addEventListener( 'scroll', onScroll, false );

        /*
         * AND ONCE WITHOUT A SCROLL, because a page that fits in the viewport is
         * read to the end without ever firing one -- and so never reported its
         * depth at all.
         *
         * At `load`, not now. The snippet can run this before the document is laid
         * out, and a long page measured then has a small height, which reads as
         * scrolled to the bottom -- a false event on exactly the pages that are
         * least likely to be read to the end.
         */
        if ( typeof document !== 'undefined' && document.readyState === 'complete' ) {

            this.checkScrollDepth();

        } else {

            window.addEventListener( 'load', function () {

                that.checkScrollDepth();

            }, { once: true } );
        }
    }

}

export { OWATracker };
