var OWA = {

	items: new Object,
	config: new Object,
	setSetting: function(name, value) {
		this.config[name] = value;
	},
	
	getSetting: function(name) {
		return this.config[name];
	}

};


OWA.util =  {

	ns: function(string) {
	
		return OWA.config.ns + string;
	
	},
	
	nsAll: function(obj) {
	
		var nsObj = new Object();
		
		for(param in obj) {  // print out the params
	       
			nsObj[OWA.config.ns+param] = obj[param];
			
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
	
	setCookie2: function (name,value,days,path,domain,secure) {
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
		//console.log(this.readCookie('owa_v'));
		
		var domain = OWA.getSetting('cookie_domain') || document.domain;
		//console.log(domain);
		this.setCookie2(name,"",-1,"",domain);
		var test = this.readCookie(name);
		
		if (test) {
			domain = "."+domain;
			console.log(domain);
			this.setCookie2(name,"",-1,"",domain);	
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
			var key = keyValues[i].split("=>");
			queryAsAssoc[key[0]] = key[1];
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
			var key = keyValues[i].split("=>");
			queryAsObj[key[0]] = key[1];
			//alert(key[0] +"="+ key[1]);
		}
		//alert (queryAsObj.period);
		return queryAsObj;
	},
	
	nsParams: function(obj) {
		var new_obj = new Object;
		
		for(param in obj) {
			new_obj['owa_'+ param] = obj[param];
		}
		
		return new_obj;
	}

}




