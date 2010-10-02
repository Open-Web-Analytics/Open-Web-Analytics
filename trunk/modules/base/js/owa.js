var OWA = {

	items: new Object,
	overlay: '',
	config: {
		ns: 'owa_'
	},
	state: [],
	overlayActive: false,
	setSetting: function(name, value) {
		this.config[name] = value;
	},
	
	getSetting: function(name) {
		return this.config[name];
	},
	
	debug: function() {
		
		var debugging = OWA.getSetting('debug') || false; // or true
		
		if (debugging) {
			
			if(window.console && window.console.firebug) { 
		 		console.log.apply(this, arguments);
			}
		}
	},
	
	setApiEndpoint : function (endpoint) {
		this.config['api_endpoint'] = endpoint;
	},
	
	getApiEndpoint : function() {
		return this.config['api_endpoint'] || this.getSetting('baseUrl') + 'action.php';
	},
	
	loadHeatmap: function(p) {
		var that = this;
		OWA.util.loadScript(OWA.getSetting('baseUrl')+'/modules/base/js/includes/jquery/jquery-1.3.2.min.js', function(){});
		OWA.util.loadCss(OWA.getSetting('baseUrl')+'/modules/base/css/owa.overlay.css', function(){});
		OWA.util.loadScript(OWA.getSetting('baseUrl')+'/modules/base/js/owa.heatmap.js', function(){
			that.overlay = new OWA.heatmap();
			//hm.setParams(p);
			//hm.options.demoMode = true;
			that.overlay.options.liveMode = true;
			that.overlay.generate();
		});	
	},
	
	loadPlayer: function() {
		var that = this;
		OWA.debug("Loading Domstream Player");
		OWA.util.loadScript(OWA.getSetting('baseUrl')+'/modules/base/js/includes/jquery/jquery-1.3.2.min.js', function(){});
		OWA.util.loadCss(OWA.getSetting('baseUrl')+'/modules/base/css/owa.overlay.css', function(){});
		OWA.util.loadScript(OWA.getSetting('baseUrl')+'/modules/base/js/owa.player.js', function(){
			that.overlay = new OWA.player();	
		});	
	},
	
	startOverlaySession: function(p) {
		
		// set global is overlay actve flag
		OWA.overlayActive = true;
		
	    // get param from cookie	
		var params = OWA.util.parseCookieStringToJson(p);
		// evaluate the action param
		if (params.action === 'loadHeatmap') {
			this.loadHeatmap(p);
		} else if (params.action === 'loadPlayer') {
			this.loadPlayer(p);
		}
		
	},
	
	endOverlaySession : function() {
				
		OWA.util.eraseCookie('owa_overlay');
		OWA.overlayActive = false;
		window.location.href = document.location;
	}


}


OWA.util =  {

	ns: function(string) {
	
		return OWA.config.ns + string;
	
	},
	
	nsAll: function(obj) {
	
		var nsObj = new Object();
		
		for(param in obj) {  // print out the params
	    	if (obj.hasOwnProperty(param)) {
	    		nsObj[OWA.config.ns+param] = obj[param];
	    	}
		}
		
		return nsObj;
    },
    
    getScript: function(file, path) {
    
    	jQuery.getScript(path + file);
    	
    	return;
    
    },
    
    makeUrl: function(template, uri, params) {
		var url = jQuery.sprintf(template, uri, jQuery.param(OWA.util.nsAll(params)));
		//alert(url);
		return url;
	},
	
	createCookie: function (name,value,days) {
		if (days) {
			var date = new Date();
			date.setTime(date.getTime()+(days*24*60*60*1000));
			var expires = "; expires="+date.toGMTString();
		}
		else var expires = "";
		document.cookie = name+"="+value+expires+"; path=/";
	},
	
	dt_setcookie: function (name, value, expirydays) {
	    var expiry = new Date();
	    expiry.setDate(expiry.getDate() + expirydays);
	    document.cookie = name+"="+escape(value)+";expires="+expiry.toGMTString();
	    console.log(document.cookie);
	    return document.cookie;
	},
	
	setCookie: function (name,value,days,path,domain,secure) {
		var date = new Date();
		date.setTime(date.getTime()+(days*24*60*60*1000));
		
		document.cookie = name + "=" + escape (value) +
	    ((days) ? "; expires=" + date.toGMTString() : "") +
	    ((path) ? "; path=" + path : "") +
	    ((domain) ? "; domain=" + domain : "") +
	    ((secure) ? "; secure" : "");
	},

	readCookie: function (name) {
		var nameEQ = name + "=";
		var ca = document.cookie.split(';');
		for(var i=0;i < ca.length;i++) {
			var c = ca[i];
			while (c.charAt(0)==' ') c = c.substring(1,c.length);
			if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
		}
		return null;
	},
	
	
	eraseCookie: function (name) {
		//OWA.debug(this.readCookie('owa_overlay'));
		
		var domain = OWA.getSetting('cookie_domain') || document.domain;
		OWA.debug("erasing " + name + " in domain: " +domain);
		this.setCookie(name,"",-1,"/",domain);
		var test = this.readCookie(name);
		
		if (test) {
			domain = "."+domain;
			OWA.debug("erasing " + name + " in domain: " +domain);
			this.setCookie(name,"",-1,"/",domain);	
		}
		
	},
	
	loadScript: function (url, callback){

	       return LazyLoad.js(url, callback);
	},

	loadCss: function (url, callback){

	    return LazyLoad.css(url, callback);
	},
	
	parseCookieString: function parseQuery(v) {
		var queryAsAssoc = new Array();
		var queryString = unescape(v);
		var keyValues = queryString.split("|||");
		//alert(keyValues);
		for (var i in keyValues) {
			if (keyValues.hasOwnProperty(i)) {
				var key = keyValues[i].split("=>");
				queryAsAssoc[key[0]] = key[1];
			}
			//alert(key[0] +"="+ key[1]);
		}
		
		return queryAsAssoc;
	},
	
	parseCookieStringToJson: function parseQuery(v) {
		var queryAsObj = new Object;
		var queryString = unescape(v);
		var keyValues = queryString.split("|||");
		//alert(keyValues);
		for (var i in keyValues) {
			if (keyValues.hasOwnProperty(i)) {
				var key = keyValues[i].split("=>");
				queryAsObj[key[0]] = key[1];
				//alert(key[0] +"="+ key[1]);
			}
		}
		//alert (queryAsObj.period);
		return queryAsObj;
	},
	
	nsParams: function(obj) {
		var new_obj = new Object;
		
		for(param in obj) {
			if (obj.hasOwnProperty(param)) {
				new_obj['owa_'+ param] = obj[param];
			}
		}
		
		return new_obj;
	},
	
	urlEncode : function(str) {
		 str = (str+'').toString();
    
    	// Tilde should be allowed unescaped in future versions of PHP (as reflected below), but if you want to reflect current
    	// PHP behavior, you would need to add ".replace(/~/g, '%7E');" to the following.
    	return encodeURIComponent(str).replace(/!/g, '%21').replace(/'/g, '%27').replace(/\(/g, '%28').replace(/\)/g, '%29').replace(/\*/g, '%2A').replace(/%20/g, '+').replace(/~/g, '%7E');
	},
	
	parseUrlParams : function(url) {
		
		var _GET = {};
		for(var i,a,m,n,o,v,p=location.href.split(/[?&]/),l=p.length,k=1;k<l;k++)
			if( (m=p[k].match(/(.*?)(\..*?|\[.*?\])?=([^#]*)/)) && m.length==4){
				n=decodeURI(m[1]).toLowerCase(),o=_GET,v=decodeURI(m[3]);
				if(m[2])
					for(a=decodeURI(m[2]).replace(/\[\s*\]/g,"[-1]").split(/[\.\[\]]/),i=0;i<a.length;i++)
						o=o[n]?o[n]:o[n]=(parseInt(a[i])==a[i])?[]:{}, n=a[i].replace(/^["\'](.*)["\']$/,"$1");
						n!='-1'?o[n]=v:o[o.length]=v;
			}
		
		return _GET;
	},
	
	strpos : function(haystack, needle, offset) {
	    // Finds position of first occurrence of a string within another  
	    // 
	    // version: 1008.1718
	    // discuss at: http://phpjs.org/functions/strpos
	    // +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +   improved by: Onno Marsman    
	    // +   bugfixed by: Daniel Esteban
	    // +   improved by: Brett Zamir (http://brett-zamir.me)
	    // *     example 1: strpos('Kevin van Zonneveld', 'e', 5);
	    // *     returns 1: 14
	    var i = (haystack+'').indexOf(needle, (offset || 0));
	    return i === -1 ? false : i;
	},
	
	strCountOccurances : function(haystack, needle) {
		return haystack.split(needle).length - 1;
	},
	
	implode : function(glue, pieces) {
	    // Joins array elements placing glue string between items and return one string  
	    // 
	    // version: 1008.1718
	    // discuss at: http://phpjs.org/functions/implode
	    // +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +   improved by: Waldo Malqui Silva
	    // +   improved by: Itsacon (http://www.itsacon.net/)
	    // +   bugfixed by: Brett Zamir (http://brett-zamir.me)
	    // *     example 1: implode(' ', ['Kevin', 'van', 'Zonneveld']);
	    // *     returns 1: 'Kevin van Zonneveld'
	    // *     example 2: implode(' ', {first:'Kevin', last: 'van Zonneveld'});
	    // *     returns 2: 'Kevin van Zonneveld'
	    var i = '', retVal='', tGlue='';
	    if (arguments.length === 1) {
	        pieces = glue;
	        glue = '';
	    }
	    if (typeof(pieces) === 'object') {
	        if (pieces instanceof Array) {
	            return pieces.join(glue);
	        }
	        else {
	            for (i in pieces) {
	                retVal += tGlue + pieces[i];
	                tGlue = glue;
	            }
	            return retVal;
	        }
	    }
	    else {
	        return pieces;
	    }
	},
	
	setState : function(store_name, key, value, expiration) {
	
	},
	
	getState : function(store_name, key) {
		this.loadState(store_name);
		return OWA.state[store_name][key];
	},
	
	loadState : function(store_name) {
	
		var store = unescape( this.readCookie( OWA.getSetting('ns') + store_name ) );
		var state = this.assocFromString(store);
		
		OWA.state[store_name] = state;
		OWA.debug('state store %s: %s', store_name, JSON.stringify(state));
	},
	
	loadStateJson : function(store_name) {
		var store = unescape(this.readCookie( OWA.getSetting('ns') + store_name ) );
		if (store) {
			state = JSON.parse(store);
		}
		OWA.state[store_name] = state;
		OWA.debug('state store %s: %s', store_name, JSON.stringify(state));
	},
	
	clearState : function(store_name) {
	
	},
	
	is_array : function(input) {
  		return typeof(input)=='object'&&(input instanceof Array);	
  	},
  	
  	countObjectProperties : function( obj ) {
  		
    	var size = 0, key;
    	for (key in obj) {
        	if (obj.hasOwnProperty(key)) size++;
    	}
    	return size;
  	},
	
	assocFromString : function(str, inner, outer) {
		
		inner = inner || '=>';
		outer = outer || '|||';
		
		if (str){
		
			if (!this.strpos(str, outer)) {
	
				return str;
				
			} else {
				
				var assoc = [];
				outer_array = str.split(outer);
				//OWA.debug('outer array: %s', JSON.stringify(outer_array));
				for (var i = 0, n = outer_array.length; i < n; i++) {
				
					var inside_array = outer_array[i].split(inner);
					
					assoc[inside_array[0]] = inside_array[1];
				}	
			}
			
			OWA.debug('assoc from string: ' + JSON.stringify(assoc));
			return assoc;
		}
	},
	
	getDomainFromUrl : function (url, strip_www) {
		
		var domain = url.split(/\/+/g)[1];
		
		if (strip_www === true) {
			var fp = domain.split('.')[0];
			
			if (fp === 'www') {
				return domain.substring(4);
			} else {
				return domain;
			}
			
		} else {
			return domain;
		}
	},
	
	getCurrentUnixTimestamp : function() {
		return Math.round(new Date().getTime() / 1000);
	}
	
}