class Uri {
	
	constructor( str ) {
		
    	this.components = {};
		this.dirty = false;
		this.options = {
            strictMode: false,
            key: ["source","protocol","authority","userInfo","user","password","host","port","relative","path","directory","file","query","anchor"],
            q:   {
                name:   "queryKey",
                parser: /(?:^|&)([^&=]*)=?([^&]*)/g
            },
            parser: {
                strict: /^(?:([^:\/?#]+):)?(?:\/\/((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?))?((((?:[^?#\/]*\/)*)([^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
                loose:  /^(?:(?![^:@]+:[^:@\/]*@)([^:\/?#.]+):)?(?:\/\/)?((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?)(((\/(?:[^?#](?![^?#\/]*\.[^?#\/.]+(?:[?#]|$)))*\/?)?([^?#\/]*))(?:\?([^#]*))?(?:#(.*))?)/
            }
    	};
    
		if ( str ) {
        	this.components = this.parseUri( str );
    	}
    }
    
    parseUri(str) {
        // parseUri 1.2.2
        // (c) Steven Levithan <stevenlevithan.com>
        // MIT License
        var o = this.options;
        var m   = o.parser[o.strictMode ? "strict" : "loose"].exec(str);
        var uri = {};
        var i   = 14;
    
        while (i--) uri[o.key[i]] = m[i] || "";
    
        uri[o.q.name] = {};
        uri[o.key[12]].replace(o.q.parser, function ($0, $1, $2) {
            if ($1) uri[o.q.name][$1] = $2;
        });
    
        return uri;
    }
    
    getHost() {
        
        if (this.components.hasOwnProperty('host')) {
            return this.components.host;
        }
    }
    
    getQueryParam( name ) {
        
        if ( this.components.hasOwnProperty('queryKey')
            && this.components.queryKey.hasOwnProperty(name) ) {
            return OWA.util.urldecode( this.components.queryKey[name] );
        }
    }
    
    isQueryParam( name ) {
    
        if ( this.components.hasOwnProperty('queryKey') 
            && this.components.queryKey.hasOwnProperty(name) ) {
            return true;
        } else {
            return false;
        }
    }
    
    getComponent( name ) {
    
        if ( this.components.hasOwnProperty( name ) ) {
            return this.components[name];
        }
    }
    
    getProtocol() {
        
        return this.getComponent('protocol');
    }
    
    getAnchor() {
        
        return this.getComponent('anchor');
    }
    
    getQuery() {
        
        return this.getComponent('query');
    }
    
    getFile() {
        
        return this.getComponent('file');
    }
    
    getRelative() {
    
        return this.getComponent('relative');
    }
    
    getDirectory() {
        
        return this.getComponent('directory');
    }
    
    getPath() {
        
        return this.getComponent('path');
    }
    
    getPort() {
    
        return this.getComponent('port');
    }
    
    getPassword() {
        
        return this.getComponent('password');
    }
    
    getUser() {
        
        return this.getComponent('user');
    }
    
    getUserInfo() {
    
        return this.getComponent('userInfo');
    }
    
    getQueryParams() {
    
        return this.getComponent('queryKey');
    }
    
    getSource() {
    
        return this.getComponent('source');
    }
    
    setQueryParam(name, value) {
        
        if ( ! this.components.hasOwnProperty('queryKey') ) {
            
            this.components.queryKey = {};
        }
        
        this.components.queryKey[name] = OWA.util.urlEncode(value);
        
        this.resetQuery();
    }
    
    removeQueryParam( name ) {
    
        if ( this.components.hasOwnProperty( 'queryKey' ) 
             && this.components.queryKey.hasOwnProperty( name )    
        ) {
            delete this.components.queryKey[name];            
            this.resetQuery();
        }
    }
    
    resetSource() {
    
        this.components.source = this.assembleUrl();
        //alert (this.components.source);
    }
    
    resetQuery() {
        
        var qp = this.getQueryParams();
        
        if (qp) {
            
            var query = '';
            var count = OWA.util.countObjectProperties(qp);
            var i = 1;
            
            for (var name in qp) {
                
                query += name + '=' + qp[name];
                
                if (i < count) {
                    query += '&';
                }    
            }
            
            this.components.query = query;
            
            this.resetSource();
        }
    }
    
    isDirty() {
        
        return this.dirty;
    }
    
    setPath( path ) {
    
    }
    
    assembleUrl() {
        
        var url = '';
        
        // protocol
        url += this.getProtocol();
        url += '://';
        // user
        if ( this.getUser() ) {
            url += this.getUser();
        }
        
        // password
        if ( this.getUser() && this.getPassword() ) {
            url += ':' + this.password();
        }
        // host
        url += this.getHost();
        
        // port
        if ( this.getPort() ) {
            url += ':' + this.getPort();
        }

        // directory
        url += this.getDirectory();

        // file
        url += this.getFile();
        
        // query params
        var query = this.getQuery();
        if (query) {
            url += '?' + query;
        }
        
        // query params
        var anchor = this.getAnchor();
        if (anchor) {
            url += '#' + anchor;
        }
        
        // anchor
        url += this.getAnchor();
        
        return url;
    }
}

export { Uri };