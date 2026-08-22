import { Util } from './Util.js';
import { OWA_instance as OWA } from './owa.js';

class StateManager {
	
	constructor() {
    
    	this.cookies = Util.readAllCookies();
		this.init = true;
		this.stores = {};
		this.storeFormats = {};
		this.storeMeta = {};

		/*
		 * Managing state, persisting it, and loading what was persisted are
		 * three different jobs. They used to be one: set() and get() both
		 * called load() on first touch, so putting a value into a store was
		 * what pulled the cookie in. A value set on this page and a value left
		 * by a previous session were therefore merged before anyone knew
		 * whether that previous session was still alive -- and once merged,
		 * nothing could tell them apart.
		 *
		 * Which of those jobs a given store takes part in, and when, is
		 * DECLARED AT REGISTRATION -- see registerStore(). It used to be
		 * hardcoded here as a set of maps keyed by one-letter store name, which
		 * meant a store's identity was declared in the tracker and its behaviour
		 * in this file, and registering a new store gave you no way to say how
		 * it should behave.
		 */
		this.hydrated = {};
		this.persistenceReleased = {};

		/*
		 * Storage migrations, run once the cookie domain is known.
		 *
		 * The point of the seam is that downstream code never learns a
		 * migration happened. Compatibility handled at the READ side spreads
		 * outwards -- every reader grows a fallback, every fallback is a place
		 * the old shape can come back, and they are never removed because no
		 * one can prove the last visitor holding the old shape is gone. The 'b'
		 * store is the worked example: reading it as a fallback kept visitors
		 * from losing values, and also carried a session-boundary leak into the
		 * store it was being moved away from.
		 *
		 * Normalising storage BEFORE anything reads it means readers only ever
		 * see the current shape, and a migration is finished work rather than a
		 * permanent branch. Pegged to the cookie domain because that is what a
		 * cookie migration genuinely depends on, and to nothing else -- a
		 * migration that waits on the session decision has been given a
		 * dependency it does not have.
		 */
		this.migrations = [];

		this.registerMigration( 'collapse-legacy-stores', function ( state ) {
			state.collapseLegacyStores();
		} );
	}

	/**
	 * Should a first touch of this store pull its cookie in?
	 *
	 * No for a store awaiting a session decision, and no for one that has no
	 * cookie at all.
	 */
	shouldAutoLoad( store_name ) {

		var behaviour = this.behaviourOf( store_name );

		if ( behaviour.persist === 'never' ) {
			return false;
		}

		if ( behaviour.hydrate === 'deferred'
			 && ! this.hydrated.hasOwnProperty( store_name ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Merge the persisted store into memory. MEMORY WINS.
	 *
	 * Called when the session that persisted those values is still running, so
	 * they are still current -- but a value set during this page load is newer
	 * than one a previous page persisted, so the merge fills gaps and never
	 * overwrites. Getting this backwards (Object.assign(memory, cookie)) is the
	 * natural mistake and silently discards the caller's write.
	 */
	hydrate( store_name ) {

		var persisted = this.readPersistedStore( store_name );

		this.hydrated[ store_name ] = true;

		if ( ! persisted.state ) {
			OWA.debug( 'Nothing persisted to hydrate store (%s) with', store_name );
			return;
		}

		if ( ! this.isPresent( store_name ) ) {
			this.stores[ store_name ] = persisted.state;
			this.storeFormats[ store_name ] = persisted.format;
			return;
		}

		var memory = this.stores[ store_name ];

		for ( var key in persisted.state ) {

			if ( persisted.state.hasOwnProperty( key )
				 && ! memory.hasOwnProperty( key ) ) {

				memory[ key ] = persisted.state[ key ];
			}
		}

		this.storeFormats[ store_name ] = persisted.format;
		OWA.debug( 'Hydrated store (%s): %s', store_name, JSON.stringify( memory ) );
	}

	/**
	 * Throw the persisted store away and keep memory as it stands.
	 *
	 * The session those values described has ended, so none of them carry over
	 * -- but anything set during this page load belongs to the session that is
	 * starting, and is in memory, untouched. That is the whole fix: the old
	 * values are discarded without having to be told apart from the new ones,
	 * because they were never mixed.
	 */
	discardPersisted( store_name ) {

		this.hydrated[ store_name ] = true;

		Util.eraseCookie( OWA.getSetting('ns') + store_name );
		this.cookies = Util.readAllCookies();

		OWA.debug( 'Discarded persisted store (%s); memory kept', store_name );
	}

	/**
	 * Read one value straight out of the persisted store, without merging
	 * anything into memory.
	 *
	 * For the values the session decision itself needs (last_req, the prior
	 * sid) which must be read BEFORE that decision can be made. A read cannot
	 * contaminate memory, so this does not weaken the separation -- the
	 * invariant is that nothing MERGES before the decision, not that nothing
	 * reads.
	 */
	getPersisted( store_name, key ) {

		var persisted = this.readPersistedStore( store_name );

		if ( persisted.state && persisted.state.hasOwnProperty( key ) ) {
			return persisted.state[ key ];
		}

		return '';
	}

	/**
	 * The session decision has been made. Settle every store that was waiting
	 * on it.
	 *
	 * A new session means the persisted values described a session that has
	 * ended, so they are discarded. A continuing session means they are still
	 * current, so they are merged in behind whatever this page load already
	 * set. Either way memory ends up holding exactly the values that belong to
	 * the session now in progress.
	 */
	resolveDeferredHydration( action, options ) {

		var discard = !! ( options && options.discard );
		var deferred = this.storesWhere( 'hydrateOn', action );

		for ( var i = 0; i < deferred.length; i++ ) {

			var store_name = deferred[ i ];

			if ( discard ) {
				this.discardPersisted( store_name );
			} else {
				this.hydrate( store_name );
			}
		}
	}

	/**
	 * Fold a legacy store into the one that now owns its values, and drop it.
	 *
	 * 'b' held session-scoped custom variables in a cookie of its own, beside
	 * the session store. Reading it as a fallback was enough to stop a visitor
	 * losing what they had, but not enough to be correct: because nothing ever
	 * cleared it at a session boundary, a variable left there by a session that
	 * had ended was still found by that read and put back on the wire. The store
	 * moved; the leak moved with it.
	 *
	 * THIS DEPENDS ON NOTHING, so it runs at tracker init rather than waiting
	 * for the session to be settled. It is a storage migration, not a session
	 * decision: values move cookie to cookie without passing through memory, so
	 * they keep their provenance -- persisted by a previous page load, and still
	 * persisted afterwards. Whatever the session decision turns out to be then
	 * applies to them exactly as it would have had they never moved.
	 *
	 * Merging into MEMORY instead would be the subtle version of the bug this
	 * whole design exists to prevent: memory is what marks a value as set by
	 * THIS page load, so a legacy value merged there would survive a new session
	 * that should have discarded it.
	 *
	 * Running at init also means it happens on a page that never tracks a
	 * pageview, so the extra cookie stops riding on requests at the first
	 * opportunity. That is what makes this a migration rather than an indefinite
	 * compatibility shim: it empties itself the first time each visitor is seen.
	 */
	/**
	 * Declare a storage migration.
	 *
	 * The callback receives the state manager and runs once per page, after the
	 * cookie domain is established and before anything downstream reads.
	 */
	registerMigration( name, callback ) {

		this.migrations.push( { 'name': name, 'callback': callback } );
	}

	/**
	 * Run the registered migrations.
	 *
	 * A migration that throws is logged and skipped rather than allowed to take
	 * the tracker down with it: a page that cannot migrate its cookies can still
	 * track, and the next page load tries again. That retry is the reason
	 * migrations have to tolerate finding their own half-finished work -- see
	 * collapseLegacyStores(), which finishes a previous run instead of
	 * repeating it.
	 */
	runMigrations() {

		for ( var i = 0; i < this.migrations.length; i++ ) {

			var migration = this.migrations[ i ];

			OWA.debug( 'Running state migration: %s', migration.name );

			try {
				migration.callback( this );
			} catch ( e ) {
				OWA.debug( 'State migration (%s) failed: %s', migration.name, e );
			}
		}
	}

	/**
	 * Does this store already hold values of the kind a legacy store carries?
	 *
	 * Custom-variable slots specifically, not merely "any key": the session
	 * store always holds sid, last_req and cdh, so their presence says nothing
	 * about whether a migration has run.
	 */
	holdsMigratedValues( state ) {

		if ( ! state || typeof state !== 'object' ) {
			return false;
		}

		for ( var key in state ) {

			if ( state.hasOwnProperty( key ) && /^cv[0-9]+$/.test( key ) ) {
				return true;
			}
		}

		return false;
	}

	collapseLegacyStores() {

		for ( var name in this.storeMeta ) {

			if ( ! this.storeMeta.hasOwnProperty( name ) ) {
				continue;
			}

			var legacy = name;
			var target = this.behaviourOf( legacy ).collapseInto;

			if ( ! target ) {
				continue;
			}

			var from = this.readPersistedStore( legacy );

			if ( ! from.state ) {
				continue;
			}

			var into = this.readPersistedStore( target );

			/*
			 * A previous run got half way. Writing the target and erasing the
			 * legacy store are two separate cookie operations, and finding
			 * migrated values already in the target while the legacy store is
			 * still here means the first succeeded and the second did not.
			 *
			 * Finish it rather than repeat it. The values are already across,
			 * so a second merge could only put back something that has been
			 * changed or removed since -- and it is the erase that failed, so
			 * the erase is what is owed.
			 */
			if ( this.holdsMigratedValues( into.state ) ) {

				OWA.debug( 'Legacy store (%s) already migrated into (%s); finishing the erase',
					legacy, target );
				this.clear( legacy );
				continue;
			}

			var merged = into.state || {};

			for ( var key in from.state ) {

				if ( ! from.state.hasOwnProperty( key ) || key === 'cdh' ) {
					continue;
				}

				merged[ key ] = from.state[ key ];
			}

			if ( OWA.getSetting('hashCookiesToDomain') && ! merged.hasOwnProperty('cdh') ) {
				merged.cdh = Util.getCookieDomainHash( OWA.getSetting('cookie_domain') );
			}

			this.writePersistedStore( target, merged, true );
			this.clear( legacy );

			OWA.debug( 'Collapsed legacy store (%s) into (%s)', legacy, target );
		}
	}

	/**
	 * The session is real and worth writing down.
	 *
	 * Flushes the session-bound stores and leaves persistence on, so later
	 * writes go to the cookie as they are made. Wired to the 'persistSession'
	 * action.
	 */
	releaseDeferredPersistence( action ) {

		var released = this.storesWhere( 'persistOn', action );

		for ( var i = 0; i < released.length; i++ ) {

			this.persistenceReleased[ released[ i ] ] = true;

			if ( this.isPresent( released[ i ] ) ) {
				this.persist( released[ i ] );
			}
		}
	}
        
    /**
     * Declare a state store.
     *
     * `behaviour` is optional and says how the store takes part in hydration
     * and persistence. Defaults match what every store did before any of this
     * existed, so an existing four-argument call keeps its meaning:
     *
     *   hydrate: 'eager'      load the cookie on first touch (default)
     *            'deferred'   do not load until the session decision settles
     *                         it -- see resolveDeferredHydration(). Memory then
     *                         holds only what THIS page load set, which is what
     *                         makes new values distinguishable from old ones.
     *
     *   persist: 'immediate'  write the cookie on every set (default)
     *            'deferred'    hold in memory until persistOn fires, then write
     *                          on every set from there on
     *            'never'       memory only, for the life of the page
     *
     *   collapseInto: '<store>'  legacy store, folded into <store> and erased
     *                            by the collapse-legacy-stores migration
     *
     * A deferred store also names the ACTION it waits for, so stores are not
     * obliged to wait for the same thing:
     *
     *   hydrateOn: '<action>'  default 'isSessionizationDone'
     *   persistOn: '<action>'  default 'persistSession'
     *
     * The hydration action's payload carries `discard` -- true when the
     * persisted values describe something that has ended and do not carry over,
     * false when they still apply and should be merged in behind memory. That
     * is the whole contract, so a store can wait on a decision that has nothing
     * to do with sessions and still be settled correctly.
     */
    registerStore( name, expiration, length, format, behaviour ) {
	    
        behaviour = behaviour || {};

        var hydrate = behaviour.hydrate || 'eager';
        var persist = behaviour.persist || 'immediate';

        this.storeMeta[name] = {
            'expiration'   : expiration,
            'length'       : length,
            'format'       : format,
            'hydrate'      : hydrate,
            'persist'      : persist,
            'collapseInto' : behaviour.collapseInto || '',
            'hydrateOn'    : hydrate === 'deferred'
                             ? ( behaviour.hydrateOn || 'isSessionizationDone' ) : '',
            'persistOn'    : persist === 'deferred'
                             ? ( behaviour.persistOn || 'persistSession' ) : ''
        };

        this.subscribeStoreActions( name );
    }

    /**
     * Make sure something is listening for the actions this store waits on.
     *
     * The listener is owned by OWA rather than by this object: replacing
     * OWA.state with a fresh manager must not leave a listener bound to the old
     * one, and must not add a second listener either. OWA de-duplicates by
     * action name and resolves the CURRENT manager when the action fires.
     */
    subscribeStoreActions( store_name ) {

        var behaviour = this.behaviourOf( store_name );

        if ( behaviour.hydrateOn ) {
            OWA.ensureStateActionSubscription( 'hydrate', behaviour.hydrateOn );
        }

        if ( behaviour.persistOn ) {
            OWA.ensureStateActionSubscription( 'persist', behaviour.persistOn );
        }
    }

    /**
     * How a store behaves. Defaulted, so an unregistered store -- one written
     * to before the tracker registered it -- behaves the way every store did
     * before any of this existed rather than throwing.
     */
    behaviourOf( store_name ) {

        var meta = this.storeMeta[ store_name ];

        return {
            'hydrate'      : ( meta && meta.hydrate ) || 'eager',
            'persist'      : ( meta && meta.persist ) || 'immediate',
            'collapseInto' : ( meta && meta.collapseInto ) || '',
            'hydrateOn'    : ( meta && meta.hydrateOn ) || '',
            'persistOn'    : ( meta && meta.persistOn ) || ''
        };
    }

    /** Registered store names whose behaviour matches, e.g. ('persist','session'). */
    storesWhere( setting, value ) {

        var names = [];

        for ( var name in this.storeMeta ) {
            if ( this.storeMeta.hasOwnProperty( name )
                 && this.behaviourOf( name )[ setting ] === value ) {
                names.push( name );
            }
        }

        return names;
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
        
        if ( ! this.isPresent( store_name ) && this.shouldAutoLoad( store_name ) ) {
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

        // Memory is now current for every event on this page. Persistence is a
        // separate concern -- see persist().
        this.persist( store_name, is_perminant );
    }

    /**
     * Serialize a store to its cookie.
     *
     * Both gates below are per-STORE, which is the granularity that matters:
     * the cookie is written whole, so a write to any one key re-serializes
     * every other key beside it.
     */
    persist( store_name, is_perminant ) {

        if ( ! this.isPresent( store_name ) ) {
            return;
        }

        var behaviour = this.behaviourOf( store_name );

        // Memory-only store: there is no cookie to write.
        if ( behaviour.persist === 'never' ) {
            return;
        }

        // Session-bound store, and nothing has declared the session persistable
        // yet. Memory already has the value; the cookie waits.
        if ( behaviour.persist === 'deferred'
             && ! this.persistenceReleased.hasOwnProperty( store_name ) ) {

            OWA.debug( 'Holding store (%s) in memory; not released for persistence yet', store_name );
            return;
        }

        var snapshot = this.stores[store_name];

        this.writePersistedStore( store_name, snapshot, is_perminant );
    }

    /**
     * Serialize a snapshot to a store's cookie.
     *
     * Split out of persist() so a snapshot that did not come from memory can be
     * written too -- collapseLegacyStores() moves values from one cookie to
     * another without either of them passing through the memory store, and must
     * not be subject to the gates persist() applies to memory writes.
     */
    writePersistedStore( store_name, snapshot, is_perminant ) {

        var format = this.getFormat(store_name);

        if ( ! format ) {

            // check the orginal format that the state store was loaded from.
            if (this.storeFormats.hasOwnProperty(store_name)) {
                format = this.storeFormats[store_name];
            }
        }

        var state_value = '';

        if (format === 'json') {
            state_value = JSON.stringify(snapshot);
        } else {
            state_value = Util.assocStringFromJson(snapshot);
        }

        var expiration_days = this.getExpirationDays( store_name );
        
        if ( ! expiration_days ) {
            
            if ( is_perminant ) {
                expiration_days =  364;
            }
        }
        
        // set or reset the campaign cookie
        OWA.debug('Populating state store (%s) with value: %s', store_name, state_value);
        var domain = OWA.getSetting('cookie_domain') || document.domain;
        Util.setCookie( OWA.getSetting('ns') + store_name, state_value, expiration_days, '/', domain );
    }
    
    replaceStore(store_name, value, is_perminant, format, expiration_days) {
        
        OWA.debug('replace state format: %s, value: %s',format, JSON.stringify(value));
        if ( store_name ) {
        
            if (value) {

                format = this.getFormat(store_name);
                this.stores[store_name] = value;
                this.storeFormats[store_name] = format;
            }

            // Route through persist() rather than writing the cookie here, so a
            // replace (notably clear(store, key), which rebuilds the store minus
            // one key) honours the deferred-key filter. Writing directly would
            // serialize an undelivered sid straight back into the cookie.
            OWA.debug('About to replace state store (%s)', store_name);
            this.persist( store_name, is_perminant );
        }
    }
        
    getStateFromCookie(store_name) {
        
        var store = unescape( Util.readCookie( OWA.getSetting('ns') + store_name ) );
        if ( store ) {
            return store;
        }
    }
    
    get(store_name, key) {
        
        if ( ! this.isPresent( store_name ) && this.shouldAutoLoad( store_name ) ) {
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
    
    /**
     * Read and decode a store's cookie. Returns { state, format } and touches
     * neither memory nor the cookie.
     *
     * Split out of load() so that hydrate() can merge the same decoded value
     * instead of replacing memory with it -- the decode, the domain-hash check
     * and the format sniff are identical either way, and only what is done with
     * the result differs.
     */
    readPersistedStore( store_name ) {

        var state = '';
        var format = '';
        var cookie_values = this.getCookieValues( OWA.getSetting('ns') + store_name );

        if (cookie_values) {

            for (var i=0;i < cookie_values.length;i++) {

                var raw_cookie_value = unescape( cookie_values[i] );
                var cookie_value = Util.decodeCookieValue( raw_cookie_value );
                format = Util.getCookieValueFormat( raw_cookie_value );

                if ( OWA.getSetting('hashCookiesToDomain') ) {
                    var domain = OWA.getSetting('cookie_domain');
                    var dhash = Util.getCookieDomainHash(domain);
                    if ( cookie_value.hasOwnProperty( 'cdh' ) ) {
                        if ( cookie_value.cdh == dhash ) {
                            OWA.debug('Cookie: %s, index: %s domain hash matches current cookie domain. Loading...', store_name, i);
                            state = cookie_value;
                            break;
                        } else {
                            OWA.debug('Cookie: %s, index: %s domain hash does not match current cookie domain. Not loading.', store_name, i);
                        }
                    } else {
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

        return { 'state': state, 'format': format };
    }

    /**
     * Replace the memory store with what was persisted.
     *
     * Note the asymmetry with hydrate(): load() overwrites, so it is only safe
     * where memory holds nothing worth keeping. Stores listed in
     * a store registered with hydrate:'deferred' does not come through here.
     */
    load(store_name) {

        var persisted = this.readPersistedStore( store_name );

        if ( persisted.state ) {
            this.stores[store_name] = persisted.state;
            this.storeFormats[store_name] = persisted.format;
            OWA.debug('Loaded state store: %s with: %s', store_name, JSON.stringify(persisted.state));
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
                delete state[key];
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