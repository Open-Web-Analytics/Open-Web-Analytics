var OWA = {

	items: new Object,
	overlay: '',
	config: {
		ns: 'owa_',
		baseUrl: ''
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
			} else {
				console.log.apply(console, arguments);
			}
		}
	},
	
	setApiEndpoint : function (endpoint) {
		this.config['api_endpoint'] = endpoint;
	},
	
	getApiEndpoint : function() {
		return this.config['api_endpoint'] || this.getSetting('baseUrl') + 'api.php';
	},
	
	loadHeatmap: function(p) {
		var that = this;
		OWA.util.loadScript(OWA.getSetting('baseUrl')+'/modules/base/js/includes/jquery/jquery-1.4.2.min.js', function(){});
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
		OWA.util.loadScript(OWA.getSetting('baseUrl')+'/modules/base/js/includes/jquery/jquery-1.4.2.min.js', function(){});
		OWA.util.loadCss(OWA.getSetting('baseUrl')+'/modules/base/css/owa.overlay.css', function(){});
		OWA.util.loadScript(OWA.getSetting('baseUrl')+'/modules/base/js/owa.player.js', function(){
			that.overlay = new OWA.player();	
		});	
	},
	
	startOverlaySession: function(p) {
		
		// set global is overlay actve flag
		OWA.overlayActive = true;
		
	    // get param from cookie	
		//var params = OWA.util.parseCookieStringToJson(p);
		var params = p;
		// evaluate the action param
		if (params.action === 'loadHeatmap') {
			this.loadHeatmap(p);
		} else if (params.action === 'loadPlayer') {
			this.loadPlayer(p);
		}
		
	},
	
	endOverlaySession : function() {
				
		OWA.util.eraseCookie('owa_overlay', document.domain);
		OWA.overlayActive = false;
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
	
	createCookie: function (name,value,days,domain) {
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
		return '';
	},
	
	
	eraseCookie: function (name, domain) {
		OWA.debug(document.cookie);
		if ( ! domain ) {
			domain = OWA.getSetting('cookie_domain') || document.domain;
		}
		OWA.debug("erasing cookie: " + name + " in domain: " +domain);
		this.setCookie(name,"",-1,"/",domain);
		// attempt to read the cookie again to see if its there under another valid domain
		var test = this.readCookie(name);
		// if so then try the alternate domain				
		if (test) {
			
			var period = domain.substr(0,1);
			OWA.debug('period: '+period);
			if (period === '.') {
				var domain2 = domain.substr(1);
				OWA.debug("erasing " + name + " in domain2: " + domain2);
				this.setCookie(name,"",-2,"/", domain2);
				
					
			} else {
				//	domain = '.'+ domain
				OWA.debug("erasing " + name + " in domain3: " + domain);
				this.setCookie(name,"",-2,"/",domain);	
			}
			//OWA.debug("erasing " + name + " in domain: ");
			//this.setCookie(name,"",-2,"/");	
		}
		
	},
	
	eraseMultipleCookies: function(names, domain) {
		
		for (var i=0; i < names.length; i++) {
			this.eraseCookie(names[i], domain);
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
	
	urldecode : function (str) {
	    // Decodes URL-encoded string  
	    // 
	    // version: 1008.1718
	    // discuss at: http://phpjs.org/functions/urldecode
	    // +   original by: Philip Peterson
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +      input by: AJ
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +   improved by: Brett Zamir (http://brett-zamir.me)
	    // +      input by: travc
	    // +      input by: Brett Zamir (http://brett-zamir.me)
	    // +   bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +   improved by: Lars Fischer
	    // +      input by: Ratheous
	    // +   improved by: Orlando
	    // +      reimplemented by: Brett Zamir (http://brett-zamir.me)
	    // +      bugfixed by: Rob
	    // %        note 1: info on what encoding functions to use from: http://xkr.us/articles/javascript/encode-compare/
	    // %        note 2: Please be aware that this function expects to decode from UTF-8 encoded strings, as found on
	    // %        note 2: pages served as UTF-8
	    // *     example 1: urldecode('Kevin+van+Zonneveld%21');
	    // *     returns 1: 'Kevin van Zonneveld!'
	    // *     example 2: urldecode('http%3A%2F%2Fkevin.vanzonneveld.net%2F');
	    // *     returns 2: 'http://kevin.vanzonneveld.net/'
	    // *     example 3: urldecode('http%3A%2F%2Fwww.google.nl%2Fsearch%3Fq%3Dphp.js%26ie%3Dutf-8%26oe%3Dutf-8%26aq%3Dt%26rls%3Dcom.ubuntu%3Aen-US%3Aunofficial%26client%3Dfirefox-a');
	    // *     returns 3: 'http://www.google.nl/search?q=php.js&ie=utf-8&oe=utf-8&aq=t&rls=com.ubuntu:en-US:unofficial&client=firefox-a'
	    
	    return decodeURIComponent(str.replace(/\+/g, '%20'));
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
	
	setState : function(store_name, key, value, is_perminant,format, expiration_days) {
		
		if ( ! OWA.state.hasOwnProperty( store_name ) ) {
			OWA.util.loadState(store_name);
		}
		
		if ( ! OWA.state.hasOwnProperty( store_name ) ) {
			OWA.debug('Creating state store (%s)', store_name);
			OWA.state[store_name] = {};
		}
		
		if ( key ) {
			OWA.state[store_name][key] = value;
		} else {
			OWA.state[store_name] = value;
		}
		
		var store = unescape( this.readCookie( OWA.getSetting('ns') + store_name ) );
		
		var check = '';
		if (store) {
			check = store.substr(0,2);
			
			if (check === '[{') {
				format = 'json';
			} else {
				format = 'assoc';
			}
		}
		
		if (format === 'json') {
			state_value = JSON.stringify(OWA.state[store_name]);
		} else {
			state_value = OWA.util.assocStringFromJson(OWA.state[store_name]);
		}
		
		if ( ! expiration_days ) {
			
			if ( is_perminant ) {
				expiration_days =  3600;
			}
		}
		
		// set or reset the campaign cookie
		OWA.debug('Populating state store (%s) with value: %s', store_name, state_value);
		var domain = OWA.getSetting('cookie_domain') || document.domain;
		// erase cookie
		//OWA.util.eraseCookie( 'owa_'+store_name, domain );
		// set cookie
		OWA.util.setCookie( 'owa_'+store_name, state_value, expiration_days, '/', domain );
	},
	
	replaceState : function (store_name, value, is_perminant, format, expiration_days) {
		
		if ( store_name ) {
			var domain = OWA.getSetting('cookie_domain') || document.domain;
			
			if ( ! expiration_days ) {
				
				if ( is_perminant ) {
					expiration_days =  3600;
				}
			}
			OWA.debug('About to replace state store (%s) with: %s', store_name, value);
			OWA.util.setCookie( 'owa_'+ store_name, value, expiration_days, '/', domain );
			OWA.util.loadState(store_name);
		}
	},
	
	getRawState : function(store_name) {
		
		var store = unescape( this.readCookie( OWA.getSetting('ns') + store_name ) );
		if ( store ) {
			return store;
		}
	},
	
	getState : function(store_name, key) {
		
		if ( ! OWA.state.hasOwnProperty( store_name ) ) {
			this.loadState(store_name);
		}
		
		if ( OWA.state.hasOwnProperty( store_name ) ) {
			if ( key ) {
				if ( OWA.state[store_name].hasOwnProperty( key ) ) {		
					return OWA.state[store_name][key];
				}		
			} else {
				return OWA.state[store_name];
			}
		} else {
			OWA.debug('No state store (%s) was found', store_name);
			return '';
		}
		
	},
	
	loadState : function(store_name) {
	
		var store = unescape( this.readCookie( OWA.getSetting('ns') + store_name ) );
		if ( store ) {
			var check = store.substr(0,2);
			//OWA.debug('check: %s', check);
			var state = '';
			if (check === '[{') {
				state = JSON.parse(store);
			} else {
				state = this.jsonFromAssocString(store);
			}
			
			OWA.state[store_name] = state;
			OWA.debug('Loaded state store (%s) with: %s', store_name, JSON.stringify(state));
		} else {
			
			OWA.debug('State store (%s) is empty. Nothing to Load.', store_name);
		}
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
	
		// delete cookie
		OWA.state[store_name] = '';
		this.eraseCookie(OWA.getSetting('ns') + store_name);
	},
	
	is_array : function (input) {
  		return typeof(input)=='object'&&(input instanceof Array);	
  	},
  	
	// Returns true if variable is an object  
    // 
    // version: 1008.1718
    // discuss at: http://phpjs.org/functions/is_object
    // +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
    // +   improved by: Legaev Andrey
    // +   improved by: Michael White (http://getsprink.com)
    // *     example 1: is_object('23');
    // *     returns 1: false
    // *     example 2: is_object({foo: 'bar'});
    // *     returns 2: true
    // *     example 3: is_object(null);
    // *     returns 3: false
  	is_object : function (mixed_var) {

	    if (mixed_var instanceof Array) {
	        return false;
	    } else {
	        return (mixed_var !== null) && (typeof( mixed_var ) == 'object');
	    }
	},
  	
  	countObjectProperties : function( obj ) {
  		
    	var size = 0, key;
    	for (key in obj) {
        	if (obj.hasOwnProperty(key)) size++;
    	}
    	return size;
  	},
	
	jsonFromAssocString : function(str, inner, outer) {
		
		inner = inner || '=>';
		outer = outer || '|||';
		
		if (str){
		
			if (!this.strpos(str, inner)) {
	
				return str;
				
			} else {
				
				var assoc = {};
				outer_array = str.split(outer);
				//OWA.debug('outer array: %s', JSON.stringify(outer_array));
				for (var i = 0, n = outer_array.length; i < n; i++) {
				
					var inside_array = outer_array[i].split(inner);
					
					assoc[inside_array[0]] = inside_array[1];
				}	
			}
			
			//OWA.debug('jsonFromAssocString: ' + JSON.stringify(assoc));
			return assoc;
		}
	},
	
	assocStringFromJson : function(obj) {
		
		var string = '';
		var i = 0;
		var count = OWA.util.countObjectProperties(obj);
		
		for (prop in obj) {
			i++;
			string += prop + '=>' + obj[prop];
			
			if (i < count) {
				string += '|||';
			}
		}
		//OWA.debug('OWA.util.assocStringFromJson: %s', string);
		return string;	
	
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
	},
	
	generateHash : function(value) {
	
		return this.crc32(value);
	},
	
	generateRandomGuid : function(salt) {
		var time = this.getCurrentUnixTimestamp();
		var random = this.rand();
		return this.generateHash(time + random + salt);
	},
	
	crc32 : function ( str ) {
	    // Calculate the crc32 polynomial of a string  
	    // 
	    // version: 1008.1718
	    // discuss at: http://phpjs.org/functions/crc32
	    // +   original by: Webtoolkit.info (http://www.webtoolkit.info/)
	    // +   improved by: T0bsn
	    // -    depends on: utf8_encode
	    // *     example 1: crc32('Kevin van Zonneveld');
	    // *     returns 1: 1249991249
	    str = this.utf8_encode(str);
	    var table = "00000000 77073096 EE0E612C 990951BA 076DC419 706AF48F E963A535 9E6495A3 0EDB8832 79DCB8A4 E0D5E91E 97D2D988 09B64C2B 7EB17CBD E7B82D07 90BF1D91 1DB71064 6AB020F2 F3B97148 84BE41DE 1ADAD47D 6DDDE4EB F4D4B551 83D385C7 136C9856 646BA8C0 FD62F97A 8A65C9EC 14015C4F 63066CD9 FA0F3D63 8D080DF5 3B6E20C8 4C69105E D56041E4 A2677172 3C03E4D1 4B04D447 D20D85FD A50AB56B 35B5A8FA 42B2986C DBBBC9D6 ACBCF940 32D86CE3 45DF5C75 DCD60DCF ABD13D59 26D930AC 51DE003A C8D75180 BFD06116 21B4F4B5 56B3C423 CFBA9599 B8BDA50F 2802B89E 5F058808 C60CD9B2 B10BE924 2F6F7C87 58684C11 C1611DAB B6662D3D 76DC4190 01DB7106 98D220BC EFD5102A 71B18589 06B6B51F 9FBFE4A5 E8B8D433 7807C9A2 0F00F934 9609A88E E10E9818 7F6A0DBB 086D3D2D 91646C97 E6635C01 6B6B51F4 1C6C6162 856530D8 F262004E 6C0695ED 1B01A57B 8208F4C1 F50FC457 65B0D9C6 12B7E950 8BBEB8EA FCB9887C 62DD1DDF 15DA2D49 8CD37CF3 FBD44C65 4DB26158 3AB551CE A3BC0074 D4BB30E2 4ADFA541 3DD895D7 A4D1C46D D3D6F4FB 4369E96A 346ED9FC AD678846 DA60B8D0 44042D73 33031DE5 AA0A4C5F DD0D7CC9 5005713C 270241AA BE0B1010 C90C2086 5768B525 206F85B3 B966D409 CE61E49F 5EDEF90E 29D9C998 B0D09822 C7D7A8B4 59B33D17 2EB40D81 B7BD5C3B C0BA6CAD EDB88320 9ABFB3B6 03B6E20C 74B1D29A EAD54739 9DD277AF 04DB2615 73DC1683 E3630B12 94643B84 0D6D6A3E 7A6A5AA8 E40ECF0B 9309FF9D 0A00AE27 7D079EB1 F00F9344 8708A3D2 1E01F268 6906C2FE F762575D 806567CB 196C3671 6E6B06E7 FED41B76 89D32BE0 10DA7A5A 67DD4ACC F9B9DF6F 8EBEEFF9 17B7BE43 60B08ED5 D6D6A3E8 A1D1937E 38D8C2C4 4FDFF252 D1BB67F1 A6BC5767 3FB506DD 48B2364B D80D2BDA AF0A1B4C 36034AF6 41047A60 DF60EFC3 A867DF55 316E8EEF 4669BE79 CB61B38C BC66831A 256FD2A0 5268E236 CC0C7795 BB0B4703 220216B9 5505262F C5BA3BBE B2BD0B28 2BB45A92 5CB36A04 C2D7FFA7 B5D0CF31 2CD99E8B 5BDEAE1D 9B64C2B0 EC63F226 756AA39C 026D930A 9C0906A9 EB0E363F 72076785 05005713 95BF4A82 E2B87A14 7BB12BAE 0CB61B38 92D28E9B E5D5BE0D 7CDCEFB7 0BDBDF21 86D3D2D4 F1D4E242 68DDB3F8 1FDA836E 81BE16CD F6B9265B 6FB077E1 18B74777 88085AE6 FF0F6A70 66063BCA 11010B5C 8F659EFF F862AE69 616BFFD3 166CCF45 A00AE278 D70DD2EE 4E048354 3903B3C2 A7672661 D06016F7 4969474D 3E6E77DB AED16A4A D9D65ADC 40DF0B66 37D83BF0 A9BCAE53 DEBB9EC5 47B2CF7F 30B5FFE9 BDBDF21C CABAC28A 53B39330 24B4A3A6 BAD03605 CDD70693 54DE5729 23D967BF B3667A2E C4614AB8 5D681B02 2A6F2B94 B40BBE37 C30C8EA1 5A05DF1B 2D02EF8D";
	 
	    var crc = 0;
	    var x = 0;
	    var y = 0;
	 
	    crc = crc ^ (-1);
	    for (var i = 0, iTop = str.length; i < iTop; i++) {
	        y = ( crc ^ str.charCodeAt( i ) ) & 0xFF;
	        x = "0x" + table.substr( y * 9, 8 );
	        crc = ( crc >>> 8 ) ^ x;
	    }
	 
	    return crc ^ (-1);
	},
	
	utf8_encode : function ( argString ) {
	    // Encodes an ISO-8859-1 string to UTF-8  
	    // 
	    // version: 1008.1718
	    // discuss at: http://phpjs.org/functions/utf8_encode
	    // +   original by: Webtoolkit.info (http://www.webtoolkit.info/)
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +   improved by: sowberry
	    // +    tweaked by: Jack
	    // +   bugfixed by: Onno Marsman
	    // +   improved by: Yves Sucaet
	    // +   bugfixed by: Onno Marsman
	    // +   bugfixed by: Ulrich
	    // *     example 1: utf8_encode('Kevin van Zonneveld');
	    // *     returns 1: 'Kevin van Zonneveld'
	    var string = (argString+''); // .replace(/\r\n/g, "\n").replace(/\r/g, "\n");
	 
	    var utftext = "";
	    var start, end;
	    var stringl = 0;
	 
	    start = end = 0;
	    stringl = string.length;
	    for (var n = 0; n < stringl; n++) {
	        var c1 = string.charCodeAt(n);
	        var enc = null;
	 
	        if (c1 < 128) {
	            end++;
	        } else if (c1 > 127 && c1 < 2048) {
	            enc = String.fromCharCode((c1 >> 6) | 192) + String.fromCharCode((c1 & 63) | 128);
	        } else {
	            enc = String.fromCharCode((c1 >> 12) | 224) + String.fromCharCode(((c1 >> 6) & 63) | 128) + String.fromCharCode((c1 & 63) | 128);
	        }
	        if (enc !== null) {
	            if (end > start) {
	                utftext += string.substring(start, end);
	            }
	            utftext += enc;
	            start = end = n+1;
	        }
	    }
	 
	    if (end > start) {
	        utftext += string.substring(start, string.length);
	    }
	 
	    return utftext;
	},
	
	utf8_decode : function( str_data ) {
		// Converts a UTF-8 encoded string to ISO-8859-1  
	    // 
	    // version: 1009.2513
	    // discuss at: http://phpjs.org/functions/utf8_decode
	    // +   original by: Webtoolkit.info (http://www.webtoolkit.info/)
	    // +      input by: Aman Gupta
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +   improved by: Norman "zEh" Fuchs
	    // +   bugfixed by: hitwork
	    // +   bugfixed by: Onno Marsman
	    // +      input by: Brett Zamir (http://brett-zamir.me)
	    // +   bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // *     example 1: utf8_decode('Kevin van Zonneveld');
	    // *     returns 1: 'Kevin van Zonneveld'
	    var tmp_arr = [], i = 0, ac = 0, c1 = 0, c2 = 0, c3 = 0;
	    
	    str_data += '';
	    
	    while ( i < str_data.length ) {
	        c1 = str_data.charCodeAt(i);
	        if (c1 < 128) {
	            tmp_arr[ac++] = String.fromCharCode(c1);
	            i++;
	        } else if ((c1 > 191) && (c1 < 224)) {
	            c2 = str_data.charCodeAt(i+1);
	            tmp_arr[ac++] = String.fromCharCode(((c1 & 31) << 6) | (c2 & 63));
	            i += 2;
	        } else {
	            c2 = str_data.charCodeAt(i+1);
	            c3 = str_data.charCodeAt(i+2);
	            tmp_arr[ac++] = String.fromCharCode(((c1 & 15) << 12) | ((c2 & 63) << 6) | (c3 & 63));
	            i += 3;
	        }
	    }
	 
	    return tmp_arr.join('');
	},
	
	trim : function (str, charlist) {
	    // Strips whitespace from the beginning and end of a string  
	    // 
	    // version: 1009.2513
	    // discuss at: http://phpjs.org/functions/trim
	    // +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +   improved by: mdsjack (http://www.mdsjack.bo.it)
	    // +   improved by: Alexander Ermolaev (http://snippets.dzone.com/user/AlexanderErmolaev)
	    // +      input by: Erkekjetter
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +      input by: DxGx
	    // +   improved by: Steven Levithan (http://blog.stevenlevithan.com)
	    // +    tweaked by: Jack
	    // +   bugfixed by: Onno Marsman
	    // *     example 1: trim('    Kevin van Zonneveld    ');
	    // *     returns 1: 'Kevin van Zonneveld'
	    // *     example 2: trim('Hello World', 'Hdle');
	    // *     returns 2: 'o Wor'
	    // *     example 3: trim(16, 1);
	    // *     returns 3: 6
	    var whitespace, l = 0, i = 0;
	    str += '';
	    
	    if (!charlist) {
	        // default list
	        whitespace = " \n\r\t\f\x0b\xa0\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200a\u200b\u2028\u2029\u3000";
	    } else {
	        // preg_quote custom list
	        charlist += '';
	        whitespace = charlist.replace(/([\[\]\(\)\.\?\/\*\{\}\+\$\^\:])/g, '$1');
	    }
	    
	    l = str.length;
	    for (i = 0; i < l; i++) {
	        if (whitespace.indexOf(str.charAt(i)) === -1) {
	            str = str.substring(i);
	            break;
	        }
	    }
	    
	    l = str.length;
	    for (i = l - 1; i >= 0; i--) {
	        if (whitespace.indexOf(str.charAt(i)) === -1) {
	            str = str.substring(0, i + 1);
	            break;
	        }
	    }
	    
	    return whitespace.indexOf(str.charAt(0)) === -1 ? str : '';
	},
	
	rand : function(min, max) {
	    // Returns a random number  
	    // 
	    // version: 1008.1718
	    // discuss at: http://phpjs.org/functions/rand
	    // +   original by: Leslie Hoare
	    // +   bugfixed by: Onno Marsman
	    // *     example 1: rand(1, 1);
	    // *     returns 1: 1
	    
	    var argc = arguments.length;
	    if (argc === 0) {
	        min = 0;
	        max = 2147483647;
	    } else if (argc === 1) {
	        throw new Error('Warning: rand() expects exactly 2 parameters, 1 given');
	    }
	    return Math.floor(Math.random() * (max - min + 1)) + min;
	},
	
	base64_encode: function (data) {
	    // Encodes string using MIME base64 algorithm  
	    // 
	    // version: 1008.1718
	    // discuss at: http://phpjs.org/functions/base64_encode
	    // +   original by: Tyler Akins (http://rumkin.com)
	    // +   improved by: Bayron Guevara
	    // +   improved by: Thunder.m
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +   bugfixed by: Pellentesque Malesuada
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // -    depends on: utf8_encode
	    // *     example 1: base64_encode('Kevin van Zonneveld');
	    // *     returns 1: 'S2V2aW4gdmFuIFpvbm5ldmVsZA=='
	    // mozilla has this native
	    // - but breaks in 2.0.0.12!
	    //if (typeof this.window['atob'] == 'function') {
	    //    return atob(data);
	    //}
	        
	    var b64 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=";
	    var o1, o2, o3, h1, h2, h3, h4, bits, i = 0, ac = 0, enc="", tmp_arr = [];
	 
	    if (!data) {
	        return data;
	    }
	 
	    data = this.utf8_encode(data+'');
	    
	    do { // pack three octets into four hexets
	        o1 = data.charCodeAt(i++);
	        o2 = data.charCodeAt(i++);
	        o3 = data.charCodeAt(i++);
	 
	        bits = o1<<16 | o2<<8 | o3;
	 
	        h1 = bits>>18 & 0x3f;
	        h2 = bits>>12 & 0x3f;
	        h3 = bits>>6 & 0x3f;
	        h4 = bits & 0x3f;
	 
	        // use hexets to index into b64, and append result to encoded string
	        tmp_arr[ac++] = b64.charAt(h1) + b64.charAt(h2) + b64.charAt(h3) + b64.charAt(h4);
	    } while (i < data.length);
	    
	    enc = tmp_arr.join('');
	    
	    switch (data.length % 3) {
	        case 1:
	            enc = enc.slice(0, -2) + '==';
	        break;
	        case 2:
	            enc = enc.slice(0, -1) + '=';
	        break;
	    }
	 
	    return enc;
	},
	
	base64_decode: function (data) {
	    // Decodes string using MIME base64 algorithm  
	    // 
	    // version: 1009.2513
	    // discuss at: http://phpjs.org/functions/base64_decode
	    // +   original by: Tyler Akins (http://rumkin.com)
	    // +   improved by: Thunder.m
	    // +      input by: Aman Gupta
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +   bugfixed by: Onno Marsman
	    // +   bugfixed by: Pellentesque Malesuada
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +      input by: Brett Zamir (http://brett-zamir.me)
	    // +   bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // -    depends on: utf8_decode
	    // *     example 1: base64_decode('S2V2aW4gdmFuIFpvbm5ldmVsZA==');
	    // *     returns 1: 'Kevin van Zonneveld'
	    // mozilla has this native
	    // - but breaks in 2.0.0.12!
	    //if (typeof this.window['btoa'] == 'function') {
	    //    return btoa(data);
	    //}
	 
	    var b64 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=";
	    var o1, o2, o3, h1, h2, h3, h4, bits, i = 0, ac = 0, dec = "", tmp_arr = [];
	 
	    if (!data) {
	        return data;
	    }
	 
	    data += '';
	 
	    do {  // unpack four hexets into three octets using index points in b64
	        h1 = b64.indexOf(data.charAt(i++));
	        h2 = b64.indexOf(data.charAt(i++));
	        h3 = b64.indexOf(data.charAt(i++));
	        h4 = b64.indexOf(data.charAt(i++));
	 
	        bits = h1<<18 | h2<<12 | h3<<6 | h4;
	 
	        o1 = bits>>16 & 0xff;
	        o2 = bits>>8 & 0xff;
	        o3 = bits & 0xff;
	 
	        if (h3 == 64) {
	            tmp_arr[ac++] = String.fromCharCode(o1);
	        } else if (h4 == 64) {
	            tmp_arr[ac++] = String.fromCharCode(o1, o2);
	        } else {
	            tmp_arr[ac++] = String.fromCharCode(o1, o2, o3);
	        }
	    } while (i < data.length);
	 
	    dec = tmp_arr.join('');
	    dec = this.utf8_decode(dec);
	 
	    return dec;
	},
	
	sprintf : function( ) {
	    // Return a formatted string  
	    // 
	    // version: 1008.1718
	    // discuss at: http://phpjs.org/functions/sprintf
	    // +   original by: Ash Searle (http://hexmen.com/blog/)
	    // + namespaced by: Michael White (http://getsprink.com)
	    // +    tweaked by: Jack
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +      input by: Paulo Freitas
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +      input by: Brett Zamir (http://brett-zamir.me)
	    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // *     example 1: sprintf("%01.2f", 123.1);
	    // *     returns 1: 123.10
	    // *     example 2: sprintf("[%10s]", 'monkey');
	    // *     returns 2: '[    monkey]'
	    // *     example 3: sprintf("[%'#10s]", 'monkey');
	    // *     returns 3: '[####monkey]'
	    var regex = /%%|%(\d+\$)?([-+\'#0 ]*)(\*\d+\$|\*|\d+)?(\.(\*\d+\$|\*|\d+))?([scboxXuidfegEG])/g;
	    var a = arguments, i = 0, format = a[i++];
	 
	    // pad()
	    var pad = function (str, len, chr, leftJustify) {
	        if (!chr) {chr = ' ';}
	        var padding = (str.length >= len) ? '' : Array(1 + len - str.length >>> 0).join(chr);
	        return leftJustify ? str + padding : padding + str;
	    };
	 
	    // justify()
	    var justify = function (value, prefix, leftJustify, minWidth, zeroPad, customPadChar) {
	        var diff = minWidth - value.length;
	        if (diff > 0) {
	            if (leftJustify || !zeroPad) {
	                value = pad(value, minWidth, customPadChar, leftJustify);
	            } else {
	                value = value.slice(0, prefix.length) + pad('', diff, '0', true) + value.slice(prefix.length);
	            }
	        }
	        return value;
	    };
	 
	    // formatBaseX()
	    var formatBaseX = function (value, base, prefix, leftJustify, minWidth, precision, zeroPad) {
	        // Note: casts negative numbers to positive ones
	        var number = value >>> 0;
	        prefix = prefix && number && {'2': '0b', '8': '0', '16': '0x'}[base] || '';
	        value = prefix + pad(number.toString(base), precision || 0, '0', false);
	        return justify(value, prefix, leftJustify, minWidth, zeroPad);
	    };
	 
	    // formatString()
	    var formatString = function (value, leftJustify, minWidth, precision, zeroPad, customPadChar) {
	        if (precision != null) {
	            value = value.slice(0, precision);
	        }
	        return justify(value, '', leftJustify, minWidth, zeroPad, customPadChar);
	    };
	 
	    // doFormat()
	    var doFormat = function (substring, valueIndex, flags, minWidth, _, precision, type) {
	        var number;
	        var prefix;
	        var method;
	        var textTransform;
	        var value;
	 
	        if (substring == '%%') {return '%';}
	 
	        // parse flags
	        var leftJustify = false, positivePrefix = '', zeroPad = false, prefixBaseX = false, customPadChar = ' ';
	        var flagsl = flags.length;
	        for (var j = 0; flags && j < flagsl; j++) {
	            switch (flags.charAt(j)) {
	                case ' ': positivePrefix = ' '; break;
	                case '+': positivePrefix = '+'; break;
	                case '-': leftJustify = true; break;
	                case "'": customPadChar = flags.charAt(j+1); break;
	                case '0': zeroPad = true; break;
	                case '#': prefixBaseX = true; break;
	            }
	        }
	 
	        // parameters may be null, undefined, empty-string or real valued
	        // we want to ignore null, undefined and empty-string values
	        if (!minWidth) {
	            minWidth = 0;
	        } else if (minWidth == '*') {
	            minWidth = +a[i++];
	        } else if (minWidth.charAt(0) == '*') {
	            minWidth = +a[minWidth.slice(1, -1)];
	        } else {
	            minWidth = +minWidth;
	        }
	 
	        // Note: undocumented perl feature:
	        if (minWidth < 0) {
	            minWidth = -minWidth;
	            leftJustify = true;
	        }
	 
	        if (!isFinite(minWidth)) {
	            throw new Error('sprintf: (minimum-)width must be finite');
	        }
	 
	        if (!precision) {
	            precision = 'fFeE'.indexOf(type) > -1 ? 6 : (type == 'd') ? 0 : undefined;
	        } else if (precision == '*') {
	            precision = +a[i++];
	        } else if (precision.charAt(0) == '*') {
	            precision = +a[precision.slice(1, -1)];
	        } else {
	            precision = +precision;
	        }
	 
	        // grab value using valueIndex if required?
	        value = valueIndex ? a[valueIndex.slice(0, -1)] : a[i++];
	 
	        switch (type) {
	            case 's': return formatString(String(value), leftJustify, minWidth, precision, zeroPad, customPadChar);
	            case 'c': return formatString(String.fromCharCode(+value), leftJustify, minWidth, precision, zeroPad);
	            case 'b': return formatBaseX(value, 2, prefixBaseX, leftJustify, minWidth, precision, zeroPad);
	            case 'o': return formatBaseX(value, 8, prefixBaseX, leftJustify, minWidth, precision, zeroPad);
	            case 'x': return formatBaseX(value, 16, prefixBaseX, leftJustify, minWidth, precision, zeroPad);
	            case 'X': return formatBaseX(value, 16, prefixBaseX, leftJustify, minWidth, precision, zeroPad).toUpperCase();
	            case 'u': return formatBaseX(value, 10, prefixBaseX, leftJustify, minWidth, precision, zeroPad);
	            case 'i':
	            case 'd':
	                number = parseInt(+value, 10);
	                prefix = number < 0 ? '-' : positivePrefix;
	                value = prefix + pad(String(Math.abs(number)), precision, '0', false);
	                return justify(value, prefix, leftJustify, minWidth, zeroPad);
	            case 'e':
	            case 'E':
	            case 'f':
	            case 'F':
	            case 'g':
	            case 'G':
	                number = +value;
	                prefix = number < 0 ? '-' : positivePrefix;
	                method = ['toExponential', 'toFixed', 'toPrecision']['efg'.indexOf(type.toLowerCase())];
	                textTransform = ['toString', 'toUpperCase']['eEfFgG'.indexOf(type) % 2];
	                value = prefix + Math.abs(number)[method](precision);
	                return justify(value, prefix, leftJustify, minWidth, zeroPad)[textTransform]();
	            default: return substring;
	        }
	    };
	 
	    return format.replace(regex, doFormat);
	},
	
	clone : function (mixed) {
		
		var newObj = (mixed instanceof Array) ? [] : {};
		for (i in mixed) {
			if (mixed[i] && (typeof mixed[i] == "object") ) {
				newObj[i] = OWA.util.clone(mixed[i]);
			} else {
				newObj[i] = mixed[i];
			}
		}
		return newObj;
	},
	
	strtolower : function( str ) {
		
		return (str+'').toLowerCase();
	},
	
	in_array : function(needle, haystack, argStrict) {
	    // Checks if the given value exists in the array  
	    // 
	    // version: 1008.1718
	    // discuss at: http://phpjs.org/functions/in_array
	    // +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
	    // +   improved by: vlado houba
	    // +   input by: Billy
	    // +   bugfixed by: Brett Zamir (http://brett-zamir.me)
	    // *     example 1: in_array('van', ['Kevin', 'van', 'Zonneveld']);
	    // *     returns 1: true
	    // *     example 2: in_array('vlado', {0: 'Kevin', vlado: 'van', 1: 'Zonneveld'});
	    // *     returns 2: false
	    // *     example 3: in_array(1, ['1', '2', '3']);
	    // *     returns 3: true
	    // *     example 3: in_array(1, ['1', '2', '3'], false);
	    // *     returns 3: true
	    // *     example 4: in_array(1, ['1', '2', '3'], true);
	    // *     returns 4: false
	    var key = '', strict = !!argStrict;
	 
	    if (strict) {
	        for (key in haystack) {
	            if (haystack[key] === needle) {
	                return true;
	            }
	        }
	    } else {
	        for (key in haystack) {
	            if (haystack[key] == needle) {
	                return true;
	            }
	        }
	    }
	 
	    return false;
	}
}