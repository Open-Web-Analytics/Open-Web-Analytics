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
 * Javascript Tracker Library
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
}

OWA.event.prototype = {
	
	id : '',
	
	siteId : '',
	
	properties : '',
	
	get : function(name) {
		
		return this.properties[name];
	},
	
	set : function(name, value) {
		
		this.properties[name] = value;
		return;
	},
	
	setEventType : function(event_type) {
		this.set("event_type", event_type);
		return;
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


OWA.tracker = function(caller_params, options) {
	
	this.urlParams = [];
	
	// check to see if overlay sesson is active
	var p = OWA.util.readCookie('owa_overlay');

	if (p) {
		// pause tracker so we dont log anything during an overlay session
		this.pause();
		// start overlay session
		OWA.startOverlaySession(p);
	}
		
	this.setEndpoint(OWA.config.baseUrl + 'log.php');
	this.page = new OWA.event();
	this.startTime = this.getTimestamp();
    this.page.set('page_url', document.URL);
	this.setPageTitle(document.title);
	this.page.set("referer", document.referrer);
	this.page.set('timestamp', this.startTime);
	
	if (typeof owa_params != 'undefined') {
		// merge page params from the global object if it exists
		if (owa_params.length > 0) {
			this.page.merge(owa_params);
		}
	}
		
	// merge page params from map passed into the constructor
	if (typeof caller_params != 'undefined') {
		this.page.merge(caller_params);
	}
	
	// attribute traffic
	this.attributeTraffic();
}

OWA.tracker.prototype = {

	id : '',
	siteId : '',
	init: 0,
	/**
	 * Time When logger is loaded
	 */
	startTime: null,
	endTime: null,
	/**
	 * Active status of logger
	 */
	active: true,
	/**
	 * Endpoint URl of logger service
	 */
	endpoint : '',
	campaignState : [],
	isNewCampaign: false,
	isTrafficAttributed: false,
	/**
	 * Configuration options
	 */
	options : {
		logClicks: true, 
		logPage: true, 
		logMovement: false, 
		encodeProperties: true, 
		movementInterval: 100,
		logDomStreamPercentage: 100,
		domstreamEventThreshold: 5,
		maxPriorCampaigns: 5,
		campaignAttributionWindow: 60,
		trafficAttributionMode: 'direct'
	},
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
	event_queue : new Array(),
	player: '',
	overlay: '',
	ecommerce_transaction: '',
	
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
		this.endpoint = endpoint;
	},
	
	getEndpoint : function() {
		return this.endpoint;
	},
	
	/**
	 * Logs a page view event
	 */
	trackPageView : function(url) {
		
		if (url) {
			this.page.set('page_url', url);
		}
		
		this.page.setEventType("base.page_request");	
		return this.logEvent(this.page.getProperties());
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
	
	logDomStream : function() {
    	
    	if (this.event_queue.length > this.options.domstreamEventThreshold) {
    	
			var event = new OWA.event;
			event.setEventType('dom.stream');
			event.set('site_id', this.getSiteId());
			event.set('page_url', this.page.get('page_url'));
			event.set('timestamp', this.startTime);
			event.set('duration', this.getElapsedTime());
			event.set('stream_events', JSON.stringify(this.event_queue));
			
			this.logEventAjax(event, 'POST');
			//this.trackEvent(event, true);
			OWA.debug("Domstream logged");
			
		} else {
			OWA.debug("Domstream had too few events to log");
		}
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
	    
		ajax.open("POST", this.getEndpoint(), false); 
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
	    for(param in properties) {  // print out the params
	       
			value = '';
			
			if (properties.hasOwnProperty(param)) {
	  		//if (typeof properties[param] != 'undefined') {
    			
    			// check to see if we should base64 encode the properties
    			if (this.getOption('encodeProperties')) {
    				value = this._base64_encode(properties[param]+'');
    			} else {
    				value = properties[param]+'';
    			}
    			
    			//value = Url.encode(value);
    	
	    	} else {
    	
    			value = '';
    	
    		}
       
    		get += "owa_" + param + "=" + value + "&";
		}
		
		return get;
    },
    
    
    /** 
     * Sends an OWA event to the server for processing using GET
     * inserts 1x1 pixel IMG tag into DOM
     */
    trackEvent : function(event, block) {
    
    	if ( ! block ) {
    		block_flag = false;
    	} else {
    		block_flag = true;
    	}
    	
    	return this.logEvent( event.getProperties(), block_flag );
    },
    

    /** 
     * Logs event by inserting 1x1 pixel IMG tag into DOM
     */
    logEvent : function (properties, block) {
    	
    	if (this.active) {
    	
	    	var bug
	    	var url
	    
	    	url = this._assembleRequestUrl(properties);
	    	
	    	
	
		   	bug = "<img src=\"" + url + "\" height=\"1\" width=\"1\">";
		 	OWA.debug('writing bug for %s', properties['event_type']);
		 	
		   	//document.createElement('img').src = url;
		   	image = new Image(1, 1);
		   	//expireDateTime = now.getTime() + delay;
		   	image.onLoad = function () { };
			image.src = url;
			that = this;
			if (block) {
				//OWA.debug(' blocking...');
			}
			

		}
    },
    
    isImageLoaded : function(img, url) {
    	
    	OWA.debug('checking if image is loaded.');
	    // During the onload event, IE correctly identifies any images that
	    // weren�t downloaded as not complete. Gecko-based
	    // browsers act like NS4 in that they report this incorrectly.
	    if (! img.complete) {
	       
	        return false;
	    }
	
	    // However, Gecko browsers do have two very useful properties: naturalWidth and
	    // naturalHeight. These give the true size of the image. If it failed
	    // to load, either of these should be zero.
	
	    if (typeof img.naturalWidth != "undefined" && img.naturalWidth == 0) {
	   
	        return false;
	    }
	    
	    return true;
    },
    
    /**
     * Private method for helping assemble request params
     */
    _assembleRequestUrl : function(properties) {
    
    	// append site_id to properties
    	properties.site_id = this.getSiteId();
    	var get = this.prepareRequestParams(properties);
    	
    	var log_url = this.getEndpoint();
    	
    	if (log_url.indexOf('?') === -1) {
    		log_url += '?';
    	} else {
    		log_url += '&';
    	}
    	    	
		// add some radomness for cache busting
		return log_url + get;
    },
    
    _base64_encode : function(decStr) {
    
		  var base64s = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
		  var bits;
		  var dual;
		  var i = 0;
		  var encOut = '';
		
		  while(decStr.length >= i + 3) {
		    bits = (decStr.charCodeAt(i++) & 0xff) <<16 |
		           (decStr.charCodeAt(i++) & 0xff) <<8 |
		            decStr.charCodeAt(i++) & 0xff;
		
		    encOut += base64s.charAt((bits & 0x00fc0000) >>18) +
		              base64s.charAt((bits & 0x0003f000) >>12) +
		              base64s.charAt((bits & 0x00000fc0) >> 6) +
		              base64s.charAt((bits & 0x0000003f));
		  }
		
		  if(decStr.length -i > 0 && decStr.length -i < 3) {
		    dual = Boolean(decStr.length -i -1);
		
		    bits = ((decStr.charCodeAt(i++) & 0xff) <<16) |
		           (dual ? (decStr.charCodeAt(i) & 0xff) <<8 : 0);
		
		    encOut += base64s.charAt((bits & 0x00fc0000) >>18) +
		              base64s.charAt((bits & 0x0003f000) >>12) +
		              (dual ? base64s.charAt((bits & 0x00000fc0) >>6) : '=') +
		              '=';
		  }
		
		  return(encOut);
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
		//if (this.e.target) {
		//   this.targ = this.e.target;
		   
	    //} else if (this.e.srcElement) {
	    //     this.targ = this.e.srcElement;
	    // }
	   
	    targ = e.target || e.srcElement;
	    
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
	        
	    }
	    
	    else if (targ.tagName == "INPUT") {
	    
	        properties.dom_element_text = targ.value;
	    }
	    
	    else if (targ.tagName == "IMG") {
	    
	        properties.target_url = targ.parentNode.href;
	        properties.dom_element_text = targ.alt;
	    }
	    
	    else {
	    
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
	
	bindClickEvents : function() {
		var that = this;
		document.onclick = function (e) {that.clickEventHandler(e);}
	},
	
	clickEventHandler : function(e) {
		
		// hack for IE7
		e = e || window.event;
		
		var click = new OWA.event();
		// set event type	
		click.setEventType("dom.click");
		
		//clicked DOM element properties
	    var targ = this._getTarget(e);
	    click.set("dom_element_name", targ.name);
	    click.set("dom_element_value", targ.value);
	    click.set("dom_element_id", targ.id);
	    click.set("dom_element_tag", targ.tagName);
	    click.set("dom_element_class", targ.className);
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
		
		//if all that works then log
		if (this.getOption('logClicksAsTheyHappen')) {
			this.trackEvent(click);
		}
		
		// add to event queue is logging dom stream
		if (this.getOption('trackDomStream')) {
			this.addToEventQueue(click)
		}
		
		this.click = click;
	},
	
	// stub for a filter that will strip certain properties or abort the logging
	filterDomProperties : function(properties) {
		
		return properties;
		
	},
			
	registerBeforeNavigateEvent : function() {
		var that = this;
		// Registers the handler for the before navigate event so that the dom stream can be logged
		if (window.addEventListener) {
			window.addEventListener('beforeunload', function (e) {that.logDomStream(e);}, false);
		} else if(window.attachEvent) {
			window.attachEvent('beforeunload', function (e) {that.logDomStream(e);});
		}
	
	},
	
	callMethod : function(string, data) {
		
		return this[string](data);
	},
	
	addDomStreamEventBinding : function(method_name) {
		this.streamBindings.push(method_name);
	},
	
	trackClicks : function(handler) {
		// flag to tell handler to log clicks as they happen
		this.setOption('logClicksAsTheyHappen', true);
		this.bindClickEvents();
	},
	
	trackDomStream : function() {
		
		// check random number against logging percentage
		var rand = Math.floor(Math.random() * 100 + 1 );
		
		if (rand <= this.getOption('logDomStreamPercentage')) {
			
			// needed by click handler 
			this.setOption('trackDomStream', true);	
			// loop through stream event bindings
			var len=this.streamBindings.length;
			for(var i=0; i<len; i++) {	
			//for (method in this.streamBindings) {
				this.callMethod(this.streamBindings[i]);
			}
			
			this.registerBeforeNavigateEvent();
		} else {
			OWA.debug("not tracking dom stream for this user.");
		}
			
		
		
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
		var key_code = e.keyCode? e.keyCode : e.charCode
		var key_value = String.fromCharCode(key_code); 
		var event = new OWA.event();
		event.setEventType('dom.keypress');
		event.set('key_value', key_value);
		event.set('key_code', key_code);
		var targ = this._getTarget(e);
		event.set("dom_element_name", targ.name);
	    event.set("dom_element_value", targ.value);
	    event.set("dom_element_id", targ.id);
	    event.set("dom_element_tag", targ.tagName);
	    //console.log("Keypress: %s %d", key_value, key_code);
		this.addToEventQueue(event);
		
	},
		
	getTimestamp : function() {
	
		return Math.round(new Date().getTime()/1000);
	},
	
	getTime : function() {
		return Math.round(new Date().getTime());
	},
	
	getElapsedTime : function() {
		
		return this.getTimestamp() - this.startTime;
	},
	
	getOption : function(name) {
		
		return this.options[name];
	},
	
	setOption : function(name, value) {
		this.options[name] = value;
		return;
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
	
	attributeTraffic : function() {
		
		// load existing campaign cookie.
		OWA.util.loadStateJson('c');
		
		if (OWA.state.c) {
			this.campaignState = OWA.state.c;
		}		
		// load GET params from URL
		if (!this.urlParams.length > 0)	{	
			this.urlParams = OWA.util.parseUrlParams(document.URL);
			OWA.debug('GET: '+ JSON.stringify(this.urlParams));
		}
		
		// look for attributes in the url of the page
		var campaignKeys = [
				{ public: 'owa_medium', private: 'md', full: 'medium' },
				{ public: 'owa_campaign', private: 'cn', full: 'campaign' },
				{ public: 'owa_source', private: 'sr', full: 'source' },
				{ public: 'owa_search_terms', private: 'tr', full: 'search_terms' }, 
				{ public: 'owa_ad', private: 'ad', full: 'ad' },
				{ public: 'owa_ad_type', private: 'at', full: 'ad_type' } ];
		
		// pull campaign params from _GET
		var campaign_params = {};
		for (var i = 0, n = campaignKeys.length; i < n; i++) {
			
			if (this.urlParams[campaignKeys[i].public]) {
				campaign_params[campaignKeys[i].private] = this.urlParams[campaignKeys[i].public];
				OWA.debug('campaign params obj: ' + JSON.stringify(campaign_params));
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
		
		// attribution object
		var attribution = {
			medium: '',
			source: '',
			campaign: '',
			ad_type: '',
			ad: '',
			search_terms: ''
		};
		
		// if traffic attribution mode is direct, then add the touch to the
		// campaign cookie.
		if (this.options.trafficAttributionMode === 'direct') {
			// check to see if this request is from a new campaign.
			if ( this.isNewCampaign ) {
				// set campaign timestamp
				campaign_params['ts'] = this.page.get('timestamp');
				OWA.debug( 'campaign state length: %s', this.campaignState.length );
				// add the new campaing params to the prior touches array
				this.campaignState.push( campaign_params );
				// if there is prior campaign touches, check to see if there is room for one more touch
				if ( this.campaignState.length > this.options.maxPriorCampaigns ) {
					OWA.debug( 'existing campaign touches' );
					// splice array to make room for the new one
					var removed = this.campaignState.splice( 0, 1 );
					OWA.debug('campaign state array post slice: ' + JSON.stringify( this.campaignState ) );
				}
				
				// set/reset the campaign cookie.
				this.setCampaignCookie( this.campaignState );
				
				// set flag
				this.isTrafficAttributed = true;
			}
		} 
		
		// if attribution mode is 'original' then only add the touch if
		// there is no prior touch in the cookie
		if ( this.options.trafficAttributionMode === 'original' ) {
			
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
				if (this.isNewCampaign) {
					// set campaign timestamp
					campaign_params['ts'] = this.page.get('timestamp');
					this.campaignState.push( campaign_params );
					// set cookie
					this.setCampaignCookie( this.campaignState );
					// set flag
					this.isTrafficAttributed = true;
				}
			}
		}
		
		// set attribution properties on page view object	
		if ( this.isTrafficAttributed ) {
		
			for ( param in campaign_params ) {
				if ( campaign_params.hasOwnProperty( param ) ) {
					// set medium
					switch ( param ) {
						
						case "md":
							this.page.set( 'medium', campaign_params.md );
							break;
						case "sr":
							this.page.set( 'source', campaign_params.sr );
							break;
						case "cn":
							this.page.set( 'campaign', campaign_params.cn );
							break;
						case "ad":
							this.page.set( 'ad', campaign_params.ad );
							break;
						case "at":
							this.page.set( 'ad_type', campaign_params.at );
							break;
						case "tr":
							this.page.set( 'search_terms', campaign_params.tr );
							break;
					}
				}
			}
			
			// set campaign touches
			if (this.campaignState.length > 0) {
				this.page.set( 'attribs', JSON.stringify( this.campaignState ) );
			}
			
			// set campaign timestamp
			if (this.campaignState.length > 0) {
				this.page.set( 'campaign_timestamp', campaign_params.ts );
			}
			
			// tells upstream processing to skip attribution
			this.page.set( 'is_attributed', true );
		}
	
	},
	
	setCampaignCookie : function(values) {
		
		// set or reset the campaign cookie
		OWA.debug('new campaign cookie value: ' + JSON.stringify(values));
		var domain = OWA.getSetting('cookie_domain') || document.domain;
		OWA.util.setCookie('owa_c',JSON.stringify(values),this.options.campaignAttributionWindow,'/',domain);
	},
	
	checkRefererForSearchEngine : function (referer) {
		
		var _get = OWA.util.parseUrlParams(referer);
		
		var query_params = [
				'q', 'p','search', 'Keywords','ask','keyword','keywords','kw','pattern', 				'pgm','qr','qry','qs','qt','qu','query','queryterm','question',
				'sTerm','searchfor','searchText','srch','su','what'
		];
		
		for (var i = 0, n = query_params.length; i < n; i++) {
			if ( _get[query_params[i]] ) {
				OWA.debug( 'Found search engine query param: ' + query_params[i] );
				return true;
			}
		}
	},
	
	addTransaction : function ( order_id, order_source, total, tax, shipping, gateway ) {
	
		this.ecommerce_transaction = new OWA.event();
		this.ecommerce_transaction.setEventType( 'ecommerce.transaction' );
		this.ecommerce_transaction.set( 'ct_order_id', order_id );
		this.ecommerce_transaction.set( 'ct_order_source', order_source );
		this.ecommerce_transaction.set( 'ct_total', total );
		this.ecommerce_transaction.set( 'ct_tax', tax );
		this.ecommerce_transaction.set( 'ct_shipping', shipping );
		this.ecommerce_transaction.set( 'ct_gateway', gateway );
		this.ecommerce_transaction.set( 'page_url', page_url );
		this.ecommerce_transaction.set( 'ct_line_items', [] );

	},
	
	addTransactionLineItem : function ( order_id, sku, product_name, category, unit_price, quantity ) {
	
		if ( ! this.ecommerce_transaction.length > 0 ) {
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
	
	trackTransaction: function () {
		
		if (  this.ecommerce_transaction.length > 0 ) {
			this.trackEvent( this.ecommerce_transaction );
			this.ecommerce_transaction = '';
		}
	}
	
}