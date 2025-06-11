import { StateManager } from './StateManager.js';
import { Util } from './Util.js';

/**
 * OWA Global Object 
 *	
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.openwebanalytics.com/licenses/ BSD-3 Clause
 */
class OWA {
	
	constructor() {

	    this.items = {};
	    
	    this.hooks = {
		    actions: {},
		    filters: {}
	    };
	    
	    this.overlay = '';
	    this.config = {
	        ns: 'owa_',
	        baseUrl: '',
	        hashCookiesToDomain: true,
	        debug: false
	    };
	    
	    this.state = {};
	    this.overlayActive = false;
    }
    
    // depricated
    setSetting( name, value ) {
	    
        return this.setOption(name, value);
    }
    
    // depricated
    getSetting(name) {
	    
        return this.getOption(name);
    }
    
    setOption(name, value) {
	    
        this.config[name] = value;
    }
    
    getOption(name) {
	    
        return this.config[name];
    }
    
    // localize wrapper
    l(string) {
        
        return string;
    }
    
    initializeStateManager() {
        
        if ( ! this.state.hasOwnProperty('init') ) {
            
            this.debug('initializing state manager...');
            this.state = new StateManager();
        }
    }
    
    registerStateStore( name, expiration, length, format ) {
	    
        this.initializeStateManager();
        return this.state.registerStore(name, expiration, length, format);
    }
    
    checkForState( store_name ) {
    
        this.initializeStateManager();
        return this.state.isPresent( store_name );
    }
    
    setState(store_name, key, value, is_perminant,format, expiration_days) {
    
        this.initializeStateManager();
        return this.state.set(store_name, key, value, is_perminant,format, expiration_days);    
    }
    
    replaceState(store_name, value, is_perminant, format, expiration_days) {
    
        this.initializeStateManager();
        return this.state.replaceStore(store_name, value, is_perminant, format, expiration_days);
    }
    
    getStateFromCookie(store_name) {
    
        this.initializeStateManager();
        return this.state.getStateFromCookie(store_name);
    }
    
    getState(store_name, key) {
        
        this.initializeStateManager();
        return this.state.get(store_name, key);
    }
    
    clearState(store_name, key) {
    
        this.initializeStateManager();
        return this.state.clear(store_name, key);
    }
    
    getStateStoreFormat(store_name) {
    
        this.initializeStateManager();
        return this.state.getStoreFormat(store_name);
    }
    
    setStateStoreFormat(store_name, format) {
    
        this.initializeStateManager();
        return this.state.setStoreFormat(store_name, format);
    }
    
    debug() {
        
        var debugging = this.getSetting('debug') || false; // or true
        
        if ( debugging ) {
        
            if( window.console ) {
                
                if (console.log.apply) {
                
                    if (window.console.firebug) { 
                         console.log.apply(this, arguments);
                    } else {
                        console.log.apply(console, arguments);
                    }
                }
            }
        }
    }
    
    setApiEndpoint(endpoint) {
	    
        this.config['rest_api_endpoint'] = endpoint;
    }
    
    getApiEndpoint() {
	    
        return this.config['rest_api_endpoint'] || this.getSetting('baseUrl') + 'api/';
    }
    
    loadHeatmap(p) {
	    
        var that = this;
        
        // load css for heatmap class
        Util.loadCss(this.getSetting('baseUrl')+'/modules/base/css/owa.overlay.css', function(){});
        
        // dynamic import of the Heatmap class
	    import(/* webpackChunkName: "owa.heatmap" */ '../tracker/Heatmap.js').then( ( { Heatmap } ) => { 
    
	        that.overlay = new Heatmap();
            //hm.setParams(p);
            //hm.options.demoMode = true;
            that.overlay.options.liveMode = true;
            that.overlay.generate();
        });
    }
    
    loadPlayer() {
	    
        this.debug("about to load Domstream Player");
        
        var that = this;
        
        //Util.loadScript(this.getSetting('baseUrl')+'/modules/base/js/includes/jquery/jquery-1.6.4.min.js', function(){});
        Util.loadCss(this.getSetting('baseUrl')+'/modules/base/css/owa.overlay.css', function(){});
        //Util.loadScript(this.getSetting('baseUrl')+'/modules/base/js/owa.player.js', function(){
	    
	    // dynamic import of the Player class
	    import(/* webpackChunkName: "owa.player" */ '../tracker/Player.js').then( ( { Player } ) => { 
			that.debug("Loading Domstream Player");
            that.overlay = new Player();   
            that.overlay.init(); 
        });    
    }
    
    startOverlaySession(p) {
        
        // set global is overlay actve flag
        this.overlayActive = true;
        //alert(JSON.stringify(p));
        
        if (p.hasOwnProperty('api_url')) {
                
            this.setApiEndpoint(p.api_url);
        }
        
        // get param from cookie    
        //var params = Util.parseCookieStringToJson(p);
        var params = p;
        // evaluate the action param
        if (params.action === 'loadHeatmap') {
            this.loadHeatmap(p);
        } else if (params.action === 'loadPlayer') {
            this.loadPlayer(p);
        }
        
    }
    
    endOverlaySession() {
                
        Util.eraseCookie(this.getSetting('ns') + 'overlay', document.domain);
        this.overlayActive = false;
    }
    
    /**
	 * Add a new Filter callback
	 * Note: filter functions must return the value variable.
	 *
	 * @param	tag			string	 	The tag that will be called by applyFilters
	 * @param	callback	function	The callback function to call
	 * @param	priority 	int			Priority of filter to apply.
	 * @return	value		mixed		the value to return.	
	 */
    addFilter( tag, callback, priority ) {
		
		if( "undefined" === typeof priority ) {
	        
	        priority = 10;
	    }
	
	    // Make tag if it doesn't already exist
	    this.hooks.filters[ tag ] = this.hooks.filters[ tag ] || [];
	    this.hooks.filters[ tag ].push( { priority: priority, callback: callback } );  
    }
    
    /**
	 * Add a new Action callback
	 *
	 * @param	tag			string	 	The tag that will be called by doAction
	 * @param	callback	function	The callback function to call
	 * @param	priority 	int			Priority of filter to apply.
	 */

    addAction( tag, callback, priority ) {
	    
	    this.debug('Adding Action callback for: ' + tag);
	    
	    if( typeof priority === "undefined" ) {
	        priority = 10;
	    }
	
	    // Make tag if it doesn't already exist
	    this.hooks.actions[ tag ] = this.hooks.actions[ tag ] || [];
		this.hooks.actions[ tag ].push( { priority: priority, callback: callback } );
	}
	
	/**
	 * trigger filter callbacks
	 *
	 * @param 	tag			string			filter name
	 * @param	value		mixed			the value being filtered
	 * @param	options		object||array	Optional object to pass to the callbacks
	 */
	applyFilters( tag, value, options ) {
		
		this.debug('Filtering ' + tag + ' with value:');
		this.debug(value);
		
		var filters = [];
		
	    if( "undefined" !== typeof this.hooks.filters[ tag ] && this.hooks.filters[ tag ].length > 0 ) {
			this.debug('Applying filters for ' + tag);
	        this.hooks.filters[ tag ].forEach( function( hook ) {
				
	            filters[ hook.priority ] = filters[ hook.priority ] || [];
	            filters[ hook.priority ].push( hook.callback );
	        } );
	
	        filters.forEach( function( hooks ) {
	
	            hooks.forEach( function( callback ) {
	                value = callback( value, options );
	                
	                this.debug('Filter returned value: ');
	                this.debug(value);
	            } );
	
	        } );
	    }
	
	    return value;
	}
	
	/**
	 * trigger action callbacks
	 *
	 * @param 	tag		 string			A registered tag
	 * @param	options	 object||array	Optional object to pass to the callbacks
	 */
	doAction( tag, options ) {
		
		this.debug('Doing Action: ' + tag);
		
		var actions = [];

	    if( "undefined" !== typeof this.hooks.actions[ tag ]  && this.hooks.actions[ tag ].length > 0 ) {
			this.debug(this.hooks.actions[ tag ]);
	        this.hooks.actions[ tag ].forEach( function( hook ) {
	
	            actions[ hook.priority ] = actions[ hook.priority ] || [];
	            actions[ hook.priority ].push( hook.callback );
	
	        } );
	
	        actions.forEach( function( hooks ) {
				this.debug('Executing Action callabck for: ' + tag);
	            hooks.forEach( function( callback ) {
	                callback( options );
	            } );
	
	        } );
	    }
	}
	
	/**
	 * Remove an Action callback
	 *
	 * Must be the exact same callback signature.
	 * Note: Anonymous functions can not be removed.
	 * @param tag		The tag specified by applyFilters
	 * @param callback	The callback function to remove
	 */
	removeAction( tag, callback ) {
		
		this.hooks.actions[ tag ] = this.hooks.actions[ tag ] || [];

	    this.hooks.actions[ tag ].forEach( function( filter, i ) {
		    
	        if( filter.callback === callback ) {
	            this.hooks.actions[ tag ].splice(i, 1);
	        }
	        
	    });
	}
	
	/**
	 * Remove a Filter callback
	 *
	 * Must be the exact same callback signature.
	 * Note: Anonymous functions can not be removed.
	 * @param tag		The tag specified by applyFilters
	 * @param callback	The callback function to remove
	 */
	removeFilter( tag, callabck ) {
		
		this.hooks.filters[ tag ] = this.hooks.filters[ tag ] || [];
	
	    this.hooks.filters[ tag ].forEach( function( filter, i ) {
		    
	        if( filter.callback === callback ) {
	            this.hooks.filters[ tag ].splice(i, 1);
	        }
	        
	    });
	}
}

const OWA_instance = new OWA();
export { OWA_instance };