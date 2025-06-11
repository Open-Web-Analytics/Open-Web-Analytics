/**
 * Javascript Tracker Object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.openwebanalytics.com/licenses/ BSD-3 Clause
 */
 
import { OWA_instance as OWA } from '../common/owa.js';
import { Util } from '../common/Util.js';
import { Event } from './Event.js';
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
	    OWA.registerStateStore('v', 364, '', 'assoc');
	    OWA.registerStateStore('s', 364, '', 'assoc');
	    OWA.registerStateStore('c', 60, '', 'json');
	    OWA.registerStateStore('b', '', '', 'json');
	
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
                        decodedvalue = Util.decodeCookieValue(value);
                        //OWA.debug('decoded shared link state value: %s', JSON.stringify(decodedvalue));
                        var format = Util.getCookieValueFormat(value);
                        //OWA.debug('format of decoded shared state value: %s', format);
                        decodedvalue.cdh = Util.getCookieDomainHash( this.getCookieDomain() );

                        OWA.replaceState( pair[0], decodedvalue, true, format );
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

            // set the overlay cookie
            Util.setCookie( OWA.getSetting('ns') + 'overlay',a, '','/', document.domain );
            //alert(Util.readCookie('owa_overlay') );
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

        this.urlParams = this.urlParams || Util.parseUrlParams();

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
     */
    setPageTitle(title) {

        this.setGlobalEventProperty("page_title", Util.trim( title ) );
    }

    /**
     * Convienence method for setting page type
     */
    setPageType(type) {

        this.setGlobalEventProperty("page_type", Util.trim( type ) );
    }

    /**
     * Convienence method for setting user name
     */
    setUserName( value ) {

        this.setGlobalEventProperty( 'user_name', Util.trim( value ) );
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

        var event = new Event
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
            } else {

                OWA.debug('url : %s', url);
                   var image = new Image(1, 1);
                   //expireDateTime = now.getTime() + delay;
                   image.onLoad = function () { };
                image.src = url;
                if (block) {
                    //OWA.debug(' blocking...');
                }
                OWA.debug('Inserted web bug for %s', properties['event_type']);
            }

            if (callback && (typeof(callback) === "function")) {
                callback();
            }
        }
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
                kvp = Util.sprintf('%s=%s&', param, properties[ param ] );
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
        // Set properties of the owa_click object.
        properties.dom_element_tag = targ.tagName;

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

        var click = new Event();
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

        click.set("dom_element_tag", Util.strtolower(targ.tagName));
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
            this.movement = new Event();
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

        var event = new Event();
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
        var event = new Event();
        event.setEventType('dom.keypress');
        event.set('key_value', key_value);
        event.set('key_code', key_code);
        event.set("dom_element_name", targ.name);
        event.set("dom_element_value", targ.value);
        event.set("dom_element_id", targ.id);
        event.set("dom_element_tag", targ.tagName);
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
        return new Event();
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
        var campaignKeys = this.getOption('campaignKeys');
        for (var i = 0, n = campaignKeys.length; i < n; i++) {
            var value = OWA.getState( 's', campaignKeys[i].full );

            if ( value ) {
                this.setGlobalEventProperty( campaignKeys[i].full, value );
            }
        }

        // set sesion referer
        // @todo move this logic to service side. not really needed in tracker as we already send HTTTP_REFERER
        var session_referer = OWA.getState('s', 'referer');
        if ( session_referer ) {

            this.setGlobalEventProperty( 'session_referer', session_referer );
        }

        // add the attribs to event properties
        // set campaign touches
        if ( this.campaignState.length > 0 ) {
            this.setGlobalEventProperty( 'attribs', JSON.stringify( this.campaignState ) );
            //event.set( 'campaign_timestamp', campaign_params.ts );

        }

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
	    
        this.ecommerce_transaction = new Event();
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

        this.setGlobalEventProperty( 'nps',  nps );

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }
    }

    setDaysSinceLastSession( event, callback ) {

        OWA.debug('setting days since last session.');
        var dsps = '';
        if ( this.getGlobalEventProperty( 'is_new_session' ) ) {
            OWA.debug( 'timestamp: %s', event.get( 'timestamp' ) );
            var last_req = this.getGlobalEventProperty( 'last_req' ) || event.get( 'timestamp' );
            OWA.debug( 'last_req: %s', last_req );
            dsps = Math.round( ( event.get( 'timestamp' ) - last_req ) / ( 3600*24 ) );
            OWA.setState( 's', 'dsps', dsps);
        }

        if ( ! dsps ) {
            dsps = OWA.getState( 's', 'dsps' ) || 0;
        }

        this.setGlobalEventProperty( 'dsps', dsps );

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
            visitor_id = Util.generateRandomGuid( this.siteId );

            this.globalEventProperties.is_new_visitor = true;
            OWA.debug('Creating new visitor id');
        }
        // set property on event object
        OWA.setState( 'v', 'vid', visitor_id, true );
        this.setGlobalEventProperty( 'visitor_id', visitor_id );

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
        this.setGlobalEventProperty( 'fsts', fsts );

        // calc days since first session
        var dsfs = Math.round( ( event.get( 'timestamp' ) - fsts ) / ( 3600 * 24 ) ) ;
        OWA.setState( 'v', 'dsfs', dsfs );
        this.setGlobalEventProperty( 'dsfs', dsfs );

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }
    }

    setLastRequestTime( event, callback ) {

        var last_req = OWA.getState('s', 'last_req');
        OWA.debug('last_req from cookie: %s', last_req);
        // suppport for old style cookie
        if ( ! last_req ) {
            var state_store_name = Util.sprintf( '%s_%s', 'ss', this.siteId );
            last_req = OWA.getState( state_store_name, 'last_req' );
        }

        // set property on for all events
        OWA.debug('setting last_req global property of %s', last_req);
        this.setGlobalEventProperty( 'last_req', last_req );

        // store new state value
        OWA.setState( 's', 'last_req', event.get( 'timestamp' ), true );

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }
    }

    setSessionId( event, callback ) {
	    
        var session_id = '';
        var state_store_name = '';
        var is_new_session = this.isNewSession( event.get( 'timestamp' ),  this.getGlobalEventProperty( 'last_req' ) );
        if ( is_new_session ) {
            //set prior_session_id
            var prior_session_id = OWA.getState('s', 'sid');
            if ( ! prior_session_id ) {
                state_store_name = Util.sprintf('%s_%s', 'ss', this.getSiteId() );
                prior_session_id = OWA.getState(state_store_name, 's');
            }
            if ( prior_session_id ) {
                this.globalEventProperties.prior_session_id = prior_session_id;
            }

            this.resetSessionState();

            session_id = Util.generateRandomGuid( this.getSiteId() );
            // it's a new session. generate new session ID
               this.globalEventProperties.session_id = session_id;
               //mark new session flag on current request
            this.globalEventProperties.is_new_session = true;
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

            this.globalEventProperties.session_id = session_id;
        }

        // fail-safe just in case there is no session_id
        if ( ! this.getGlobalEventProperty( 'session_id' ) ) {
            session_id = Util.generateRandomGuid( this.getSiteId() );
            this.globalEventProperties.session_id = session_id;
            //mark new session flag on current request
            this.globalEventProperties.is_new_session = true;
            this.isNewSessionFlag = true;
            OWA.setState( 's', 'sid', session_id, true );
        }

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }

    }

    resetSessionState() {

        var last_req = OWA.getState( 's', 'last_req');
        OWA.clearState('s');
        OWA.setState('s', 'last_req', last_req);
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

            case 'session':

                // store in session cookie
                OWA.setState('b', cv_param_name, cv_param_value);
                OWA.debug('just set custom var on session.');
                break;

            case 'visitor':

                // store in visitor cookie
                OWA.setState('v', cv_param_name, cv_param_value);
                // remove slot from session level cookie
                OWA.clearState('b', cv_param_name);
                break;
        }

        this.setGlobalEventProperty(cv_param_name, cv_param_value);
    }

    getCustomVar(slot) {

        var cv_param_name = 'cv' + slot;
        var cv = '';
        // check request/page level
        cv = this.getGlobalEventProperty( cv_param_name );
        //check session store
        if ( ! cv ) {
            cv = OWA.getState( 'b', cv_param_name );
        }
        // check visitor store
        if ( ! cv ) {
            cv = OWA.getState( 'v', cv_param_name );
        }

        return cv;

    }

    deleteCustomVar(slot) {

        var cv_param_name = 'cv' + slot;
        //clear session level
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

        // add custom variables to global properties if not there already
        for ( var i=1; i <= this.getOption('maxCustomVars'); i++ ) {
            var cv_param_name = 'cv' + i;
            var cv_value = '';

            // if the custom var is not already a global property
            if ( ! this.globalEventProperties.hasOwnProperty( cv_param_name ) ) {
                // check to see if it exists
                cv_value = this.getCustomVar(i);
                // if so add it
                if ( cv_value ) {
                    this.setGlobalEventProperty( cv_param_name, cv_value );
                }
            }
        }

        OWA.debug( 'Adding global properties to event: %s', JSON.stringify(this.globalEventProperties) );
        for ( var prop in this.globalEventProperties ) {

            // only set global properties is they are not already set on the event
            if ( this.globalEventProperties.hasOwnProperty( prop )
                 && ! event.isSet( prop ) )
            {
                event.set( prop, this.globalEventProperties[prop] );
            }
        }

        if (callback && (typeof(callback) === "function")) {
            callback(event);
        }

    }

    manageState( event, callback ) {

        var that = this;
        if ( ! this.stateInit ) {

            this.setVisitorId( event, function(event) {

                that.setFirstSessionTimestamp( event, function( event ) {

                    that.setLastRequestTime( event, function( event ) {

                        that.setSessionId( event, function( event ) {

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

        if (callback && ( typeof( callback ) === "function" ) ) {
            callback( event );
        }
    }

    /**
     * Sends an OWA event to the server for processing using GET
     * inserts 1x1 pixel IMG tag into DOM
     */
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

        var event = new Event;

        if (url) {
            event.set('page_url', url);
        }

        event.setEventType( "base.page_request" );

        return this.trackEvent( event );
    }

    trackAction(action_group, action_name, action_label, numeric_value) {

        var event = new Event;

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

        var domstream = new Event;
		
        if ( this.event_queue.length > this.options.domstreamEventThreshold ) {

            // make an domstream_id if one does not exist. needed for upstream processing
            if ( ! this.domstream_guid ) {
                var salt = 'domstream' + this.getCurrentUrl() + this.getSiteId();
                this.domstream_guid = Util.generateRandomGuid( salt );
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
