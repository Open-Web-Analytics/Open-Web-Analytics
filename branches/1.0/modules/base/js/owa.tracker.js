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

	this.properties = new Object();
	this.set('timestamp', OWA.util.getCurrentUnixTimestamp() );
}

OWA.event.prototype = {
	
	id : '',
	
	siteId : '',
	
	properties : {},
	
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
	}
}

OWA.commandQueue = function() {

	OWA.debug('Command Queue object created');
}

OWA.commandQueue.prototype = {
	asyncCmds: '',
	push : function (cmd) {
		
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
		
		// is OWATracker created?
		if ( typeof window[obj_name] == "undefined" ) {
			OWA.debug('making global object named: %s', obj_name);
			window[obj_name] = new OWA.tracker( { globalObjectName: obj_name } );
		}
		
		window[obj_name][method].apply(window[obj_name], args);
	},
	
	loadCmds: function(cmds) {
		
		this.asyncCmds = cmds;
	},
	
	process: function() {
		
		for (var i=0; i < this.asyncCmds.length;i++) {
			this.push(this.asyncCmds[i]);
		}
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
		api_endpoint: ''
		
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
		
		for (opt in options) {
			
			this.options[opt] = options[opt];
		}
	}
	
	// private vars
	this.ecommerce_transaction = '',
	this.isClickTrackingEnabled = false;
	
	// check to se if an overlay session is active
	this.checkForOverlaySession();
	
	// set default page properties
	this.page = new OWA.event();
    this.page.set('page_url', document.URL);
	this.setPageTitle(document.title);
	this.page.set("referer", document.referrer);
	this.page.set('timestamp', this.startTime);
	
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
	sharableStateStores: ['v', 's', 'c'],
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
	cookie_names: ['owa_s', 'owa_v', 'owa_c'],
	linkedStateSet: false,
	hashCookiesToDomain: true,
	/**
	 * GET params parsed from URL
	 */ 
	urlParams: {},
	/**
	 * DOM stream Event Binding Methods
	 */ 
	streamBindings : ['bindMovementEvents', 'bindScrollEvents','bindKeypressEvents', 'bindClickEvents'],
	/**
	 * Page view event
	 */
	page : '',
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
	
	checkForLinkedState : function() {
		
		var ls = this.getUrlParam('owa_state');
		
		if ( ! ls ) {
			ls = this.getAnchorParam('owa_state');
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
				document.location.href = url + '#owa_state.' + state ;
			
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
	
	shareShareByPost : function (form) {

		var state = this.createSharedStateValue();
		form.action += '#owa_state.' + state;
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
		
		/*
		if (match === true) {
			// check to see if the domain is www 
			if ( contains_www === true ) {
				// eliminate any top level domain cookies
				OWA.debug('document domain matches cookie domain and includes www. cleaning up cookies.');
				//erase the no www domain cookie (ie. .openwebanalytics.com)
				var top_domain =  document.domain.substr(4);
				OWA.util.eraseMultipleCookies(this.cookie_names, top_domain);
			}
			
		} else {
			// erase the document.domain version of all cookies (ie. www.openwebanalytics.com)
			OWA.debug('document domain does not match cookie domain. cleaning up by erasing cookies under document.domain .');
			OWA.util.eraseMultipleCookies(this.cookie_names, document.domain);
			
			
			//if ( contains_www === true) {
			//	OWA.util.eraseMultipleCookies(this.cookie_names, document.domain.substr(4));
			//	OWA.util.eraseMultipleCookies(this.cookie_names, document.domain.substr(4));
			//}
			
		}
		*/
		
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
		var a = this.getAnchorParam('owa_overlay');
		
		if ( a ) {
			a = OWA.util.base64_decode(OWA.util.urldecode(a));
			//a = OWA.util.trim(a, '\u0000');
			a = OWA.util.urldecode( a );
			OWA.debug('overlay anchor value: ' + a);
			//var domain = this.getCookieDomain();
			
			// set the overlay cookie
			OWA.util.setCookie('owa_overlay',a, '','/', document.domain );
			////alert(OWA.util.readCookie('owa_overlay') );
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
	 * Convienence method for seting page title
	 */
	setPageTitle: function(title) {
		
		this.page.set("page_title", title);
	},
	
	/**
	 * Convienence method for seting page type
	 */
	setPageType : function(type) {
		
		this.page.set("page_type", type);
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
	
	/**
	 * Logs a page view event
	 */
	trackPageView : function(url) {
		
		if (url) {
			this.page.set('page_url', url);
		}
		
		this.page.setEventType("base.page_request");
		
		return this.trackEvent(this.page);
	},
	
	trackAction : function(action_group, action_name, action_label, numeric_value) {
		
		var event = new OWA.event;
		
		event.setEventType('track.action');
		event.set('site_id', this.getSiteId());
		event.set('page_url', this.page.get('page_url'));
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
	
	bindClickEvents : function() {
	
		if ( ! this.isClickTrackingEnabled ) {
			var that = this;
			// Registers the handler for the before navigate event so that the dom stream can be logged
			if (window.addEventListener) {
				window.addEventListener('click', function (e) {that.clickEventHandler(e);}, false);
			} else if(window.attachEvent) {
				window.attachEvent('click', function (e) {that.clickEventHandler(e);});
			}
			
			this.isClickTrackingEnabled = true;
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
	},
	
	logDomStream : function() {
    	
    	this.domstream = this.domstream || new OWA.event;
    	
    	if ( this.event_queue.length > this.options.domstreamEventThreshold ) {
    		
			// make an domstream_id if one does not exist. needed for upstream processing
			if ( ! this.domstream.get('domstream_guid') ) {
				var salt = 'domstream' + this.page.get('page_url') + this.getSiteId();
				this.domstream.set( 'domstream_guid', OWA.util.generateRandomGuid( salt ) );
			}
			
			this.domstream.setEventType( 'dom.stream' );
			this.domstream.set( 'site_id', this.getSiteId());
			this.domstream.set( 'page_url', this.page.get('page_url') );
			//this.domstream.set( 'timestamp', this.startTime);
			this.domstream.set( 'timestamp', OWA.util.getCurrentUnixTimestamp() );
			this.domstream.set( 'duration', this.getElapsedTime());
			this.domstream.set( 'stream_events', JSON.stringify(this.event_queue));
			this.domstream.set( 'stream_length', this.event_queue.length );
			this.trackEvent( this.domstream );
			this.event_queue = [];
	
		} else {
			OWA.debug("Domstream had too few events to log.");
		}
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
    
    /**
     * Logs event asyncronously using AJAX GET
     */
    logEventAjax : function (event, method) {
    	if (this.active) {
    		
    		if (event instanceof OWA.event) { 
	    		var properties = event.getProperties(); 
	    	} else {
	    		var properties = event;
	    	}
	    	
	    	method = method || 'GET';
	    	
	    	if (method === 'GET') {
	    		return this.ajaxGet(properties);
	    	} else {
	    		this.ajaxPost(properties);
	    		return;
	    	}
    		
    	}
    	
    	
    },
    
    isObjectType : function(obj, type) {
    	return !!(obj && type && type.prototype && obj.constructor == type.prototype.constructor);
	},

    
    /**
     * Gets XMLHttpRequest Object
     */
    getAjaxObj : function() {
    
    	if (window.XMLHttpRequest){
			// If IE7, Mozilla, Safari, etc: Use native object
			var ajax = new XMLHttpRequest()
		} else {
			
			if (window.ActiveXObject){
		          // ...otherwise, use the ActiveX control for IE5.x and IE6
		          var ajax = new ActiveXObject("Microsoft.XMLHTTP"); 
			}
	
		}
		return ajax;
    },
    
    ajaxGet : function(properties) {
    	
    	var url = this._assembleRequestUrl(properties);
		var ajax = this.getAjaxObj();
		ajax.open("GET", url, false); 
		ajax.send(null);
    },
    
    /**
     * AJAX POST Request
     */
    ajaxPost : function(properties) {
    	
    	var ajax = this.getAjaxObj();
	    var params = this.prepareRequestParams(properties);
	    
		ajax.open("POST", this.getLoggerEndpoint(), false); 
		//Send the proper header information along with the request
		ajax.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
		ajax.setRequestHeader("Content-length", params.length);
		ajax.setRequestHeader("Connection", "close");
		
		ajax.onreadystatechange = function() {//Call a function when the state changes.
			if(ajax.readyState == 4 && ajax.status == 200) {
				//console.log("ajax response: %s", ajax.responseText);
			}
		}

		ajax.send(params);
    	
    },
    
    ajaxJsonp : function (url) {                
	   
	    var script = document.createElement("script");        
	    script.setAttribute("src",url);
	    script.setAttribute("type","text/javascript");                
	    document.body.appendChild(script);
	},
    
    prepareRequestParams : function(properties) {
    
  		var get = '';
    	
    	// append site_id to properties
    	properties.site_id = this.getSiteId();
    	//assemble query string
	    for ( param in properties ) {  
	    	// print out the params
			var value = '';
			var kvp = '';
				
			if ( properties.hasOwnProperty(param) ) {
	  			
	  			if ( OWA.util.is_array( properties[param] ) ) {
				
					for ( var i = 0, n = properties[param].length; i < n; i++ ) {
						
						if ( OWA.util.is_object( properties[param][i] ) ) {
							for ( o_param in properties[param][i] ) {
								kvp = OWA.util.sprintf('owa_%s[%s][%s]=%s&', param, i, o_param, OWA.util.urlEncode( properties[param][i][o_param] ) );
								get += kvp;
							}
						} else {
							// what the heck is it then. assum string
							kvp = OWA.util.sprintf('owa_%s[%s]=%s&', param, i, OWA.util.urlEncode( properties[param][i] ) );
							get += kvp;
						}
					}
				// assume it's a string
				} else {
					kvp = OWA.util.sprintf('owa_%s=%s&', param, OWA.util.urlEncode( properties[param] ) );
					
				}
			
				
    		//needed?	
	    	} else {
    	
    			kvp = OWA.util.sprintf('owa_%s=%s&', '', OWA.util.urlEncode( properties[param] ) );
    		}
    		
    		get += kvp;
		}
		//OWA.debug('GET string: %s', get);
		return get;
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
    	
    	if ( this.linkedStateSet != true ) {
    		//check for linked state send from another domain
			this.checkForLinkedState();
    	}
    	
    	if ( this.active ) {
	    	if ( ! block ) {
	    		block_flag = false;
	    	} else {
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
	    		this.manageState(event);
	    	}
	    	
	    	this.addGlobalPropertiesToEvent( event );
	    	//OWA.debug('post global event: %s', JSON.stringify(event));
	    	return this.logEvent( event.getProperties(), block_flag );
	    }
    },
    
    addGlobalPropertiesToEvent : function ( event ) {
    	OWA.debug( 'Adding global properties to event: %s', JSON.stringify(this.globalEventProperties) );	
    	for ( prop in this.globalEventProperties ) {
    		event.set( prop, this.globalEventProperties[prop] );
    	}
    },
    

    /** 
     * Logs event by inserting 1x1 pixel IMG tag into DOM
     */
    logEvent : function (properties, block) {
    	
    	if (this.active) {
    	
	    	var url = this._assembleRequestUrl(properties);
	    	OWA.debug('url : %s', url);
		   	image = new Image(1, 1);
		   	//expireDateTime = now.getTime() + delay;
		   	image.onLoad = function () { };
			image.src = url;
			if (block) {
				//OWA.debug(' blocking...');
			}
			OWA.debug('Inserted web bug for %s', properties['event_type']);
		}
    },
        
    /**
     * Private method for helping assemble request params
     */
    _assembleRequestUrl : function(properties) {
    
    	// append site_id to properties
    	properties.site_id = this.getSiteId();
    	var get = this.prepareRequestParams(properties);
    	
    	var log_url = this.getLoggerEndpoint();
    	
    	if (log_url.indexOf('?') === -1) {
    		log_url += '?';
    	} else {
    		log_url += '&';
    	}
    	    	
		// add some radomness for cache busting
		return log_url + get;
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
		
		// hack for IE7
		e = e || window.event;
		
		var click = new OWA.event();
		// set event type	
		click.setEventType("dom.click");
		
		//clicked DOM element properties
	    var targ = this._getTarget(e);
	    
	    var dom_name = '(not set)';
	    if ( targ.hasOwnProperty( 'name' ) && targ.name.length > 0 ) {
	    	dom_name = targ.name;
	    }
	    click.set("dom_element_name", dom_name);
	    
	    var dom_value = '(not set)';
	    if ( targ.hasOwnProperty( 'value' ) && targ.value.length > 0 ) { 
	    	dom_value = targ.value;
	    }
	    click.set("dom_element_value", dom_value);
	    
	    var dom_id = '(not set)';
	    if ( ! targ.hasOwnProperty( 'id' ) && targ.id.length > 0) {
	    	dom_id = targ.id;
	    }
	    click.set("dom_element_id", dom_id);
	    
	    var dom_class = '(not set)';
	    if ( targ.hasOwnProperty( 'className' ) && targ.className.length > 0) {
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
		
		// hack for IE7
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
		window.onscroll = function (e) {that.scrollEventHandler(e);}
	},
	
	scrollEventHandler : function(e) {
				
		// hack for IE7
		e = e || window.event;
		
		var now = this.getTimestamp();
		
		var event = new OWA.event();
		event.setEventType('dom.scroll');
		var coords = this.getScrollingPosition();
		event.set('x', coords.x);
		event.set('y', coords.y);
		var targ = this._getTarget(e);
		event.set("dom_element_name", targ.name);
	    event.set("dom_element_value", targ.value);
	    event.set("dom_element_id", targ.id);
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
	
	applyCampaignPropertiesToEvent : function(event, properties) {
		
		var campaignKeys = this.getOption('campaignKeys');
		for (var i = 0, n = campaignKeys.length; i < n; i++) {
			if ( properties.hasOwnProperty(campaignKeys[i].private) ) {
				this.setGlobalEventProperty(campaignKeys[i].full, properties[campaignKeys[i].private]);
				//OWA.debug('setting campaign property %s %s', campaignKeys[i].private,  properties[campaignKeys[i].private]);
			}
		}
	},
	
	// used when in third party cookie mode to send raw campaign related
	// properties as part of the event. upstream handler needs these to
	// do traffic attribution.
	setCampaignRelatedProperties : function( event ) {
		var properties = this.getCampaignProperties();
		OWA.debug('campaign properties: %s', JSON.stringify(properties));
		this.applyCampaignPropertiesToEvent( event, properties );
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
		
		return campaign_params;
		
	},
	
	setTrafficAttribution : function( event ) {
		
		var campaignState = OWA.getState( 'c', 'attribs' );
		
		if (campaignState) {
			this.campaignState = campaignState;
		}
		
		var campaign_params = this.getCampaignProperties();
		
		// choose attribution mode.	
		switch ( this.options.trafficAttributionMode ) {
			
			case 'direct':
				OWA.debug( 'Applying "Direct" Traffic Attribution Model' );
				this.directAttributionModel( campaign_params );
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
		// set attribution properties on the event object	
		if ( this.isTrafficAttributed ) {
			
			OWA.debug( 'Attributing Traffic to: %s', JSON.stringify( campaign_params ) );
		
			this.applyCampaignPropertiesToEvent( event, campaign_params );
			
			// set campaign touches
			if ( this.campaignState.length > 0 ) {
				this.setGlobalEventProperty( 'attribs', JSON.stringify( this.campaignState ) );
				//event.set( 'campaign_timestamp', campaign_params.ts );

			}
			
			// tells upstream processing to skip attribution
			//event.set( 'is_attributed', true );
		
		} else {
			OWA.debug( 'No traffic attribution.' );
		}
	
	},
	
	setCampaignCookie : function( values ) {
		OWA.setState( 'c', 'attribs', values, '', 'json', this.options.campaignAttributionWindow );
	},
	
	checkRefererForSearchEngine : function ( referer ) {
		
		var _get = OWA.util.parseUrlParams( referer );
		
		var query_params = [
				'q', 'p','search', 'Keywords','ask','keyword','keywords','kw','pattern', 				'pgm','qr','qry','qs','qt','qu','query','queryterm','question',
				'sTerm','searchfor','searchText','srch','su','what'
		];
		
		for ( var i = 0, n = query_params.length; i < n; i++ ) {
			if ( _get[query_params[i]] ) {
				OWA.debug( 'Found search engine query param: ' + query_params[i] );
				return true;
			}
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
		this.ecommerce_transaction.set( 'page_url', this.page.get('page_url') );
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
	
	manageState : function( event ) {
		
		if ( ! this.stateInit ) {
			this.setVisitorId( event );
			this.setFirstSessionTimestamp( event );
			this.setLastRequestTime( event );
			this.setSessionId( event );
			this.setNumberPriorSessions( event );
			this.setTrafficAttribution( event );
			this.stateInit = true;
		}
	},
	
	setNumberPriorSessions : function ( event ) {
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

		this.globalEventProperties.num_prior_sessions =  nps;
		
	},
	
	setVisitorId : function( event ) {
		
		var visitor_id =  OWA.getState( 'v', 'vid' );

		if ( ! visitor_id ) {
			visitor_id =  OWA.getState( 'v' );
			
			if (visitor_id) {
				OWA.clearState( 'v' );
				OWA.setState( 'v', 'vid', visitor_id, true );
			}
		}
				
		if ( ! visitor_id ) {
			visitor_id = OWA.util.generateRandomGuid( this.siteId );
			
			this.globalEventProperties.is_new_visitor = true;
			OWA.setState( 'v', 'vid', visitor_id, true );
			OWA.debug('Creating new visitor id');
		}
		// set property on event object
		this.globalEventProperties.visitor_id = visitor_id ;
	},
	
	setFirstSessionTimestamp : function( event ) {
		var fsts = OWA.getState( 'v', 'fsts' );
		
		if ( ! fsts ) {
			fsts = event.get('timestamp');
			OWA.debug('setting fsts value: %s', fsts);
			OWA.setState('v', 'fsts', fsts , true);	
		}
		
		this.globalEventProperties.fsts = fsts;
	},
	
	setLastRequestTime : function( event ) {
		
		var last_req = OWA.getState('s', 'last_req');
		
		// suppport for old style cookie
		if ( ! last_req ) {
			var state_store_name = OWA.util.sprintf( '%s_%s', 'ss', this.siteId );		
			last_req = OWA.getState( state_store_name, 'last_req' );	
		}
		// set property on event object
		this.globalEventProperties.last_req = last_req;
		// store new state value
		OWA.setState( 's', 'last_req', event.get( 'timestamp' ), true );
	},
	
	setSessionId : function ( event ) {
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
	
	setPageProperties : function(properties) {
		
		for (prop in properties) {
			
			if ( properties.hasOwnProperty( prop ) ) {
				this.page.set( prop, properties[prop] );
			}
		}
	}
	
};

(function() {
	//if ( typeof owa_baseUrl != "undefined" ) {
	//	OWA.config.baseUrl = owa_baseUrl;
	//}
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
})();