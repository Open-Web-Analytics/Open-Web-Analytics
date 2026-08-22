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
	    // site id
	    this.siteId  =  '';
	    // ???
	    this.init =  0;
	    // flag to tell if client state has been set
	    this.stateInit =  false;
	    // properties that should be added to all events
	    this.globalEventProperties =  {};
	    // state sores that can be shared across sites
	    this.sharableStateStores =  ['v', 's', 'c', 'b'],
	    // Time When tracker is loaded
	    this.startTime =  null;
	    // time when tracker is unloaded
	    this.endTime =  null;
	    // campaign state holder
	    this.campaignState  =  [];
	    // flag for new campaign status
	    this.isNewCampaign =  false;
	    // flag for new session status
	    this.isNewSessionFlag =  false;
	    /*
	     * Whether the event that CREATED this session is still waiting to be
	     * sent. Distinct from is_new_session, which is page-scoped and rides
	     * every event from the session's first page:
	     *
	     *   is_new_session_start  this REQUEST created the session. True for
	     *                         exactly one event, which is what a server
	     *                         deciding create-vs-update needs.
	     *   is_new_session        this event happened on the page where the
	     *                         session started. True for all of them, which
	     *                         is what a per-event dimension needs.
	     *
	     * One flag cannot answer both, and it was answering the second while
	     * being read as the first -- so a second trackPageView() on the same
	     * page re-entered logSession() for a session that already existed.
	     */
	    this.pendingSessionStart = false;
	    /*
	     * The visitor half of the same pair, and the same distinction:
	     *
	     *   is_new_visitor_created  this REQUEST minted the visitor. One event.
	     *   is_new_visitor          this SESSION was the visitor's first. Every
	     *                           event of it.
	     *
	     * Nothing consumes the first one yet. It exists so v2 can raise a
	     * first_visit event from the request that actually created the visitor,
	     * which is what GA does with its _fv flag -- the session column and the
	     * is_repeat_visitor dimension both want the session-scoped one, so
	     * neither can answer "was this the moment". Do not remove it as unused.
	     */
	    this.pendingVisitorCreated = false;
	    // flag for whether or not traffic has been attributed
	    this.isTrafficAttributed =  false;
	    this.linkedStateSet =  false;
	    this.hashCookiesToDomain =  true;
	    	    
	    /**
	     * GET params parsed from URL
	     */
	    this.urlParams =  {};
	    /**
	     * DOM stream Event Binding Methods
	     */
	    this.streamBindings  =  ['bindMovementEvents', 'bindScrollEvents','bindKeypressEvents', 'bindClickEvents'];
	    /**
	     * Latest click event
	     */
	    this.click  =  '';
	    /**
	     * Domstream event
	     */
	    this.domstream  =  '';
	    /**
	     * Latest Movement Event
	     */
	    this.movement  =  '';
	    /**
	     * Latest Keystroke Event
	     */
	    this.keystroke  =  '';
	    /**
	     * Latest Hover Event
	     */
	    this.hover  =  '';
	
	    this.last_event  =  '';
	    this.last_movement  =  '';
	    /**
	     * DOM Stream Event Queue
	     */
	    this.event_queue  =  [];
	    this.player =  '';
	    this.overlay =  '';
	
	
	
		//var OWA = owa;
		//OWA.event = event;
	
	    //this.setDebug(true);
	    // set start time
	    this.startTime = this.getTimestamp();
	
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
	    OWA.registerStateStore('v', 364, '', 'json');
	    OWA.registerStateStore('c', 60, '', 'json');

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
	    OWA.registerStateStore('s', 364, '', 'json', {
		    hydrate:   'deferred',
		    hydrateOn: 'isSessionizationDone',
		    persist:   'deferred',
		    persistOn: 'persistSession'
	    });

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
	    OWA.registerStateStore('b', '', '', 'json', { collapseInto: 's' });

	    // 'd' holds page-scoped custom variables. Memory only, for the life of
	    // the page -- the state manager never writes it to a cookie. Page scope
	    // used to be the ABSENCE of a case in setCustomVar(): the value fell
	    // through to a global event property and happened to work. Declaring it
	    // makes the three scopes symmetrical and gives getCustomPageVar()
	    // somewhere to read from.
	    OWA.registerStateStore('d', '', '', 'json', { persist: 'never' });
	
	    // Configuration options
	    this.options = OWA.applyFilters('tracker.default_options', {
	        logClicks: true,
	        logPage: true,
	        logMovement: false,
	        encodeProperties: false,
	        movementInterval: 100,
	        logDomStreamPercentage: 100,
	        domstreamLoggingInterval: 3000,
	        domstreamEventThreshold: 10,
	        maxPriorCampaigns: 5,
	        campaignAttributionWindow: 60,
	        trafficAttributionMode: 'direct',
	        sessionLength: 1800,
	        thirdParty: false,
	        cookie_domain: false,
	        campaignKeys: [
	                { public: 'owa_medium', private: 'md', full: 'medium' },
	                { public: 'owa_campaign', private: 'cn', full: 'campaign' },
	                { public: 'owa_source', private: 'sr', full: 'source' },
	                { public: 'owa_search_terms', private: 'tr', full: 'search_terms' },
	                { public: 'owa_ad', private: 'ad', full: 'ad' },
	                { public: 'owa_ad_type', private: 'at', full: 'ad_type' } ],
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
	
	    // private vars
	    this.ecommerce_transaction = '';
	    this.isClickTrackingEnabled = false;
	    this.domstream_guid = '';
	
	    // check to se if an overlay session is active
	    this.checkForOverlaySession();

	    // A caller that declared the cookie domain up front has established it
	    // just as surely as setCookieDomain() does, and anything waiting on it
	    // must hear about it either way. Without this the announcement would
	    // only ever come from the lazy path in trackEvent().
	    if ( this.getOption('cookie_domain_set') === true ) {
		    OWA.doAction('cookieDomainEstablished');
	    }

		OWA.doAction('tracker.init');
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
                //ls = Util.trim(ls, '\u0000');
                //ls = Util.trim(ls, '\u0000');
                OWA.debug('linked state: %s', ls);

                var state = ls.split('.');
                //var state = Util.explode('.', ls);
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

                            OWA.replaceState( pair[0], decodedvalue, true, format );
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
            var value = OWA.getState( this.sharableStateStores[i] );
            value = Util.encodeJsonForCookie(value, OWA.getStateStoreFormat(this.sharableStateStores[i]));

            if (value) {
                state += Util.sprintf( '%s=%s', this.sharableStateStores[i], Util.urlEncode(value) );
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
            domain = document.domain;
            not_passed = true;
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
            //a = Util.trim(a, '\u0000');
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

    getUrlParam(name) {

        // The constructor seeds this.urlParams to {} -- a truthy value -- so the
        // old `this.urlParams || parseUrlParams()` guard ALWAYS short-circuited to
        // the empty object and never parsed the URL, making getUrlParam return
        // false for every query param (e.g. the ?owa_state= cross-domain linking
        // token in checkForLinkedState). Parse when the cache is still empty.
        if ( Util.is_object( this.urlParams ) && Object.keys( this.urlParams ).length === 0 ) {
            this.urlParams = Util.parseUrlParams();
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

        OWA.setState( 'd', 'page_title', Util.trim( title ) );
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

        OWA.setState( 'd', 'page_type', Util.trim( type ) );
    }

    /**
     * Convienence method for setting user name
     *
     * Visitor-scoped: an identified user outlives the page and the session, so
     * 'v' is where they belong. This DOES mean the value is now written to the
     * visitor cookie, which a global event property never was -- it is
     * long-lived state on the visitor's machine rather than a per-page label.
     */
    setUserName( value ) {

        OWA.setState( 'v', 'user_name', Util.trim( value ) );
    }

    /**
     * Sets the siteId to be appended to all logging events
     */
    setSiteId(site_id) {
	    
        this.siteId = site_id;
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

    getCurrentUrl() {

        return document.URL
    }

    bindClickEvents() {

        if ( ! this.isClickTrackingEnabled ) {
            var that = this;
            // Registers the handler for the before navigate event so that the dom stream can be logged
            if (window.addEventListener) {
                window.addEventListener('click', function (e) {that.clickEventHandler(e);}, false);
            } else if(window.attachEvent) {
                document.attachEvent('onclick', function (e) {that.clickEventHandler(e);});
            }

            this.isClickTrackingEnabled = true;
        }

    }

    setDomstreamSampleRate(value) {

        this.setOption('logDomStreamPercentage', value);
    }

    startDomstreamTimer() {

        var interval = this.getOption('domstreamLoggingInterval')
        var that = this;
        var domstreamTimer = setInterval(
            function(){ that.logDomStream() },
            interval
        );
    }

    /**
     * Deprecated
     */
    log() {

        var event = new OwaEvent
        event.setEventType("base.page_request");
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
            	
                //this.cdPost( this.prepareRequestData( properties ) );
                var data = this.prepareRequestData( properties );
                this.cdPost( data );

                /*
                 * The hidden-iframe POST gives us no delivery signal, so commit
                 * optimistically -- exactly the behaviour this path has always
                 * had. Withholding here would mean a site whose payloads always
                 * exceed getRequestCharacterLimit could never persist a session
                 * at all, minting a new one on every page view. That is a worse
                 * failure than the one this deferral exists to prevent.
                 */
                this.sendAccepted();
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

           //assemble query string
        for ( var param in properties ) {
            // print out the params
            var value = '';

            if ( properties.hasOwnProperty( param ) ) {

                  if ( Util.is_array( properties[param] ) ) {

                    var n = properties[param].length;
                    for ( var i = 0; i < n; i++ ) {

                        if ( Util.is_object( properties[param][i] ) ) {
                            for ( var o_param in properties[param][i] ) {

                                data[ Util.sprintf( OWA.getSetting('ns') + '%s[%s][%s]', param, i, o_param ) ] =  properties[ param ][ i ][ o_param ];
                            }
                        } else {
                            // what the heck is it then. assume string
                            data[ Util.sprintf(OWA.getSetting('ns') + '%s[%s]', param, i) ] = properties[ param ][ i ];
                        }
                    }
                // assume it's a string
                } else {
                    data[ Util.sprintf(OWA.getSetting('ns') + '%s', param) ] = properties[ param ];
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
                kvp = Util.sprintf('%s=%s&', param, encodeURIComponent( properties[ param ] ) );
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

        if ( Util.isIE() && Util.getInternetExplorerVersion() < 9.0 ) {
            var iframe = document.createElement('<iframe name="' + iframe_name + '" scr="about:blank" width="1" height="1"></iframe>');
        } else {
            var iframe = document.createElement("iframe");
            iframe.setAttribute('name', iframe_name);
            iframe.setAttribute('src', 'about:blank');
            iframe.setAttribute('width', 1);
            iframe.setAttribute('height', 1);
        }

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

        // cannot set the name of an element using setAttribute
        if ( Util.isIE()  && Util.getInternetExplorerVersion() < 9.0 ) {
            var frm = doc.createElement('<form name="' + form_name + '"></form>');
        } else {
            var frm = doc.createElement('form');
            frm.setAttribute( 'name', form_name );
        }

        frm.setAttribute( 'id', form_name );
        frm.setAttribute("action", post_url);
        frm.setAttribute("method", "POST");

        // create hidden inputs, add them to form
        for ( var param in data ) {

            if (data.hasOwnProperty(param)) {

                // cannot set the name of an element using setAttribute
                if ( Util.isIE() && Util.getInternetExplorerVersion() < 9.0 ) {
                    var input = doc.createElement( "<input type='hidden' name='" + param + "' />" );

                } else {
                    var input = document.createElement( "input" );
                    input.setAttribute( "name",param );
                    input.setAttribute( "type","hidden");

                }

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

    //depricated
    createPostForm() {

        var post_url = this.getLoggerEndpoint();
        var form_name = 'post_form' + Math.random();

        // cannot set the name of an element using setAttribute
        if ( Util.isIE()  && Util.getInternetExplorerVersion() < 9.0 ) {
            var frm = doc.createElement('<form name="' + form_name + '"></form>');
        } else {
            var frm = doc.createElement('form');
            frm.setAttribute( 'name', form_name );
        }

        frm.setAttribute( 'id', form_name );
         frm.setAttribute("action", post_url);
         frm.setAttribute("method", "POST");

         return frm;
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
            targ = target.parentNode;
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
        properties.dom_element_tag = Util.strtolower(targ.tagName);

        if (targ.tagName == "A") {

            if (targ.textContent != undefined) {
                 properties.dom_element_text = targ.textContent;
            } else {
                 properties.dom_element_text = targ.innerText;
            }

            properties.target_url =  targ.href;

        } else if (targ.tagName == "INPUT") {

            properties.dom_element_text = targ.value;

        } else if (targ.tagName == "IMG") {

            properties.target_url = targ.parentNode.href;
            properties.dom_element_text = targ.alt;

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

    clickEventHandler(e) {

        // hack for IE
        e = e || window.event;

        var click = new OwaEvent();
        // set event type
        click.setEventType("dom.click");

        //clicked DOM element properties
        var targ = this._getTarget(e);

        var dom_name = '(not set)';
        if ( targ.hasAttribute('name') && targ.name != null && targ.name.length > 0 ) {
            dom_name = targ.name;
        }
        click.set("dom_element_name", dom_name);

        var dom_value = '(not set)';
        if ( targ.hasAttribute('value') && targ.value.length > 0 ) {
            dom_value = targ.value;
        }
        click.set("dom_element_value", dom_value);

        var dom_id = '(not set)';
        if ( targ.id && targ.id.length > 0 ) {
            dom_id = targ.id;
        }
        click.set("dom_element_id", dom_id);

        var dom_class = '(not set)';
       // if ( targ.hasOwnProperty && targ.hasOwnProperty( 'className' ) && targ.className.length > 0) {
        if ( targ.className && targ.className.length > 0 ) {
            dom_class = targ.className;
        }
        click.set("dom_element_class", dom_class);

        // dom_element_tag is set (lower-cased) by getDomElementProperties() below,
        // whose merge() would overwrite anything set here -- so no duplicate set.
        click.set("page_url", window.location.href);
        // view port dimensions - needed for calculating relative position
        var viewport = this.getViewportDimensions();
        click.set("page_width", viewport.width);
        click.set("page_height", viewport.height);
        var properties = this.getDomElementProperties(targ);
        click.merge(this.filterDomProperties(properties));
        // set coordinates
        click.set("dom_element_x", this.findPosX(targ) + '');
        click.set("dom_element_y", this.findPosY(targ) + '');
        var coords = this.getCoords(e);
        click.set('click_x', coords.x);
        click.set('click_y', coords.y);

        // add to event queue is logging dom stream
        if (this.getOption('trackDomStream')) {
            this.addToEventQueue(click)
        }
        var full_click = Util.clone(click);
        //if all that works then log
        if (this.getOption('logClicksAsTheyHappen')) {
            //this.trackEvent(full_click);
            this.trackEvent(click);
        }


        //this.click = full_click;
        this.click = click;
    }

    // stub for a filter that will strip certain properties or abort the logging
    filterDomProperties(properties) {

        return properties;

    }

    callMethod(string, data) {

        return this[string](data);
    }

    addDomStreamEventBinding(method_name) {
	    
        this.streamBindings.push(method_name);
    }

    bindMovementEvents() {

        var that = this;
        document.onmousemove = function (e) {that.movementEventHandler(e);}
    }

    movementEventHandler(e) {

        // hack for IE
        e = e || window.event;
        var now = this.getTime();
        if (now > this.last_movement + this.getOption('movementInterval')) {
            // set event type
            this.movement = new OwaEvent();
            this.movement.setEventType("dom.movement");
            var coords = this.getCoords(e);
            this.movement.set('cursor_x', coords.x);
            this.movement.set('cursor_y', coords.y);
            this.addToEventQueue(this.movement);
            this.last_movement = now;
        }

    }

    bindScrollEvents() {

        var that = this;
        window.onscroll = function (e) { that.scrollEventHandler( e ); }
    }

    scrollEventHandler(e) {

        // hack for IE
        var e = e || window.event;

        var now = this.getTimestamp();

        var event = new OwaEvent();
        event.setEventType('dom.scroll');
        var coords = this.getScrollingPosition();
        event.set('x', coords.x);
        event.set('y', coords.y);
        this.addToEventQueue(event);
        this.last_scroll = now;

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

    bindFocusEvents() {

        var that = this;

    }

    bindKeypressEvents() {

        var that = this;
        document.onkeypress = function (e) {that.keypressEventHandler(e);}

    }

    keypressEventHandler(e) {

        e = e || window.event;

        var targ = this._getTarget(e);

        if (targ.tagName === 'INPUT' && targ.type === 'password') {
            return;
        }

        var key_code = e.keyCode? e.keyCode : e.charCode
        var key_value = String.fromCharCode(key_code);
        var event = new OwaEvent();
        event.setEventType('dom.keypress');
        event.set('key_value', key_value);
        event.set('key_code', key_code);
        event.set("dom_element_name", targ.name);
        event.set("dom_element_value", targ.value);
        event.set("dom_element_id", targ.id);
        event.set("dom_element_tag", Util.strtolower(targ.tagName));
        //console.log("Keypress: %s %d", key_value, key_code);
        this.addToEventQueue(event);

    }

    // utc epoch in seconds
    getTimestamp() {

        return Util.getCurrentUnixTimestamp();
    }

    // utc epoch in milliseconds
    getTime() {

        return Math.round(new Date().getTime());
    }

    getElapsedTime() {

        return this.getTimestamp() - this.startTime;
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

    addToEventQueue(event) {

        if (this.active && !this.isPausedBySibling()) {

            var now = this.getTimestamp();

            if (event != undefined) {
                this.event_queue.push(event.getProperties());
                OWA.debug("Now logging %s for: %d", event.get('event_type'), now);
            } else {
                OWA.debug("No event properties to log");
            }

        }
    }

    isPausedBySibling() {

        return OWA.getSetting('loggerPause');
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

    // adds a new Domstream event binding. takes function name
    addStreamEventBinding(name) {

        this.streamBindings.push(name);
    }

    // gets campaign related properties from request scope.
    getCampaignProperties() {

        // load GET params from URL
        if (!this.urlParams.length > 0)    {
            this.urlParams = Util.parseUrlParams(document.URL);
            OWA.debug('GET: '+ JSON.stringify(this.urlParams));
        }

        // look for attributes in the url of the page
        var campaignKeys = this.getOption('campaignKeys');

        // pull campaign params from _GET
        var campaign_params = {};

        for (var i = 0, n = campaignKeys.length; i < n; i++) {
			
			// anytime we see a campaign param on the URL its a new campaign.
            if ( this.urlParams.hasOwnProperty(campaignKeys[i].public) ) {

                campaign_params[campaignKeys[i].private] = this.urlParams[campaignKeys[i].public];
                //OWA.debug('campaign params obj: ' + JSON.stringify(campaign_params));
                this.isNewCampaign = true;
            }
        }

        // check for incomplete combos and backfill values if needed
        if (campaign_params['at'] && !campaign_params['ad']) {
            campaign_params['ad'] = '(not set)';
        }

        if (campaign_params['ad'] && !campaign_params['at']) {
            campaign_params['at'] = '(not set)';
        }

        return campaign_params;
    }

    setCampaignSessionState( properties ) {

        var campaignKeys = this.getOption('campaignKeys');
        for (var i = 0, n = campaignKeys.length; i < n; i++) {
            if ( properties.hasOwnProperty(campaignKeys[i].private) ) {

                OWA.setState('s', campaignKeys[i].full, properties[campaignKeys[i].private]);
            }
        }
    }

    // used when in third party cookie mode to send raw campaign related
    // properties as part of the event. upstream handler needs these to
    // do traffic attribution.
    setCampaignRelatedProperties( event ) {
	    
        var properties = this.getCampaignProperties();
        OWA.debug('campaign properties: %s', JSON.stringify(properties));

        var campaignKeys = this.getOption('campaignKeys');
        for (var i = 0, n = campaignKeys.length; i < n; i++) {
            if ( properties.hasOwnProperty(campaignKeys[i].private) ) {
                this.setGlobalEventProperty(campaignKeys[i].full, properties[campaignKeys[i].private]);
            }
        }
    }

    directAttributionModel(campaign_params) {

        if ( this.isNewCampaign ) {
            OWA.debug( 'campaign state length: %s', this.campaignState.length );
            // add the new campaing params to the prior touches array
            this.campaignState.push( campaign_params );

            // if there is prior campaign touches, check to see if there is room for one more touch
            if ( this.campaignState.length > this.options.maxPriorCampaigns ) {
                // splice array to make room for the new one
                var removed = this.campaignState.splice( 0, 1 );
                OWA.debug('Too many prior campaigns in state store. Dropping oldest to make room.');
                //OWA.debug('campaign state array post slice: ' + JSON.stringify( this.campaignState ) );
            }

            // set/reset the campaign cookie.
            this.setCampaignCookie( this.campaignState );

            // set flag
            this.isTrafficAttributed = true;
            // persist state to session store
            this.setCampaignSessionState(campaign_params);
            // return values just in case
            return campaign_params;
        }
    }

    originalAttributionModel( campaign_params ) {

        // orignal touch was set previously. jus use that.
        if ( this.campaignState.length > 0 ) {
            // do nothing
            OWA.debug( 'Original attribution detected.' );
            // set the attributes from the first campaign touch

            campaign_params = this.campaignState[0];
            // set flag
            this.isTrafficAttributed = true;

        // no orginal touch, set one if its a new campaign touch
        } else {
            OWA.debug( 'Setting Original Campaign touch.' );
            if ( this.isNewCampaign ) {

                this.campaignState.push( campaign_params );
                // set cookie
                this.setCampaignCookie( this.campaignState );
                // set flag
                this.isTrafficAttributed = true;
            }
        }
        // persist state to session store
        this.setCampaignSessionState(campaign_params);
        // return values just in case
        return campaign_params;

    }

    setCampaignMediumKey( key ) {

        this.options.campaignKeys[0].public = key;
    }

    setCampaignNameKey( key ) {

        this.options.campaignKeys[1].public = key;
    }

    setCampaignSourceKey( key ) {

        this.options.campaignKeys[2].public = key;
    }

    setCampaignSearchTermsKey( key ) {

        this.options.campaignKeys[3].public = key;
    }

    setCampaignAdKey( key ) {

        this.options.campaignKeys[4].public = key;
    }

    setCampaignAdTypeKey( key ) {

        this.options.campaignKeys[5].public = key;
    }

    setTrafficAttribution( event, callback ) {

        var campaignState = OWA.getState( 'c', 'attribs' );

        if (campaignState) {
            this.campaignState = campaignState;
        }

        var campaign_params = this.getCampaignProperties();

        // choose attribution mode.
        switch ( this.options.trafficAttributionMode ) {

            case 'direct':
                OWA.debug( 'Applying "Direct" Traffic Attribution Model' );
                campaign_params = this.directAttributionModel( campaign_params );
                break;
            case 'original':
                OWA.debug( 'Applying "Original" Traffic Attribution Model' );
                campaign_params = this.originalAttributionModel( campaign_params );
                break;
            default:
                OWA.debug( 'Applying Default (Direct) Traffic Attribution Model' );
                this.directAttributionModel( campaign_params );
        }

        // if one of the attribution methods attributes the traffic them
        // set attribution properties on the event object otherwise infer from the referer
        if ( this.isTrafficAttributed ) {

            OWA.debug( 'Attributed Traffic to: %s', JSON.stringify( campaign_params ) );

        } else {
            // infer the attribution from the referer
            // if the request is the start of a new session
            
            if ( this.isNewSessionFlag === true ) {
	            var ref = document.referrer;
	            OWA.setState( 's', 'referer', ref );
                OWA.debug( 'Infering traffic attribution.' );
               
            }
        }

        // apply traffic attribution realted properties to events
        // all properties should be set in the state store by this point.
        // The campaign keys and the session referer are no longer copied onto
        // globals here. They were already being READ out of 's' at this point --
        // the loop that stood here did nothing but move them into a
        // tracker-private cache -- so collectStateProperties() reads the same
        // values from the same place, for every event and every tracker.


        // attribs is not copied onto a global here any more. campaignState is
        // loaded from 'c' at the top of this method and written back by
        // setCampaignCookie() immediately after every mutation, so the store
        // holds the same value -- collectStateProperties() reads it from there.

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }
    }




    setCampaignCookie( values ) {
	    
        OWA.setState( 'c', 'attribs', values, '', 'json', this.options.campaignAttributionWindow );
    }
    

    /**
	 * DEPRICATED. Functionality moved to server side.
	 */
    addOrganicSearchEngine( domain, query_param, prepend) {

        return;
    }

    addTransaction( order_id, order_source, total, tax, shipping, gateway, city, state, country ) {
	    
        this.ecommerce_transaction = new OwaEvent();
        this.ecommerce_transaction.setEventType( 'ecommerce.transaction' );
        this.ecommerce_transaction.set( 'ct_order_id', order_id );
        this.ecommerce_transaction.set( 'ct_order_source', order_source );
        this.ecommerce_transaction.set( 'ct_total', total );
        this.ecommerce_transaction.set( 'ct_tax', tax );
        this.ecommerce_transaction.set( 'ct_shipping', shipping );
        this.ecommerce_transaction.set( 'ct_gateway', gateway );
        this.ecommerce_transaction.set( 'page_url', this.getCurrentUrl() );
        this.ecommerce_transaction.set( 'city', city );
        this.ecommerce_transaction.set( 'state', state );
        this.ecommerce_transaction.set( 'country', country );

        OWA.debug('setting up ecommerce transaction');

        this.ecommerce_transaction.set( 'ct_line_items', [] );
        OWA.debug('completed setting up ecommerce transaction');
    }

    addTransactionLineItem( order_id, sku, product_name, category, unit_price, quantity ) {

        if ( ! this.ecommerce_transaction ) {
            this.addTransaction('none set');
        }

        var li = {};
        li.li_order_id = order_id ;
        li.li_sku = sku ;
        li.li_product_name = product_name ;
        li.li_category = category ;
        li.li_unit_price = unit_price ;
        li.li_quantity = quantity ;
        var items = this.ecommerce_transaction.get( 'ct_line_items' );
        items.push( li );
        this.ecommerce_transaction.set( 'ct_line_items', items );
    }

    trackTransaction() {

        if ( this.ecommerce_transaction ) {
            this.trackEvent( this.ecommerce_transaction );
            this.ecommerce_transaction = '';
        }
    }

    setNumberPriorSessions( event, callback ) {

        OWA.debug('setting number of prior sessions');
        // if check for nps value in vistor cookie.
        var nps = OWA.getState( 'v', 'nps' );
        // set value to 1 if not found as it means its he first session.

        if ( this.isNewSessionFlag ) {

            if ( ! nps ) {
                nps = "0";
            } else {
                // increment visit count and persist to state store
                nps = nps * 1;
                nps++;
            }

            OWA.setState( 'v', 'nps', nps, true );
        }


        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }
    }

    setDaysSinceLastSession( event, callback ) {

        OWA.debug('setting days since last session.');
        var dsps = '';
        if ( OWA.getState( 'd', 'is_new_session' ) ) {
            OWA.debug( 'timestamp: %s', event.get( 'timestamp' ) );
            var last_req = OWA.getState( 's', 'prior_last_req' ) || event.get( 'timestamp' );
            OWA.debug( 'last_req: %s', last_req );
            dsps = Math.round( ( event.get( 'timestamp' ) - last_req ) / ( 3600*24 ) );
            OWA.setState( 's', 'dsps', dsps);
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

            /*
             * Session state: it says this session was the visitor's FIRST, not
             * that this request minted them, and the store's lifetime is what
             * makes that true.
             *
             * On a new session the persisted copy is discarded and memory kept,
             * so a returning visitor's stale flag goes and a genuinely new
             * one's survives. Written here, ahead of that discard, because
             * setVisitorId runs before setSessionId in the chain.
             *
             * On a later page of the SAME session it hydrates back, which is
             * the fix: as a per-page global it vanished, so the server derived
             * is_repeat_visitor = true on page two of a visitor's very first
             * session while the session row still said is_new_visitor.
             */
            OWA.setState( 's', 'is_new_visitor', true );
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
        var dsfs = Math.round( ( event.get( 'timestamp' ) - fsts ) / ( 3600 * 24 ) ) ;
        OWA.setState( 'v', 'dsfs', dsfs );

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
        var last_req = OWA.getState('s', 'last_req') || OWA.getPersistedState('s', 'last_req');
        OWA.debug('last_req from cookie: %s', last_req);
        // suppport for old style cookie
        if ( ! last_req ) {
            var state_store_name = Util.sprintf( '%s_%s', 'ss', this.siteId );
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
        var session_store = OWA.getState( 's' );
        var already_established = session_store
            && typeof session_store === 'object'
            && session_store.hasOwnProperty( 'prior_last_req' );

        if ( ! already_established ) {
            OWA.setState( 's', 'prior_last_req', last_req );
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
        var is_new_session = this.isNewSession(
            event.get( 'timestamp' ),
            OWA.getState( 's', 'last_req' ) || OWA.getPersistedState( 's', 'last_req' )
        );

        if ( is_new_session ) {
            // Persisted read, for the same reason as last_req above: the id of
            // the session that just ended was written by a previous page load,
            // so it is in the cookie and not in memory.
            var prior_session_id = OWA.getPersistedState('s', 'sid');
            if ( ! prior_session_id ) {
                state_store_name = Util.sprintf('%s_%s', 'ss', this.getSiteId() );
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
                OWA.setState( 's', 'prior_session_id', prior_session_id );
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
            OWA.setState( 'd', 'is_new_session', true );
            this.pendingSessionStart = true;
            this.isNewSessionFlag = true;
            OWA.setState( 's', 'sid', session_id, true );
            
        } else {
	        
            // Must be an active session so just pull the session id from the state store
            session_id = OWA.getState('s', 'sid');
            // support for old style cookie
            if ( ! session_id ) {
                state_store_name = Util.sprintf( '%s_%s', 'ss', this.getSiteId() );
                session_id = OWA.getState(state_store_name, 's');
                OWA.setState( 's', 'sid', session_id, true );
            }
        }

        // Fail-safe just in case there is no session_id. Checks the local
        // rather than a global event property: the id is state, and the two
        // branches above are what decide whether there is one.
        if ( ! session_id ) {
            session_id = Util.generateRandomGuid();
            //mark new session flag on current request
            OWA.setState( 'd', 'is_new_session', true );
            this.pendingSessionStart = true;
            this.isNewSessionFlag = true;
            OWA.setState( 's', 'sid', session_id, true );
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
     * Set a custom variable
     *
     * @param    slot    int        the identifying number for the custom variable. 1-5.
     * @param    name    string    the key of the custom variable.
     * @param    value    string    the value of the varible
     * @param    scope    string    the scope of the variable. can be page, session, or visitor
     */
    setCustomVar(slot, name, value, scope) {

        var cv_param_name = 'cv' + slot;
        var cv_param_value = name + '=' + value;

        if (cv_param_value.length > 65) {
            OWA.debug('Custom variable name + value is too large. Must be less than 64 characters.');
            return;
        }

        //this.dirtyCustomVars[cv_param_name] = {'value' : cv_param_value, 'scope' : scope};

        switch (scope) {

            case 'page':
            default:

                // Memory only, discarded with the page.
                //
                // Also the default: an absent or unrecognised scope is treated
                // as page rather than dropped. That is what the old fallthrough
                // did, when there was no 'page' case at all and the value
                // simply landed on the global event property below the switch.
                OWA.setState('d', cv_param_name, cv_param_value);
                break;

            case 'session':

                // The session store, not a second cookie beside it. 'b' existed
                // only to hold these, which is why a variable scoped to the
                // session did not share that session's lifetime.
                //
                // This lands in MEMORY. It reaches the cookie when the session
                // is settled and a request carrying it has been accepted -- see
                // the 'persistSession' action. Holding it back is what makes it
                // distinguishable from a value the previous session left in the
                // cookie, which is the whole reason a new session can discard
                // one and keep the other.
                OWA.setState('s', cv_param_name, cv_param_value);
                // Drop the NARROWER copies of this slot. Re-scoping upwards is
                // a promotion, so the old copy is stale, and leaving it behind
                // lets it shadow the new value: getCustomVar() checks 'd'
                // before 's', and only the tracker that made the call has the
                // global event property that would otherwise mask the
                // difference. A second tracker on the same page would read the
                // superseded page value while this one read the session value.
                //
                // Setting page scope over a session value does NOT do the
                // reverse, and should not: that direction is a deliberate
                // per-page override of a longer-lived value, not a promotion.
                OWA.clearState('d', cv_param_name);
                OWA.clearState('b', cv_param_name);
                OWA.debug('just set custom var on session.');
                break;

            case 'visitor':

                // store in visitor cookie
                OWA.setState('v', cv_param_name, cv_param_value);
                // remove slot from the narrower stores
                OWA.clearState('d', cv_param_name);
                OWA.clearState('s', cv_param_name);
                OWA.clearState('b', cv_param_name);
                break;
        }
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
     * precedence (see collectCustomVars()), and 'cdh', which is the cookie
     * domain hash -- bookkeeping the state manager puts on every store, not a
     * tracking property.
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
            { store: 'v', key: 'fsts', name: 'fsts' },
            { store: 'v', key: 'dsfs', name: 'dsfs' },
            { store: 'v', key: 'nps',  name: 'nps' },
            { store: 's', key: 'sid',     name: 'session_id' },
            { store: 's', key: 'referer', name: 'session_referer' },
            { store: 's', key: 'prior_session_id', name: 'prior_session_id' },
            { store: 's', key: 'is_new_visitor',    name: 'is_new_visitor' }
        ];

        for ( var i = 0; i < map.length; i++ ) {

            var value = OWA.getState( map[i].store, map[i].key );

            // Defined rather than truthy: dsps is legitimately 0, and nps is
            // legitimately the string "0" on a visitor's first session.
            if ( value !== undefined && value !== '' ) {
                collected[ map[i].name ] = value;
            }
        }

        var dsps = OWA.getState( 's', 'dsps' );
        collected.dsps = ( dsps !== undefined && dsps !== '' ) ? dsps : 0;

        // Defined-only, not non-empty: '' is the honest answer on a visitor's
        // first ever request, and the property is in the beacon contract, so it
        // has to be present as '' rather than missing.
        var prior_last_req = OWA.getState( 's', 'prior_last_req' );

        if ( prior_last_req !== undefined ) {
            collected.last_req = prior_last_req;
        }

        // The accumulated attribution history. Stored as an array; the wire
        // format is JSON, and it is omitted entirely when empty rather than
        // being sent as "[]".
        var campaign_state = OWA.getState( 'c', 'attribs' );

        if ( campaign_state && campaign_state.length > 0 ) {
            collected.attribs = JSON.stringify( campaign_state );
        }

        // Campaign keys are session state too, written into 's' by the
        // attribution model. Their names are configured rather than fixed, so
        // they cannot go in the map above.
        var campaignKeys = this.getOption('campaignKeys') || [];

        for ( var k = 0; k < campaignKeys.length; k++ ) {

            var campaign_value = OWA.getState( 's', campaignKeys[k].full );

            if ( campaign_value ) {
                collected[ campaignKeys[k].full ] = campaign_value;
            }
        }

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
            'page_title':   Util.trim( document.title ),
            'HTTP_REFERER': document.referrer
        };

        var store = OWA.getState( 'd' );

        if ( ! store || typeof store !== 'object' ) {
            return collected;
        }

        for ( var key in store ) {

            if ( store.hasOwnProperty( key )
                 && key !== 'cdh'
                 && ! /^cv[0-9]+$/.test( key ) ) {

                collected[ key ] = store[ key ];
            }
        }

        return collected;
    }

    collectCustomVars() {

        var collected = {};
        var stores = [ 'v', 's', 'd' ];
        var max = this.getOption('maxCustomVars');

        for ( var i = 0; i < stores.length; i++ ) {

            for ( var slot = 1; slot <= max; slot++ ) {

                var cv_param_name = 'cv' + slot;
                var value = OWA.getState( stores[ i ], cv_param_name );

                if ( value ) {
                    collected[ cv_param_name ] = value;
                }
            }
        }

        return collected;
    }

    getCustomVar(slot) {

        var cv_param_name = 'cv' + slot;
        var cv = '';
        // check request/page level
        cv = this.getGlobalEventProperty( cv_param_name );
        if ( ! cv ) {
            cv = OWA.getState( 'd', cv_param_name );
        }
        //check session store
        if ( ! cv ) {
            cv = OWA.getState( 's', cv_param_name );
        }
        // NOTE: no read of the legacy 'b' store. The collapse migration moves
        // its values into 's' before anything reads, so a fallback here would
        // be dead after the migration and WRONG before it -- 'b' is a persisted
        // cookie, so reading it returns a previous session's value without any
        // of the settling that decides whether that value still applies. See
        // getCustomSessionVar().
        // check visitor store
        if ( ! cv ) {
            cv = OWA.getState( 'v', cv_param_name );
        }

        return cv;

    }

    /**
     * Read a page-scoped custom variable.
     *
     * Always available: page scope is memory only and never waits on anything.
     */
    getCustomPageVar( slot ) {

        var cv_param_name = 'cv' + slot;
        var cv = this.getGlobalEventProperty( cv_param_name );

        if ( ! cv ) {
            cv = OWA.getState( 'd', cv_param_name );
        }

        return cv;
    }

    /**
     * Read a session-scoped custom variable.
     *
     * CALL THIS AFTER trackPageView(). Before it, the session store holds only
     * what this page load has set: whether the values a previous page persisted
     * still apply depends on whether that session is still running, and nothing
     * knows that until sessionization has run. A read beforehand therefore
     * returns what you set on this page and nothing else.
     *
     * That is a deliberate restriction rather than a limitation to work around.
     * Serving the persisted value early would mean answering with a variable
     * belonging to a session that may already have ended, and the caller would
     * have no way to tell.
     *
     * This is also why there is no fallback read of the legacy 'b' store. It
     * would be dead weight after the collapse migration has run -- 'b' is empty
     * by then -- and before it, it would do exactly what the paragraph above
     * rules out: 'b' is a persisted cookie, so reading it hands back a previous
     * session's value with none of the settling that decides whether it still
     * applies. On a real page the migration runs on the first tracked event
     * (the cookie domain is not known before then), so that window is real, not
     * theoretical.
     */
    getCustomSessionVar( slot ) {

        return OWA.getState( 's', 'cv' + slot );
    }

    /**
     * Read a visitor-scoped custom variable.
     *
     * Always available: the visitor store does not wait on the session
     * decision, because a visitor outlives their sessions.
     */
    getCustomVisitorVar( slot ) {

        return OWA.getState( 'v', 'cv' + slot );
    }

    deleteCustomVar(slot) {

        var cv_param_name = 'cv' + slot;
        // clear page level store
        Util.clearState( 'd', cv_param_name );
        //clear session level, current and legacy
        Util.clearState( 's', cv_param_name );
        Util.clearState( 'b', cv_param_name );
        //clear visitor level
        Util.clearState( 'v', cv_param_name );
        // clear page level
        this.deleteGlobalEventProperty( cv_param_name )
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

            event.set('HTTP_REFERER', document.referrer );
        }

        if ( ! event.get( 'page_title') && ! this.getGlobalEventProperty('page_title') ) {

            event.set('page_title', Util.trim( document.title ) );
        }

        if ( ! event.get( 'timestamp') ) {

            event.set('timestamp', this.getTimestamp() );
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
        var custom_vars = this.collectCustomVars();

        for ( var sp_name in state_properties ) {
            if ( state_properties.hasOwnProperty( sp_name ) ) {
                collected[ sp_name ] = state_properties[ sp_name ];
            }
        }

        for ( var cv_name in custom_vars ) {
            if ( custom_vars.hasOwnProperty( cv_name ) ) {
                collected[ cv_name ] = custom_vars[ cv_name ];
            }
        }

        // user_name lives on the visitor, not the page.
        var user_name = OWA.getState( 'v', 'user_name' );
        if ( user_name ) {
            collected.user_name = user_name;
        }

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

                                that.setDaysSinceLastSession( event, function( event ) {

                                    that.setTrafficAttribution( event, function( event ) {

                                        that.stateInit = true;

                                    });
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
         * means an inactivity window. This makes the value match the name. It
         * is also how GA behaves: its session cookie carries a most-recent-hit
         * timestamp updated per event, alongside the session start.
         *
         * Placed after the identity block so the first event of a page still
         * decides sessionization against the PREVIOUS request before this one
         * overwrites it.
         */
        this.advanceLastRequestTime( event );

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

        OWA.setState( 's', 'last_req', event.get( 'timestamp' ) || this.getTimestamp(), true );
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

        var event = this.makeEvent();
        event.setEventType( event_type );

        if ( properties && typeof properties === 'object' ) {
            event.merge( properties );
        }

        return this.trackEvent( event, block );
    }

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

            // check for third party mode.
            if ( this.getOption( 'thirdParty' ) ) {
                // tell upstream client to manage state
                this.globalEventProperties.thirdParty = true;
                // add in campaign related properties for upstream evaluation
                this.setCampaignRelatedProperties(event);
            } else {
                // else we are in first party mode, so manage state on the client.
                //this.manageState(event);
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
    }
    
    /**
     * Logs a page view event
     */
    trackPageView( url ) {

        var event = new OwaEvent;

        if (url) {
            event.set('page_url', url);
        }

        event.setEventType( "base.page_request" );

        return this.trackEvent( event );
    }

    trackAction(action_group, action_name, action_label, numeric_value) {

        var event = new OwaEvent;

        event.setEventType('track.action');
        event.set('action_group', action_group);
        event.set('action_name', action_name);
        event.set('action_label', action_label);
        event.set('numeric_value', numeric_value);
        this.trackEvent(event);
        OWA.debug("Action logged");
    }

    trackClicks(handler) {
        // flag to tell handler to log clicks as they happen
        this.setOption('logClicksAsTheyHappen', true);
        this.bindClickEvents();

    }

    logDomStream() {

        var domstream = new OwaEvent;
		
        if ( this.event_queue.length > this.options.domstreamEventThreshold ) {

            // make an domstream_id if one does not exist. needed for upstream processing
            if ( ! this.domstream_guid ) {
                this.domstream_guid = Util.generateRandomGuid();
            }
            domstream.setEventType( 'dom.stream' );
            domstream.set( 'domstream_guid', this.domstream_guid );
            domstream.set( 'duration', this.getElapsedTime());
            domstream.set( 'stream_events', JSON.stringify(this.event_queue));
            domstream.set( 'stream_length', this.event_queue.length );

            var viewport = this.getViewportDimensions();
            domstream.set('page_width', viewport.width);
            domstream.set('page_height', viewport.height);

            // clear event queue now instead of waiting for new trackevent
            // which might be delayed if using an ifram to POST data
            this.event_queue = [];
            return this.trackEvent( domstream );

        } else {
            OWA.debug("Domstream had too few events to log.");
        }
    }

    trackDomStream() {

        if (this.active) {

            // check random number against logging percentage
            var rand = Math.floor(Math.random() * 100 + 1 );

            if (rand <= this.getOption('logDomStreamPercentage')) {

                // needed by click handler
                this.setOption('trackDomStream', true);
                // loop through stream event bindings
                var len = this.streamBindings.length;
                for ( var i = 0; i < len; i++ ) {
                //for (method in this.streamBindings) {

                    this.callMethod(this.streamBindings[i]);
                }

                this.startDomstreamTimer();
            } else {
                OWA.debug("not tracking domstream for this user.");
            }
        }
    }
}

export { OWATracker };
