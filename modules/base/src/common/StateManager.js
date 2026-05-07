import { Util } from './Util.js';
import { OWA_instance as OWA } from './owa.js';

class StateManager {
	
	constructor() {
    
    	this.cookies = Util.readAllCookies();
		this.init = true;
		this.stores = {};
		this.storeFormats = {};
		this.storeMeta = {};
	}
        
    registerStore( name, expiration, length, format ) {
	    
        this.storeMeta[name] = {'expiration' : expiration, 'length': length, 'format' : format};
    }
    
    getExpirationDays( store_name ) {
        
        if ( this.storeMeta.hasOwnProperty( store_name ) ) {
            
            return this.storeMeta[store_name].expiration;
        }
    }
    
    getFormat( store_name ) {
        
        if ( this.storeMeta.hasOwnProperty( store_name ) ) {
            
            return this.storeMeta[store_name].format;
        }
    }
    
    isPresent( store_name ) {
        
        if ( this.stores.hasOwnProperty( store_name ) ) {
            return true;
        }
    }
    
    set(store_name, key, value, is_perminant,format, expiration_days) {
        
        if ( ! this.isPresent( store_name ) ) {
            this.load( store_name );
        }
        
        if ( ! this.isPresent( store_name ) ) {
            OWA.debug( 'Creating state store (%s)', store_name );
            this.stores[store_name] = {};
            // add cookie domain hash
            if ( OWA.getSetting( 'hashCookiesToDomain' ) ) {
                this.stores[store_name].cdh = Util.getCookieDomainHash(OWA.getSetting('cookie_domain'));
            }
        }
        
        if ( key ) {
            this.stores[store_name][key] = value;
        } else {
            this.stores[store_name] = value;
        }
        
        format = this.getFormat(store_name);
        
        if ( ! format ) {
            
            // check the orginal format that the state store was loaded from.
            if (this.storeFormats.hasOwnProperty(store_name)) {
                format = this.storeFormats[store_name];
            }
        }
        
        var state_value = '';
        
        if (format === 'json') {
            state_value = JSON.stringify(this.stores[store_name]);
        } else {
            state_value = Util.assocStringFromJson(this.stores[store_name]);
        }
        
        expiration_days = this.getExpirationDays( store_name );
        
        if ( ! expiration_days ) {
            
            if ( is_perminant ) {
                expiration_days =  364;
            }
        }
        
        // set or reset the campaign cookie
        OWA.debug('Populating state store (%s) with value: %s', store_name, state_value);
        var domain = OWA.getSetting('cookie_domain') || document.domain;
        // erase cookie
        //Util.eraseCookie( 'owa_'+store_name, domain );
        // set cookie
        Util.setCookie( OWA.getSetting('ns') + store_name, state_value, expiration_days, '/', domain );
    }
    
    replaceStore(store_name, value, is_perminant, format, expiration_days) {
        
        OWA.debug('replace state format: %s, value: %s',format, JSON.stringify(value));
        if ( store_name ) {
        
            if (value) {
                
                format = this.getFormat(store_name);
                this.stores[store_name] = value;
                this.storeFormats[store_name] = format;
                
                var cookie_value = '';
                
                if (format === 'json') {
                    cookie_value = JSON.stringify(value);
                } else {
                    cookie_value = Util.assocStringFromJson(value);
                }
            }
        
            var domain = OWA.getSetting('cookie_domain') || document.domain;
            
            expiration_days = this.getExpirationDays( store_name );
            
            OWA.debug('About to replace state store (%s) with: %s', store_name, cookie_value);
            Util.setCookie( OWA.getSetting('ns') + store_name, cookie_value, expiration_days, '/', domain );
            
        }
    }
        
    getStateFromCookie(store_name) {
        
        var store = unescape( Util.readCookie( OWA.getSetting('ns') + store_name ) );
        if ( store ) {
            return store;
        }
    }
    
    get(store_name, key) {
        
        if ( ! this.isPresent( store_name ) ) {
            this.load(store_name);
        }
        
        if ( this.isPresent( store_name ) ) {
            if ( key ) {
                if ( this.stores[store_name].hasOwnProperty( key ) ) {        
                    return this.stores[store_name][key];
                }        
            } else {
                return this.stores[store_name];
            }
        } else {
            OWA.debug('No state store (%s) was found', store_name);
            return '';
        }
        
    }
    
    getCookieValues(cookie_name) {
        
        if (this.cookies.hasOwnProperty(cookie_name)) {
            return this.cookies[cookie_name];
        }
    }
    
    load(store_name) {
        
        var state = '';
        var cookie_values = this.getCookieValues( OWA.getSetting('ns') + store_name );
       
        if (cookie_values) {
             
            for (var i=0;i < cookie_values.length;i++) {
                
                
                var raw_cookie_value = unescape( cookie_values[i] );
                var cookie_value = Util.decodeCookieValue( raw_cookie_value );
                //OWA.debug(raw_cookie_value);
                var format = Util.getCookieValueFormat( raw_cookie_value );
				
				OWA.debug(OWA.config);
                if ( OWA.getSetting('hashCookiesToDomain') ) {
                    var domain = OWA.getSetting('cookie_domain');
                    var dhash = Util.getCookieDomainHash(domain);
					OWA.debug('cookie hash found:');
					OWA.debug(dhash);
                    if ( cookie_value.hasOwnProperty( 'cdh' ) ) {
                        OWA.debug( 'Cookie value cdh: %s, domain hash: %s', cookie_value.cdh, dhash );
                        if ( cookie_value.cdh == dhash ) {
                            OWA.debug('Cookie: %s, index: %s domain hash matches current cookie domain. Loading...', store_name, i);
                            state = cookie_value;
                            break;
                        } else {
                            OWA.debug('Cookie: %s, index: %s domain hash does not match current cookie domain. Not loading.', store_name, i);
                        }
                    } else {
                        //OWA.debug(cookie_value);
                        OWA.debug('Cookie: %s, index: %s has no domain hash. Not going to Load it.', store_name, i);
                    }
                
                } else {
                    // just get the last cookie set by that name
                    var lastIndex = cookie_values.length -1 ;
                    if (i === lastIndex) {
                        state = cookie_value;
                    }
                }
            }
        }    
            
        if ( state ) {            
            this.stores[store_name] = state;
            this.storeFormats[store_name] = format;
            OWA.debug('Loaded state store: %s with: %s', store_name, JSON.stringify(state));
        } else {
            
            OWA.debug('No state for store: %s was found. Nothing to Load.', store_name);
        }
    }
    
    clear(store_name, key) {
        // delete cookie
        
        if ( ! key ) {
            delete this.stores[store_name];
            Util.eraseCookie(OWA.getSetting('ns') + store_name);
            //reload cookies
            this.cookies = Util.readAllCookies();
        } else {
            var state = this.get(store_name);
            
            if ( state && state.hasOwnProperty( key ) ) {
                delete state['key'];
                this.replaceStore(store_name, state, true, this.getFormat( store_name ),  this.getExpirationDays( store_name ) );
            }
        }
    }
    
    getStoreFormat( store_name ) {
        
        return this.getFormat(store_name);
    }
    
    setStoreFormat( store_name, format ) {
        
        this.storeFormats[store_name] = format;
    }
}

export { StateManager };