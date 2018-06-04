	//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//

/**
 * OWA Generic Event Object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.2.1
 */
OWA.event = function() {

	this.properties = {};
	this.id = '';
	this.siteId = '';
	this.set('timestamp', OWA.util.getCurrentUnixTimestamp() );
}

OWA.event.prototype = {
	
	get : function(name) {
		
		if ( this.properties.hasOwnProperty(name) ) {
		
			return this.properties[name];
		}
	},
	
	set : function(name, value) {
		
		this.properties[name] = value;
	},
	
	setEventType : function(event_type) {
	
		this.set("event_type", event_type);
	},
	
	getProperties : function() {
		
		return this.properties;
	},
	
	merge : function(properties) {
		
		for(param in properties) {
			
			if (properties.hasOwnProperty(param)) {
	       
				this.set(param, properties[param]);
			}
	    }
	},
	
	isSet : function( name ) {
		
		if ( this.properties.hasOwnProperty( name ) ) {
		
			return true;
		}
	}
}

OWA.commandQueue = function() {

	OWA.debug('Command Queue object created');
	var asyncCmds = [];
	var is_paused = false;
}

OWA.commandQueue.prototype = {
	
	push : function (cmd, callback) {
		
		//alert(func[0]);
		var args = Array.prototype.slice.call(cmd, 1);
		//alert(args);
		
		var obj_name = '';
		var method = '';
		var check = OWA.util.strpos( cmd[0], '.' );
		
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
		
		// check to see if the command queue has been paused
		// used to stop tracking		
		if ( ! this.is_paused ) {
		
			// is OWATracker created?
			if ( typeof window[obj_name] == "undefined" ) {
				OWA.debug('making global object named: %s', obj_name);
				window[obj_name] = new OWA.tracker( { globalObjectName: obj_name } );
			}
			
			window[obj_name][method].apply(window[obj_name], args);
		}
		
		if ( method === "unpause-owa") {
			
			this.unpause();
		}
			
		if ( callback && ( typeof callback == 'function') ) {
			callback();
		}
		
	},
	
	loadCmds: function( cmds ) {
		
		this.asyncCmds = cmds;
	},
	
	process: function() {
		
		var that = this;
		var callback = function () {
        	// when the handler says it's finished (i.e. runs the callback)
        	// We check for more tasks in the queue and if there are any we run again
        	if (that.asyncCmds.length > 0) {
            	that.process();
         	}
        }
        
        // give the first item in the queue & the callback to the handler
        this.push(this.asyncCmds.shift(), callback);
        
     
		/*
		for (var i=0; i < this.asyncCmds.length;i++) {
			this.push(this.asyncCmds[i]);
		}
		*/
	},
	
	pause: function() {
		
		this.is_paused = true;
		OWA.debug('Pausing Command Queue');
	},
	
	unpause: function() {
		
		this.is_paused = false;
		OWA.debug('Un-pausing Command Queue');
	}
};

/**
 * Javascript Tracker Object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.2.1
 */
OWA.tracker = function( options ) {
	
	//this.setDebug(true);
	// set start time
	this.startTime = this.getTimestamp();
	
	// register cookies
	OWA.registerStateStore('v', 364, '', 'assoc');
	OWA.registerStateStore('s', 364, '', 'assoc');
	OWA.registerStateStore('c', 60, '', 'json');
	OWA.registerStateStore('b', '', '', 'json');
	
	// Configuration options
	this.options = {
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
		
	};
	
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
	
	// create page object.
	this.page = new OWA.event();
	
	// merge page properties from global owa_params object
	if (typeof owa_params != 'undefined') {
		// merge page params from the global object if it exists
		if (owa_params.length > 0) {
			this.page.merge(owa_params);
		}
	}
}

OWA.tracker.prototype = {

	id : '',
	// site id
	siteId : '',
	// ???
	init: 0,
	// flag to tell if client state has been set
	stateInit: false,
	// properties that should be added to all events
	globalEventProperties: {},
	// state sores that can be shared across sites
	sharableStateStores: ['v', 's', 'c', 'b'],
	// Time When tracker is loaded
	startTime: null,
	// time when tracker is unloaded
	endTime: null,
	// campaign state holder
	campaignState : [],
	// flag for new campaign status
	isNewCampaign: false,
	// flag for new session status
	isNewSessionFlag: false,
	// flag for whether or not traffic has been attributed
	isTrafficAttributed: false,
	linkedStateSet: false,
	hashCookiesToDomain: true,
	organicSearchEngines: [
		{d: 'google', q: 'q'},
		{d: 'yahoo', q: 'p'},
		{d: 'yahoo', q: 'q'},
		{d: 'msn', q: 'q'},
		{d: 'bing', q: 'q'},
		{d: 'images.google', q: 'q'},
		{d: 'images.search.yahoo.com', q: 'p'},
		{d: 'aol', q: 'query'},
		{d: 'aol', q: 'encquery'},
		{d: 'aol', q: 'q'},
		{d: 'lycos', q: 'query'},
		{d: 'ask', q: 'q'},
		{d: 'altavista', q: 'q'},
		{d: 'netscape', q: 'query'},
		{d: 'cnn', q: 'query'},
		{d: 'about', q: 'terms'},
		{d: 'mamma', q: 'q'},
		{d: 'daum', q: 'q'},
		{d: 'eniro', q: 'search_word'},
		{d: 'naver', q: 'query'},
		{d: 'pchome', q: 'q'},
		{d: 'alltheweb', q: 'q'},
		{d: 'voila', q: 'rdata'},
		{d: 'virgilio', q: 'qs'},
		{d: 'live', q: 'q'},
		{d: 'baidu', q: 'wd'},
		{d: 'alice', q: 'qs'},
		{d: 'yandex', q: 'text'},
		{d: 'najdi', q: 'q'},
		{d: 'mama', q: 'query'},
		{d: 'seznam', q: 'q'},
		{d: 'search', q: 'q'},
		{d: 'wp', q: 'szukaj'},
		{d: 'onet', q: 'qt'},
		{d: 'szukacz', q: 'q'},
		{d: 'yam', q: 'k'},
		{d: 'kvasir', q: 'q'},
		{d: 'sesam', q: 'q'},
		{d: 'ozu', q: 'q'},
		{d: 'terra', q: 'query'},
		{d: 'mynet', q: 'q'},
		{d: 'ekolay', q: 'q'},
		{d: 'rambler', q: 'query'},
		{d: 'rambler', q: 'words'}
	],
	/**
	 * GET params parsed from URL
	 */ 
	urlParams: {},
	/**
	 * DOM stream Event Binding Methods
	 */ 
	streamBindings : ['bindMovementEvents', 'bindScrollEvents','bindKeypressEvents', 'bindClickEvents'],
	/**
	 * Latest click event
	 */
	click : '',
	/**
	 * Domstream event
	 */
	domstream : '',
	/**
	 * Latest Movement Event
	 */
	movement : '',
	/**
	 * Latest Keystroke Event
	 */
	keystroke : '',
	/**
	 * Latest Hover Event
	 */
	hover : '',
	
	last_event : '',
	last_movement : '',
	/**
	 * DOM Stream Event Queue
	 */
	event_queue : [],
	player: '',
	overlay: '',
	
	setDebug : function(bool) {
		
		OWA.setSetting('debug', bool);
	},
	
	/**
	 * Looks for shared state cookies passed on the URL from OWA running
	 * under anohter domain. 
	 *
	 * This method must be called explicitly before any of the tracking 
	 * methods if you want shared state cookies ot be respected.
	 *
	 */
	checkForLinkedState : function() {
		
		if ( this.linkedStateSet != true ) {
		
			var ls = this.getUrlParam(OWA.getSetting('ns') + 'state');
			
			if ( ! ls ) {
				ls = this.getAnchorParam(OWA.getSetting('ns') + 'state');
			}
			
			if ( ls ) {
				OWA.debug('Shared OWA state detected...');
				
				ls = OWA.util.base64_decode(OWA.util.urldecode(ls));
				//ls = OWA.util.trim(ls, '\u0000');
				//ls = OWA.util.trim(ls, '\u0000');	
				OWA.debug('linked state: %s', ls);
				
				var state = ls.split('.');
				//var state = OWA.util.explode('.', ls);
				OWA.debug('linked state: %s', JSON.stringify(state));
				if ( state ) {
				
					for (var i=0; state.length > i; i++) {
						
						var pair = state[i].split('=');
						OWA.debug('pair: %s', pair);
						// add cookie domain hash for current cookie domain
						var value = OWA.util.urldecode(pair[1]);
						OWA.debug('pair: %s', value);
						//OWA.debug('about to decode shared link state value: %s', value);
						decodedvalue = OWA.util.decodeCookieValue(value);
						//OWA.debug('decoded shared link state value: %s', JSON.stringify(decodedvalue));
						var format = OWA.util.getCookieValueFormat(value);
						//OWA.debug('format of decoded shared state value: %s', format);
						decodedvalue.cdh = OWA.util.getCookieDomainHash( this.getCookieDomain() );
						
						OWA.replaceState( pair[0], decodedvalue, true, format );	
					}
				}
			}
			
			this.linkedStateSet = true;
		}
	},
	
	/**
	 * Shares User State cross domains using GET string
 	 *
	 * gets cookies and concatenates them together using:
	 * name1=encoded_value1.name2=encoded_value2
	 * then base64 encodes the entire string and appends it
	 * to an href
	 * 
	 * @param	url	string
	 */
	shareStateByLink : function(url) {
	
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
	},
	
	createSharedStateValue : function() {
		
		var state = '';

		for (var i=0; this.sharableStateStores.length > i;i++) {
			var value = OWA.getState( this.sharableStateStores[i] );
			value = OWA.util.encodeJsonForCookie(value, OWA.getStateStoreFormat(this.sharableStateStores[i]));
			
			if (value) {
				state += OWA.util.sprintf( '%s=%s', this.sharableStateStores[i], OWA.util.urlEncode(value) );					
				if ( this.sharableStateStores.length != ( i + 1) ) {
					state += '.';
				}
			}
		}
		
		// base64 for transport
		if ( state ) {
			OWA.debug('linked state to send: %s', state);
			
			state = OWA.util.base64_encode(state);
			state = OWA.util.urlEncode(state);
			return state;
		}
	},
	
	shareStateByPost : function (form) {

		var state = this.createSharedStateValue();
		form.action += '#' + OWA.getSetting('ns') + 'state.' + state;
		form.submit();
	},

	getCookieDomain : function() {
	
		return this.getOption('cookie_domain') || OWA.getSetting('cookie_domain') || document.domain;

	},
	
	setCookieDomain : function(domain) {
		
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
	},
	
	getCookieDomainHash: function(domain) {
		
		return OWA.util.crc32(domain);
	},
	
	setCookieDomainHashing: function(value) {
		this.hashCookiesToDomain = value;
		OWA.setSetting('hashCookiesToDomain', value);
	},
	
	checkForOverlaySession: function() {
		
		// check to see if overlay sesson should be created
		var a = this.getAnchorParam( OWA.getSetting('ns') + 'overlay');
		
		if ( a ) {
			a = OWA.util.base64_decode(OWA.util.urldecode(a));
			//a = OWA.util.trim(a, '\u0000');
			a = OWA.util.urldecode( a );
			OWA.debug('overlay anchor value: ' + a);
			//var domain = this.getCookieDomain();
			
			// set the overlay cookie
			OWA.util.setCookie( OWA.getSetting('ns') + 'overlay',a, '','/', document.domain );
			//alert(OWA.util.readCookie('owa_overlay') );
			// pause tracker so we dont log anything during an overlay session
			this.pause();
			// start overlay session
			OWA.startOverlaySession( OWA.util.decodeCookieValue( a ) );
		}			
	},
	
	getUrlAnchorValue : function() {
	
		var anchor = self.document.location.hash.substring(1);
		OWA.debug('anchor value: ' + anchor);
		return anchor;	
	},
	
	getAnchorParam : function(name) {
	
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
	},
	
	getUrlParam : function(name) {
		
		this.urlParams = this.urlParams || OWA.util.parseUrlParams();
		
		if ( this.urlParams.hasOwnProperty( name ) ) {
			return this.urlParams[name];
		} else {
			return false;
		}
	},
	
	dynamicFunc : function (func){
		//alert(func[0]);
		var args = Array.prototype.slice.call(func, 1);
		//alert(args);
		this[func[0]].apply(this, args);
	},
	
	/**
	 * Convienence method for setting page title
	 */
	setPageTitle: function(title) {
		
		this.setGlobalEventProperty("page_title", title);
	},
	
	/**
	 * Convienence method for setting page type
	 */
	setPageType : function(type) {
		
		this.setGlobalEventProperty("page_type", type);
	},
	
	/**
	 * Convienence method for setting user name
	 */
	setUserName : function( value ) {
		
		this.setGlobalEventProperty( 'user_name', value );
	},
	
	/**
	 * Sets the siteId to be appended to all logging events
	 */
	setSiteId : function(site_id) {
		this.siteId = site_id;
	},
	
	/**
	 * Convienence method for getting siteId of the logger
	 */
	getSiteId : function() {
		return this.siteId;
	},
	
	setEndpoint : function (endpoint) {
		
		endpoint = ('https:' == document.location.protocol ? window.owa_baseSecUrl || endpoint.replace(/http:/, 'https:') : endpoint );
		this.setOption('baseUrl', endpoint);
		OWA.config.baseUrl = endpoint;
	},
	
	setLoggerEndpoint : function(url) {
		
		this.setOption( 'logger_endpoint', this.forceUrlProtocol( url ) );
	},
	
	getLoggerEndpoint : function() {
	
		var url = this.getOption( 'logger_endpoint') || this.getEndpoint() || OWA.getSetting('baseUrl') ;
		
		return url + 'log.php';
	},
	
	setApiEndpoint : function(url) {
			
		this.setOption( 'api_endpoint', this.forceUrlProtocol( url ) );
		OWA.setApiEndpoint(url);
	},
	
	getApiEndpoint : function() {
	
		return this.getOption('api_endpoint') || this.getEndpoint() + 'api.php';
	},
	
	forceUrlProtocol : function (url) {
		
		url = ('https:' == document.location.protocol ? url.replace(/http:/, 'https:') : url );
		return url;
	},

	
	getEndpoint : function() {
		return this.getOption('baseUrl');
	},
	
	getCurrentUrl : function () {
		
		return document.URL
	},
	
	bindClickEvents : function() {
	
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
	
	},
	
	setDomstreamSampleRate : function(value) {
		
		this.setOption('logDomStreamPercentage', value);
	},
	
	startDomstreamTimer : function() {
		
		var interval = this.getOption('domstreamLoggingInterval')
		var that = this;
		var domstreamTimer = setInterval(
			function(){ that.logDomStream() }, 
			interval
		);
	},
	
	/**
	 * Deprecated
	 */
	log : function() {
    	this.page.setEventType("base.page_request");
    	return this.logEvent(this.page);
    },
    
    isObjectType : function(obj, type) {
    	return !!(obj && type && type.prototype && obj.constructor == type.prototype.constructor);
	},
    
    /** 
     * Logs event by inserting 1x1 pixel IMG tag into DOM
     */
    logEvent : function (properties, block, callback) {
    	
    	if (this.active) {
    	
    		// append site_id to properties
    		properties.site_id = this.getSiteId();
    	
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
    },
        
    /**
     * Private method for helping assemble request params
     */
    _assembleRequestUrl : function(properties) {
    
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
    },

	prepareRequestData : function( properties ) {
    
  		var data = {};
    	
       	//assemble query string
	    for ( var param in properties ) {  
	    	// print out the params
			var value = '';
				
			if ( properties.hasOwnProperty( param ) ) {
	  			
	  			if ( OWA.util.is_array( properties[param] ) ) {
					
					var n = properties[param].length;
					for ( var i = 0; i < n; i++ ) {
						
						if ( OWA.util.is_object( properties[param][i] ) ) {
							for ( var o_param in properties[param][i] ) {
								
								data[ OWA.util.sprintf( OWA.getSetting('ns') + '%s[%s][%s]', param, i, o_param ) ] = OWA.util.urlEncode( properties[ param ][ i ][ o_param ] );
							}
						} else {
							// what the heck is it then. assume string
							data[ OWA.util.sprintf(OWA.getSetting('ns') + '%s[%s]', param, i) ] = OWA.util.urlEncode( properties[ param ][ i ] );
						}
					}
				// assume it's a string
				} else {
					data[ OWA.util.sprintf(OWA.getSetting('ns') + '%s', param) ] = OWA.util.urlEncode( properties[ param ] );
				}
			}
		}
		
		return data;
    },
    
    prepareRequestDataForGet : function( properties ) {
    	
    	var properties = this.prepareRequestData( properties );

    	var get = '';
    	
    	for ( var param in properties ) {
    		
    		if ( properties.hasOwnProperty( param ) ) {

    			var kvp = '';
    			kvp = OWA.util.sprintf('%s=%s&', param, properties[ param ] );
    			get += kvp;
    		}
    	}
    	
    	return get;
    },

    
    /** 
	 * Issues a cross-domain http post
	 *
	 * This method generates a 1x1 iframe with a form in it that is
	 * populated by whatever data is passed to it. The http response cannot be evaluated
	 * So this is really only to be used as an alternative to the GET tracking request
	 */
	cdPost : function ( data ) {
		
		var container_id = "owa-tracker-post-container";
		var post_url = this.getLoggerEndpoint();
		
		var iframe_container = document.getElementById( container_id );
		
		// create iframe container if necessary
		if ( ! iframe_container ) {
		
			// create post frame container	
			var div = document.createElement( 'div' );
			div.setAttribute( 'id', container_id );
			document.body.appendChild( div );
			iframe_container = document.getElementById( container_id );
		}		
		
		// create iframe and post data once its fully loaded.
		this.generateHiddenIframe( iframe_container, data );
	},
	
	/**
	 * Generates a hidden 1x1 pixel iframe
	 */
	generateHiddenIframe: function ( parentElement, data ) {
	    
	    var iframe_name = 'owa-tracker-post-iframe';
	   
	    if ( OWA.util.isIE() && OWA.util.getInternetExplorerVersion() < 9.0 ) {
			var iframe = document.createElement('<iframe name="' + iframe_name + '" scr="about:blank" width="1" height="1"></iframe>');
		} else {
			var iframe = document.createElement("iframe");
			iframe.setAttribute('name', iframe_name);
			iframe.setAttribute('src', 'about:blank');
			iframe.setAttribute('width', 1);
    		iframe.setAttribute('height', 1);
		}
	    
	    iframe.setAttribute('class', iframe_name);
    	iframe.setAttribute('style', 'border: none;');
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
            	that.postFromIframe(iframe, data);
				clearInterval(timer);
            }
			
			            
            
        }, 1 );
        
        // needed to cleanup history items in browsers like Firefox
       
        var cleanuptimer = setInterval( function() {
        	
        	
			 parentElement.removeChild(iframe);	
			 clearInterval(cleanuptimer);
            
        }, 1000 );
        
        
       
	},
	
	postFromIframe: function( ifr, data ) {
		
		var post_url = this.getLoggerEndpoint();
		var doc = this.getIframeDocument(ifr);
	    // create form
	    //var frm = this.createPostForm();
		var form_name = 'post_form' + Math.random();
		
		// cannot set the name of an element using setAttribute
		if ( OWA.util.isIE()  && OWA.util.getInternetExplorerVersion() < 9.0 ) {
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
				if ( OWA.util.isIE() && OWA.util.getInternetExplorerVersion() < 9.0 ) {
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
	},
	
	//depricated
	createPostForm : function () {
		
		var post_url = this.getLoggerEndpoint();
		var form_name = 'post_form' + Math.random();
		
		// cannot set the name of an element using setAttribute
		if ( OWA.util.isIE()  && OWA.util.getInternetExplorerVersion() < 9.0 ) {
			var frm = doc.createElement('<form name="' + form_name + '"></form>');
		} else {
			var frm = doc.createElement('form');
			frm.setAttribute( 'name', form_name );
		}
		
	    frm.setAttribute( 'id', form_name );
 		frm.setAttribute("action", post_url);
 		frm.setAttribute("method", "POST");
 		
 		return frm;
	},
	
	getIframeDocument: function ( iframe ) {
		
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
	},
	
	getViewportDimensions : function() {
	
		var viewport = new Object();
		viewport.width = window.innerWidth ? window.innerWidth : document.body.offsetWidth;
		viewport.height = window.innerHeight ? window.innerHeight : document.body.offsetHeight;
		return viewport;
	},
	
	/**
	 * Sets the X coordinate of where in the browser the user clicked
	 *
	 */
	findPosX : function(obj) {
	
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
	},
	
	/**
	 * Sets the Y coordinates of where in the browser the user clicked
	 *
	 */
	findPosY : function(obj) {
	
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
	},
	
	/**
	 * Get the HTML elementassociated with an event
	 *
	 */
	_getTarget : function(e) {
		
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
	},
	
	/**
	 * Sets coordinates of where in the browser the user clicked
	 *
	 */
	getCoords : function(e) {
		
		var coords = new Object();
		
	    if ( typeof( e.pageX ) == 'number' ) {
	    	coords.x = e.pageX + '';
	        coords.y = e.pageY + '';
	    } else {
	        coords.x = e.clientX + '';
	        coords.y = e.clientY + '';
	    }
		
	    return coords;
	},
	
	/**
	 * Sets the tag name of html eleemnt that generated the event
	 */
	getDomElementProperties : function(targ) {
		
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
	},
		
	clickEventHandler : function(e) {
		
		// hack for IE
		e = e || window.event;
		
		var click = new OWA.event();
		// set event type	
		click.setEventType("dom.click");
		
		//clicked DOM element properties
	    var targ = this._getTarget(e);
	    
	    var dom_name = '(not set)';
	    if ( targ.hasAttribute('name') && targ.name.length > 0 ) {
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
	    
	    click.set("dom_element_tag", OWA.util.strtolower(targ.tagName)); 
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
		var full_click = OWA.util.clone(click);
		//if all that works then log
		if (this.getOption('logClicksAsTheyHappen')) {
			this.trackEvent(full_click);
		}
		
				
		this.click = full_click;
	},
	
	// stub for a filter that will strip certain properties or abort the logging
	filterDomProperties : function(properties) {
		
		return properties;
		
	},
		
	callMethod : function(string, data) {
		
		return this[string](data);
	},
	
	addDomStreamEventBinding : function(method_name) {
		this.streamBindings.push(method_name);
	},
		
	bindMovementEvents : function() {
		
		var that = this;
		document.onmousemove = function (e) {that.movementEventHandler(e);}
	},
		
	movementEventHandler : function(e) {
		
		// hack for IE
		e = e || window.event;
		var now = this.getTime();
		if (now > this.last_movement + this.getOption('movementInterval')) {
			// set event type	
			this.movement = new OWA.event();
			this.movement.setEventType("dom.movement");
			var coords = this.getCoords(e);
			this.movement.set('cursor_x', coords.x);
			this.movement.set('cursor_y', coords.y);
			this.addToEventQueue(this.movement);
			this.last_movement = now;
		}
		
	},
	
	bindScrollEvents : function() {
		
		var that = this;
		window.onscroll = function (e) { that.scrollEventHandler( e ); }
	},
	
	scrollEventHandler : function(e) {
				
		// hack for IE
		var e = e || window.event;
		
		var now = this.getTimestamp();
		
		var event = new OWA.event();
		event.setEventType('dom.scroll');
		var coords = this.getScrollingPosition();
		event.set('x', coords.x);
		event.set('y', coords.y);
	    this.addToEventQueue(event);
		this.last_scroll = now;

	},
	
	getScrollingPosition : function() {
	
		var position = [0, 0];
		if (typeof window.pageYOffset != 'undefined') {
			position = {x: window.pageXOffset, y: window.pageYOffset};
		} else if (typeof document.documentElement.scrollTop != 'undefined' && document.documentElement.scrollTop > 0) {
			position = {x: document.documentElement.scrollLeft, y: document.documentElement.scrollTop};
		} else if (typeof document.body.scrollTop != 'undefined') {
			position = {x: document.body.scrollLeft, y:	document.body.scrollTop};
		}
		return position;
	},
	
	bindHoverEvents : function() {
		
		//handler = handler || this.hoverEventHandler;
		//document.onmousemove = handler;

	},
	
	bindFocusEvents : function() {
	
		var that = this;
	
	},
	
	bindKeypressEvents : function() {
		
		var that = this;
		document.onkeypress = function (e) {that.keypressEventHandler(e);}

	},
	
	keypressEventHandler : function(e) {
	
		e = e || window.event;
		
		var targ = this._getTarget(e);
		
		if (targ.tagName === 'INPUT' && targ.type === 'password') {
			return;
		}
		
		var key_code = e.keyCode? e.keyCode : e.charCode
		var key_value = String.fromCharCode(key_code); 
		var event = new OWA.event();
		event.setEventType('dom.keypress');
		event.set('key_value', key_value);
		event.set('key_code', key_code);
		event.set("dom_element_name", targ.name);
	    event.set("dom_element_value", targ.value);
	    event.set("dom_element_id", targ.id);
	    event.set("dom_element_tag", targ.tagName);
    	//console.log("Keypress: %s %d", key_value, key_code);
		this.addToEventQueue(event);
		
	},
	
	// utc epoch in seconds
	getTimestamp : function() {
		
		return OWA.util.getCurrentUnixTimestamp();
	},
	
	// utc epoch in milliseconds
	getTime : function() {
	
		return Math.round(new Date().getTime());
	},
	
	getElapsedTime : function() {
		
		return this.getTimestamp() - this.startTime;
	},
	
	getOption : function(name) {
		
		if ( this.options.hasOwnProperty(name) ) {
			return this.options[name];
		}
	},
	
	setOption : function(name, value) {
		
		this.options[name] = value;
	},
	
	setLastEvent : function(event) {
		return;
	},
	
	addToEventQueue : function(event) {
		
		if (this.active && !this.isPausedBySibling()) {
					
			var now = this.getTimestamp();
			
			if (event != undefined) {
				this.event_queue.push(event.getProperties());
				//console.debug("Now logging %s for: %d", event.get('event_type'), now);
			} else {
				//console.debug("No event properties to log");
			}
					
		}
	},
	
	isPausedBySibling: function() {
			
		return OWA.getSetting('loggerPause');
	},
	
	sleep : function(delay) {
    	var start = new Date().getTime();
    	while (new Date().getTime() < start + delay);
	},
	
	pause : function() {
		
		this.active = false;
	},
	
	restart : function() {
		this.active = true;
	},
	
	// Event object Factory
	makeEvent : function() {
		return new OWA.event();
	},
	
	// adds a new Domstream event binding. takes function name
	addStreamEventBinding : function(name) {
		
		this.streamBindings.push(name);
	},
	
	// gets campaign related properties from request scope.
	getCampaignProperties : function() {
		
		// load GET params from URL
		if (!this.urlParams.length > 0)	{	
			this.urlParams = OWA.util.parseUrlParams(document.URL);
			OWA.debug('GET: '+ JSON.stringify(this.urlParams));
		}
		
		// look for attributes in the url of the page
		var campaignKeys = this.getOption('campaignKeys');
		
		// pull campaign params from _GET
		var campaign_params = {};
		
		for (var i = 0, n = campaignKeys.length; i < n; i++) {
			
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
		
		if (this.isNewCampaign) {
			//campaign_params['ts'] = this.page.get('timestamp');
		}
		
		return campaign_params;
	},
	
	setCampaignSessionState : function( properties ) {
		
		var campaignKeys = this.getOption('campaignKeys');
		for (var i = 0, n = campaignKeys.length; i < n; i++) {
			if ( properties.hasOwnProperty(campaignKeys[i].private) ) {
				
				OWA.setState('s', campaignKeys[i].full, properties[campaignKeys[i].private]);
			}
		}
	},
	
	// used when in third party cookie mode to send raw campaign related
	// properties as part of the event. upstream handler needs these to
	// do traffic attribution.
	setCampaignRelatedProperties : function( event ) {
		var properties = this.getCampaignProperties();
		OWA.debug('campaign properties: %s', JSON.stringify(properties));
		
		var campaignKeys = this.getOption('campaignKeys');
		for (var i = 0, n = campaignKeys.length; i < n; i++) {
			if ( properties.hasOwnProperty(campaignKeys[i].private) ) {
				this.setGlobalEventProperty(campaignKeys[i].full, properties[campaignKeys[i].private]);
			}
		}
	},
	
	directAttributionModel : function(campaign_params) {
		
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
	},
	
	originalAttributionModel : function( campaign_params ) {
		
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
		
	},
	
	setCampaignMediumKey : function ( key ) {
		
		this.options.campaignKeys[0].public = key;
	},
	
	setCampaignNameKey : function ( key ) {
		
		this.options.campaignKeys[1].public = key;
	},
	
	setCampaignSourceKey : function ( key ) {
		
		this.options.campaignKeys[2].public = key;
	},
	
	setCampaignSearchTermsKey : function ( key ) {
		
		this.options.campaignKeys[3].public = key;
	},
	
	setCampaignAdKey : function ( key ) {
		
		this.options.campaignKeys[4].public = key;
	},
	
	setCampaignAdTypeKey : function ( key ) {
		
		this.options.campaignKeys[5].public = key;
	},

		
	setTrafficAttribution : function( event, callback ) {
		
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
				OWA.debug( 'Infering traffic attribution.' );
				this.inferTrafficAttribution();
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
	},
	
	inferTrafficAttribution : function() {
		
		var ref = document.referrer;
		var medium = 'direct';
		var source = '(none)';
		var search_terms = '(none)';
		var session_referer = '(none)';
		
		if ( ref ) {
			var uri = new OWA.uri( ref );
			
			// check for external referer
			
			if ( document.domain != uri.getHost() ) {			
						
				medium = 'referral';
				session_referer = ref;
				source = OWA.util.stripWwwFromDomain( uri.getHost() );
				var engine = this.isRefererSearchEngine( uri );
				if ( engine ) {
					medium = 'organic-search';
					search_terms = engine.t || '(not provided)';
				} 
			}
		}
		
		OWA.setState( 's', 'referer', session_referer );
		OWA.setState( 's', 'medium', medium );
		OWA.setState( 's', 'source', source );
		OWA.setState( 's', 'search_terms', search_terms );
	},

	
	setCampaignCookie : function( values ) {
		OWA.setState( 'c', 'attribs', values, '', 'json', this.options.campaignAttributionWindow );
	},
	
	isRefererSearchEngine : function ( uri ) {
		
		for ( var i = 0, n = this.organicSearchEngines.length; i < n; i++ ) {
			
			var domain = this.organicSearchEngines[i].d;
			var query_param = this.organicSearchEngines[i].q
			var host = uri.getHost();
			var term = uri.getQueryParam(query_param);
			
			if ( OWA.util.strpos(host, domain) ) {
				OWA.debug( 'Found search engine: %s with query param %s:, query term: %s', domain, query_param, term);
				
				return {d: domain, q: query_param, t: term };
			}
		}
	},
	
	addOrganicSearchEngine : function( domain, query_param, prepend) {
		
		var engine = {d: domain, q: query_param};
		if (prepend) {
			this.organicSearchEngines.unshift(engine);
		} else {
				this.organicSearchEngines.push(engine);
		}
	},
	
	addTransaction : function ( order_id, order_source, total, tax, shipping, gateway, city, state, country ) {
		this.ecommerce_transaction = new OWA.event();
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
	},
	
	addTransactionLineItem : function ( order_id, sku, product_name, category, unit_price, quantity ) {
	
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
	},
	
	trackTransaction : function () {
		
		if ( this.ecommerce_transaction ) {
			this.trackEvent( this.ecommerce_transaction );
			this.ecommerce_transaction = '';
		}
	},
	
	setNumberPriorSessions : function ( event, callback ) {
		
		OWA.debug('setting number of prior sessions');
		// if check for nps value in vistor cookie.
		var nps = OWA.getState( 'v', 'nps' );
		// set value to 1 if not found as it means its he first session.
		if ( ! nps ) {
			nps = '0';
		}
		
		if ( this.isNewSessionFlag === true ) {
			// increment visit count and persist to state store
			nps = nps * 1;
			nps++;
			OWA.setState( 'v', 'nps', nps, true );
		}

		this.setGlobalEventProperty( 'nps',  nps );
		
		if (callback && (typeof(callback) === "function")) {
			callback(event);
		}
	},
	
	setDaysSinceLastSession : function ( event, callback ) {
		
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
	},
	
	setVisitorId : function( event, callback ) {
		
		var visitor_id =  OWA.getState( 'v', 'vid' );
		//OWA.debug('vid: '+ visitor_id);
		if ( ! visitor_id ) {
			var old_vid_test =  OWA.getState( 'v' );
			//OWA.debug('vid: '+ visitor_id);
			
			if ( ! OWA.util.is_object( old_vid_test ) ) {
				visitor_id = old_vid_test;
				OWA.clearState( 'v' );
				OWA.setState( 'v', 'vid', visitor_id, true );
				
			}
		}
				
		if ( ! visitor_id ) {
			visitor_id = OWA.util.generateRandomGuid( this.siteId );
			
			this.globalEventProperties.is_new_visitor = true;
			OWA.debug('Creating new visitor id');
		} 
		// set property on event object
		OWA.setState( 'v', 'vid', visitor_id, true );
		this.setGlobalEventProperty( 'visitor_id', visitor_id );
		
		if (callback && (typeof(callback) === "function")) {
			callback(event);
		}
	},
	
	setFirstSessionTimestamp : function( event, callback ) {
		
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
	},
	
	setLastRequestTime : function( event, callback ) {
		
		
		var last_req = OWA.getState('s', 'last_req');
		OWA.debug('last_req from cookie: %s', last_req);
		// suppport for old style cookie
		if ( ! last_req ) {
			var state_store_name = OWA.util.sprintf( '%s_%s', 'ss', this.siteId );		
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
	},
	
	setSessionId : function ( event, callback ) {
		var session_id = '';
		var state_store_name = '';
		var is_new_session = this.isNewSession( event.get( 'timestamp' ),  this.getGlobalEventProperty( 'last_req' ) ); 
		if ( is_new_session ) {
			//set prior_session_id
			var prior_session_id = OWA.getState('s', 'sid');
			if ( ! prior_session_id ) {
				state_store_name = OWA.util.sprintf('%s_%s', 'ss', this.getSiteId() );		
				prior_session_id = OWA.getState(state_store_name, 's');
			}
			if ( prior_session_id ) {
				this.globalEventProperties.prior_session_id = prior_session_id;
			}
			
			this.resetSessionState();
			
			session_id = OWA.util.generateRandomGuid( this.getSiteId() );
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
				state_store_name = OWA.util.sprintf( '%s_%s', 'ss', this.getSiteId() );		
				session_id = OWA.getState(state_store_name, 's');
				OWA.setState( 's', 'sid', session_id, true );	
			}
		
			this.globalEventProperties.session_id = session_id;
		}
		
		// fail-safe just in case there is no session_id 
		if ( ! this.getGlobalEventProperty( 'session_id' ) ) {
			session_id = OWA.util.generateRandomGuid( this.getSiteId() );
			this.globalEventProperties.session_id = session_id;
			//mark new session flag on current request
			this.globalEventProperties.is_new_session = true;
			this.isNewSessionFlag = true;
			OWA.setState( 's', 'sid', session_id, true );
		}
		
		if (callback && (typeof(callback) === "function")) {
			callback(event);
		}

	},
	
	resetSessionState : function() {
		
		var last_req = OWA.getState( 's', 'last_req');
		OWA.clearState('s');
		OWA.setState('s', 'last_req', last_req);
	},
	
	isNewSession : function( timestamp, last_req ) {
		
		var is_new_session = false;
		
		if ( ! timestamp ) {
			timestamp = OWA.util.getCurrentUnixTimestamp();
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
	},
	
	getGlobalEventProperty : function( name ) {
	
		if ( this.globalEventProperties.hasOwnProperty(name) ) {
		
			return this.globalEventProperties[name];
		}
	},
	
	setGlobalEventProperty : function (name, value) {
		
		this.globalEventProperties[name] = value;
	},
	
	deleteGlobalEventProperty : function ( name ) {
		
		if ( this.globalEventProperties.hasOwnProperty( name ) ) {
		
			delete this.globalEventProperties[name];
		}
	},
	
	setPageProperties : function ( properties ) {
		
		for (var prop in properties) {
			
			if ( properties.hasOwnProperty( prop ) ) {
				this.page.set( prop, properties[prop] );
			}
		}
	},
	
	/**
	 * Set a custom variable
	 *
	 * @param	slot	int		the identifying number for the custom variable. 1-5.
	 * @param	name	string	the key of the custom variable.
	 * @param	value	string	the value of the varible
	 * @param	scope	string	the scope of the variable. can be page, session, or visitor
	 */
	setCustomVar : function(slot, name, value, scope) {
		
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
				OWA.util.setState('b', cv_param_name, cv_param_value);
				OWA.debug('just set custom var on session.');
				break;
				
			case 'visitor':
				
				// store in visitor cookie
				OWA.util.setState('v', cv_param_name, cv_param_value);
				// remove slot from session level cookie
				OWA.util.clearState('b', cv_param_name);
				break;
		}
		
		this.setGlobalEventProperty(cv_param_name, cv_param_value);	
	},
	
	getCustomVar : function(slot) {
		
		var cv_param_name = 'cv' + slot;
		var cv = '';
		// check request/page level
		cv = this.getGlobalEventProperty( cv_param_name );
		//check session store
		if ( ! cv ) {
			cv = OWA.util.getState( 'b', cv_param_name );
		}
		// check visitor store
		if ( ! cv ) {
			cv = OWA.util.getState( 'v', cv_param_name );
		}
		
		return cv;
		
	},
	
	deleteCustomVar : function(slot) {
		
		var cv_param_name = 'cv' + slot;
		//clear session level
		OWA.util.clearState( 'b', cv_param_name );
		//clear visitor level
		OWA.util.clearState( 'v', cv_param_name );
		// clear page level
		this.deleteGlobalEventProperty( cv_param_name )
	},
	
	/**
     * Applies default values for required properties 
     * to any event where the properties were not
     * already set globally or locally.
     */
    addDefaultsToEvent : function ( event, callback ) {
    	
    	
    	if ( ! event.get( 'page_url') ) {
    		event.set('page_url', this.getCurrentUrl() );
    	}
    	
    	if ( ! event.get( 'HTTP_REFERER') ) {
    		event.set('HTTP_REFERER', document.referrer );
    	}
    	
    	if ( ! event.get( 'page_title') ) {
    		event.set('page_title', OWA.util.trim( document.title ) );
    	}
   		
   		if (callback && ( typeof( callback ) == 'function' ) ) {
   			callback( event );
   		}
    	
    },
	
	/**
     * Applies global properties to any event that 
     * were not already set locally by the method that
     * created the event.
     *
     */
    addGlobalPropertiesToEvent : function ( event, callback ) {

    	
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
    	
    },
	
	manageState : function( event, callback ) {
		
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
	},
	
	/** 
     * Sends an OWA event to the server for processing using GET
     * inserts 1x1 pixel IMG tag into DOM
     */
    trackEvent : function(event, block) {
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
    },
    
    /**
	 * Logs a page view event
	 */
	trackPageView : function(url) {
		
		
		if (url) {
			this.page.set('page_url', url);
		}
		// set default event properties
    	//this.setGlobalEventProperty( 'HTTP_REFERER', document.referrer );
    	//this.setPageTitle(document.title);
		this.page.set('timestamp', this.startTime);
		this.page.setEventType("base.page_request");
		
		return this.trackEvent(this.page);
	},
	
	trackAction : function(action_group, action_name, action_label, numeric_value) {
		
		var event = new OWA.event;
		
		event.setEventType('track.action');
		event.set('site_id', this.getSiteId());
		event.set('page_url', this.getCurrentUrl() );
		event.set('action_group', action_group);
		event.set('action_name', action_name);
		event.set('action_label', action_label);
		event.set('numeric_value', numeric_value);
		this.trackEvent(event);
		OWA.debug("Action logged");
	},
	
	trackClicks : function(handler) {
		// flag to tell handler to log clicks as they happen
		this.setOption('logClicksAsTheyHappen', true);
		this.bindClickEvents();		
		
	},
	
	logDomStream : function() {
    	
    	var domstream = new OWA.event;
    	
    	if ( this.event_queue.length > this.options.domstreamEventThreshold ) {
    		
			// make an domstream_id if one does not exist. needed for upstream processing
			if ( ! this.domstream_guid ) {
				var salt = 'domstream' + this.getCurrentUrl() + this.getSiteId();
				this.domstream_guid = OWA.util.generateRandomGuid( salt );
			}
			domstream.setEventType( 'dom.stream' );
			domstream.set( 'domstream_guid', this.domstream_guid );
			domstream.set( 'site_id', this.getSiteId());
			domstream.set( 'page_url', this.getCurrentUrl() );
			//domstream.set( 'timestamp', this.startTime);
			domstream.set( 'timestamp', OWA.util.getCurrentUnixTimestamp() );
			domstream.set( 'duration', this.getElapsedTime());
			domstream.set( 'stream_events', JSON.stringify(this.event_queue));
			domstream.set( 'stream_length', this.event_queue.length );
			// clear event queue now instead of waiting for new trackevent
			// which might be delayed if using an ifram to POST data
			this.event_queue = [];
			return this.trackEvent( domstream );
	
		} else {
			OWA.debug("Domstream had too few events to log.");
		}
	},
	
	trackDomStream : function() {
		
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
};

(function() {
	//if ( typeof owa_baseUrl != "undefined" ) {
	//	OWA.config.baseUrl = owa_baseUrl;
	//}
	
	if ( OWA.util.isBrowserTrackable() ) {
	
		// execute commands global owa_cmds command queue
		if ( typeof owa_cmds === 'undefined' ) {
			var q = new OWA.commandQueue();	
		} else {
			if ( OWA.util.is_array(owa_cmds) ) {
				var q = new OWA.commandQueue();
				q.loadCmds( owa_cmds );
			}
		}
		
		window['owa_cmds'] = q;
		window['owa_cmds'].process();
	}
})();