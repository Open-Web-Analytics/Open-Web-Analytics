/******/ (function(modules) { // webpackBootstrap
/******/ 	// The module cache
/******/ 	var installedModules = {};
/******/
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/
/******/ 		// Check if module is in cache
/******/ 		if(installedModules[moduleId]) {
/******/ 			return installedModules[moduleId].exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = installedModules[moduleId] = {
/******/ 			i: moduleId,
/******/ 			l: false,
/******/ 			exports: {}
/******/ 		};
/******/
/******/ 		// Execute the module function
/******/ 		modules[moduleId].call(module.exports, module, module.exports, __webpack_require__);
/******/
/******/ 		// Flag the module as loaded
/******/ 		module.l = true;
/******/
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/
/******/
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = modules;
/******/
/******/ 	// expose the module cache
/******/ 	__webpack_require__.c = installedModules;
/******/
/******/ 	// define getter function for harmony exports
/******/ 	__webpack_require__.d = function(exports, name, getter) {
/******/ 		if(!__webpack_require__.o(exports, name)) {
/******/ 			Object.defineProperty(exports, name, { enumerable: true, get: getter });
/******/ 		}
/******/ 	};
/******/
/******/ 	// define __esModule on exports
/******/ 	__webpack_require__.r = function(exports) {
/******/ 		if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 			Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 		}
/******/ 		Object.defineProperty(exports, '__esModule', { value: true });
/******/ 	};
/******/
/******/ 	// create a fake namespace object
/******/ 	// mode & 1: value is a module id, require it
/******/ 	// mode & 2: merge all properties of value into the ns
/******/ 	// mode & 4: return value when already ns object
/******/ 	// mode & 8|1: behave like require
/******/ 	__webpack_require__.t = function(value, mode) {
/******/ 		if(mode & 1) value = __webpack_require__(value);
/******/ 		if(mode & 8) return value;
/******/ 		if((mode & 4) && typeof value === 'object' && value && value.__esModule) return value;
/******/ 		var ns = Object.create(null);
/******/ 		__webpack_require__.r(ns);
/******/ 		Object.defineProperty(ns, 'default', { enumerable: true, value: value });
/******/ 		if(mode & 2 && typeof value != 'string') for(var key in value) __webpack_require__.d(ns, key, function(key) { return value[key]; }.bind(null, key));
/******/ 		return ns;
/******/ 	};
/******/
/******/ 	// getDefaultExport function for compatibility with non-harmony modules
/******/ 	__webpack_require__.n = function(module) {
/******/ 		var getter = module && module.__esModule ?
/******/ 			function getDefault() { return module['default']; } :
/******/ 			function getModuleExports() { return module; };
/******/ 		__webpack_require__.d(getter, 'a', getter);
/******/ 		return getter;
/******/ 	};
/******/
/******/ 	// Object.prototype.hasOwnProperty.call
/******/ 	__webpack_require__.o = function(object, property) { return Object.prototype.hasOwnProperty.call(object, property); };
/******/
/******/ 	// __webpack_public_path__
/******/ 	__webpack_require__.p = "/";
/******/
/******/
/******/ 	// Load entry module and return exports
/******/ 	return __webpack_require__(__webpack_require__.s = 0);
/******/ })
/************************************************************************/
/******/ ({

/***/ "./modules/base/js/owa.js":
/*!********************************!*\
  !*** ./modules/base/js/owa.js ***!
  \********************************/
/*! no static exports found */
/***/ (function(module, exports) {

function _typeof(obj) { "@babel/helpers - typeof"; if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") { _typeof = function _typeof(obj) { return typeof obj; }; } else { _typeof = function _typeof(obj) { return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }; } return _typeof(obj); }

/**
 * OWA Global Object 
 *	
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.openwebanalytics.com/licenses/ BSD-3 Clause
 */
var OWA = {
  items: {},
  hooks: {
    actions: {},
    filters: {}
  },
  loadedJsLibs: {},
  overlay: '',
  config: {
    ns: 'owa_',
    baseUrl: '',
    hashCookiesToDomain: true,
    debug: false
  },
  state: {},
  overlayActive: false,
  // depricated
  setSetting: function setSetting(name, value) {
    return this.setOption(name, value);
  },
  // depricated
  getSetting: function getSetting(name) {
    return this.getOption(name);
  },
  setOption: function setOption(name, value) {
    this.config[name] = value;
  },
  getOption: function getOption(name) {
    return this.config[name];
  },
  // localize wrapper
  l: function l(string) {
    return string;
  },
  requireJs: function requireJs(name, url, callback) {
    if (!this.isJsLoaded(name)) {
      OWA.util.loadScript(url, callback);
    }

    this.loadedJsLibs[name] = url;
  },
  isJsLoaded: function isJsLoaded(name) {
    if (this.loadedJsLibs.hasOwnProperty(name)) {
      return true;
    }
  },
  initializeStateManager: function initializeStateManager() {
    if (!this.state.hasOwnProperty('init')) {
      OWA.debug('initializing state manager...');
      this.state = new OWA.stateManager();
    }
  },
  registerStateStore: function registerStateStore(name, expiration, length, format) {
    this.initializeStateManager();
    return this.state.registerStore(name, expiration, length, format);
  },
  checkForState: function checkForState(store_name) {
    this.initializeStateManager();
    return this.state.isPresent(store_name);
  },
  setState: function setState(store_name, key, value, is_perminant, format, expiration_days) {
    this.initializeStateManager();
    return this.state.set(store_name, key, value, is_perminant, format, expiration_days);
  },
  replaceState: function replaceState(store_name, value, is_perminant, format, expiration_days) {
    this.initializeStateManager();
    return this.state.replaceStore(store_name, value, is_perminant, format, expiration_days);
  },
  getStateFromCookie: function getStateFromCookie(store_name) {
    this.initializeStateManager();
    return this.state.getStateFromCookie(store_name);
  },
  getState: function getState(store_name, key) {
    this.initializeStateManager();
    return this.state.get(store_name, key);
  },
  clearState: function clearState(store_name, key) {
    this.initializeStateManager();
    return this.state.clear(store_name, key);
  },
  getStateStoreFormat: function getStateStoreFormat(store_name) {
    this.initializeStateManager();
    return this.state.getStoreFormat(store_name);
  },
  setStateStoreFormat: function setStateStoreFormat(store_name, format) {
    this.initializeStateManager();
    return this.state.setStoreFormat(store_name, format);
  },
  debug: function debug() {
    var debugging = OWA.getSetting('debug') || false; // or true

    if (debugging) {
      if (window.console) {
        if (console.log.apply) {
          if (window.console.firebug) {
            console.log.apply(this, arguments);
          } else {
            console.log.apply(console, arguments);
          }
        }
      }
    }
  },
  setApiEndpoint: function setApiEndpoint(endpoint) {
    this.config['rest_api_endpoint'] = endpoint;
  },
  getApiEndpoint: function getApiEndpoint() {
    return this.config['rest_api_endpoint'] || this.getSetting('baseUrl') + 'api/';
  },
  loadHeatmap: function loadHeatmap(p) {
    var that = this;
    OWA.util.loadScript(OWA.getSetting('baseUrl') + '/modules/base/js/includes/jquery/jquery-1.6.4.min.js', function () {});
    OWA.util.loadCss(OWA.getSetting('baseUrl') + '/modules/base/css/owa.overlay.css', function () {});
    OWA.util.loadScript(OWA.getSetting('baseUrl') + '/modules/base/js/owa.heatmap.js', function () {
      that.overlay = new OWA.heatmap(); //hm.setParams(p);
      //hm.options.demoMode = true;

      that.overlay.options.liveMode = true;
      that.overlay.generate();
    });
  },
  loadPlayer: function loadPlayer() {
    var that = this;
    OWA.debug("Loading Domstream Player");
    OWA.util.loadScript(OWA.getSetting('baseUrl') + '/modules/base/js/includes/jquery/jquery-1.6.4.min.js', function () {});
    OWA.util.loadCss(OWA.getSetting('baseUrl') + '/modules/base/css/owa.overlay.css', function () {});
    OWA.util.loadScript(OWA.getSetting('baseUrl') + '/modules/base/js/owa.player.js', function () {
      that.overlay = new OWA.player();
    });
  },
  startOverlaySession: function startOverlaySession(p) {
    // set global is overlay actve flag
    OWA.overlayActive = true; //alert(JSON.stringify(p));

    if (p.hasOwnProperty('api_url')) {
      OWA.setApiEndpoint(p.api_url);
    } // get param from cookie    
    //var params = OWA.util.parseCookieStringToJson(p);


    var params = p; // evaluate the action param

    if (params.action === 'loadHeatmap') {
      this.loadHeatmap(p);
    } else if (params.action === 'loadPlayer') {
      this.loadPlayer(p);
    }
  },
  endOverlaySession: function endOverlaySession() {
    OWA.util.eraseCookie(OWA.getSetting('ns') + 'overlay', document.domain);
    OWA.overlayActive = false;
  },

  /**
  * Add a new Filter callback
  * Note: filter functions must return the value variable.
  *
  * @param	tag			string	 	The tag that will be called by applyFilters
  * @param	callback	function	The callback function to call
  * @param	priority 	int			Priority of filter to apply.
  * @return	value		mixed		the value to return.	
  */
  addFilter: function addFilter(tag, callback, priority) {
    if ("undefined" === typeof priority) {
      priority = 10;
    } // Make tag if it doesn't already exist


    this.hooks.filters[tag] = this.hooks.filters[tag] || [];
    this.hooks.filters[tag].push({
      priority: priority,
      callback: callback
    });
  },

  /**
  * Add a new Action callback
  *
  * @param	tag			string	 	The tag that will be called by doAction
  * @param	callback	function	The callback function to call
  * @param	priority 	int			Priority of filter to apply.
  */
  addAction: function addAction(tag, callback, priority) {
    OWA.debug('Adding Action callback for: ' + tag);

    if (typeof priority === "undefined") {
      priority = 10;
    } // Make tag if it doesn't already exist


    this.hooks.actions[tag] = this.hooks.actions[tag] || [];
    this.hooks.actions[tag].push({
      priority: priority,
      callback: callback
    });
  },

  /**
   * trigger filter callbacks
   *
   * @param 	tag			string			filter name
   * @param	value		mixed			the value being filtered
   * @param	options		object||array	Optional object to pass to the callbacks
   */
  applyFilters: function applyFilters(tag, value, options) {
    OWA.debug('Filtering ' + tag + ' with value:');
    OWA.debug(value);
    var filters = [];

    if ("undefined" !== typeof this.hooks.filters[tag] && this.hooks.filters[tag].length > 0) {
      OWA.debug('Applying filters for ' + tag);
      this.hooks.filters[tag].forEach(function (hook) {
        filters[hook.priority] = filters[hook.priority] || [];
        filters[hook.priority].push(hook.callback);
      });
      filters.forEach(function (hooks) {
        hooks.forEach(function (callback) {
          value = callback(value, options);
          OWA.debug('Filter returned value: ');
          OWA.debug(value);
        });
      });
    }

    return value;
  },

  /**
   * trigger action callbacks
   *
   * @param 	tag		 string			A registered tag
   * @param	options	 object||array	Optional object to pass to the callbacks
   */
  doAction: function doAction(tag, options) {
    OWA.debug('Doing Action: ' + tag);
    var actions = [];

    if ("undefined" !== typeof this.hooks.actions[tag] && this.hooks.actions[tag].length > 0) {
      OWA.debug(this.hooks.actions[tag]);
      this.hooks.actions[tag].forEach(function (hook) {
        actions[hook.priority] = actions[hook.priority] || [];
        actions[hook.priority].push(hook.callback);
      });
      actions.forEach(function (hooks) {
        OWA.debug('Executing Action callabck for: ' + tag);
        hooks.forEach(function (callback) {
          callback(options);
        });
      });
    }
  },

  /**
   * Remove an Action callback
   *
   * Must be the exact same callback signature.
   * Note: Anonymous functions can not be removed.
   * @param tag		The tag specified by applyFilters
   * @param callback	The callback function to remove
   */
  removeAction: function removeAction(tag, callback) {
    this.hooks.actions[tag] = this.hooks.actions[tag] || [];
    this.hooks.actions[tag].forEach(function (filter, i) {
      if (filter.callback === callback) {
        this.hooks.actions[tag].splice(i, 1);
      }
    });
  },

  /**
   * Remove a Filter callback
   *
   * Must be the exact same callback signature.
   * Note: Anonymous functions can not be removed.
   * @param tag		The tag specified by applyFilters
   * @param callback	The callback function to remove
   */
  removeFilter: function removeFilter(tag, callabck) {
    this.hooks.filters[tag] = this.hooks.filters[tag] || [];
    this.hooks.filters[tag].forEach(function (filter, i) {
      if (filter.callback === callback) {
        this.hooks.filters[tag].splice(i, 1);
      }
    });
  }
};

OWA.stateManager = function () {
  this.cookies = OWA.util.readAllCookies();
  this.init = true;
};

OWA.stateManager.prototype = {
  init: false,
  cookies: '',
  stores: {},
  storeFormats: {},
  storeMeta: {},
  registerStore: function registerStore(name, expiration, length, format) {
    this.storeMeta[name] = {
      'expiration': expiration,
      'length': length,
      'format': format
    };
  },
  getExpirationDays: function getExpirationDays(store_name) {
    if (this.storeMeta.hasOwnProperty(store_name)) {
      return this.storeMeta[store_name].expiration;
    }
  },
  getFormat: function getFormat(store_name) {
    if (this.storeMeta.hasOwnProperty(store_name)) {
      return this.storeMeta[store_name].format;
    }
  },
  isPresent: function isPresent(store_name) {
    if (this.stores.hasOwnProperty(store_name)) {
      return true;
    }
  },
  set: function set(store_name, key, value, is_perminant, format, expiration_days) {
    if (!this.isPresent(store_name)) {
      this.load(store_name);
    }

    if (!this.isPresent(store_name)) {
      OWA.debug('Creating state store (%s)', store_name);
      this.stores[store_name] = {}; // add cookie domain hash

      if (OWA.getSetting('hashCookiesToDomain')) {
        this.stores[store_name].cdh = OWA.util.getCookieDomainHash(OWA.getSetting('cookie_domain'));
      }
    }

    if (key) {
      this.stores[store_name][key] = value;
    } else {
      this.stores[store_name] = value;
    }

    format = this.getFormat(store_name);

    if (!format) {
      // check the orginal format that the state store was loaded from.
      if (this.storeFormats.hasOwnProperty(store_name)) {
        format = this.storeFormats[store_name];
      }
    }

    var state_value = '';

    if (format === 'json') {
      state_value = JSON.stringify(this.stores[store_name]);
    } else {
      state_value = OWA.util.assocStringFromJson(this.stores[store_name]);
    }

    expiration_days = this.getExpirationDays(store_name);

    if (!expiration_days) {
      if (is_perminant) {
        expiration_days = 364;
      }
    } // set or reset the campaign cookie


    OWA.debug('Populating state store (%s) with value: %s', store_name, state_value);
    var domain = OWA.getSetting('cookie_domain') || document.domain; // erase cookie
    //OWA.util.eraseCookie( 'owa_'+store_name, domain );
    // set cookie

    OWA.util.setCookie(OWA.getSetting('ns') + store_name, state_value, expiration_days, '/', domain);
  },
  replaceStore: function replaceStore(store_name, value, is_perminant, format, expiration_days) {
    OWA.debug('replace state format: %s, value: %s', format, JSON.stringify(value));

    if (store_name) {
      if (value) {
        format = this.getFormat(store_name);
        this.stores[store_name] = value;
        this.storeFormats[store_name] = format;

        if (format === 'json') {
          cookie_value = JSON.stringify(value);
        } else {
          cookie_value = OWA.util.assocStringFromJson(value);
        }
      }

      var domain = OWA.getSetting('cookie_domain') || document.domain;
      expiration_days = this.getExpirationDays(store_name);
      OWA.debug('About to replace state store (%s) with: %s', store_name, cookie_value);
      OWA.util.setCookie(OWA.getSetting('ns') + store_name, cookie_value, expiration_days, '/', domain);
    }
  },
  getStateFromCookie: function getStateFromCookie(store_name) {
    var store = unescape(OWA.util.readCookie(OWA.getSetting('ns') + store_name));

    if (store) {
      return store;
    }
  },
  get: function get(store_name, key) {
    if (!this.isPresent(store_name)) {
      this.load(store_name);
    }

    if (this.isPresent(store_name)) {
      if (key) {
        if (this.stores[store_name].hasOwnProperty(key)) {
          return this.stores[store_name][key];
        }
      } else {
        return this.stores[store_name];
      }
    } else {
      OWA.debug('No state store (%s) was found', store_name);
      return '';
    }
  },
  getCookieValues: function getCookieValues(cookie_name) {
    if (this.cookies.hasOwnProperty(cookie_name)) {
      return this.cookies[cookie_name];
    }
  },
  load: function load(store_name) {
    var state = '';
    var cookie_values = this.getCookieValues(OWA.getSetting('ns') + store_name);

    if (cookie_values) {
      for (var i = 0; i < cookie_values.length; i++) {
        var raw_cookie_value = unescape(cookie_values[i]);
        var cookie_value = OWA.util.decodeCookieValue(raw_cookie_value); //OWA.debug(raw_cookie_value);

        var format = OWA.util.getCookieValueFormat(raw_cookie_value);

        if (OWA.getSetting('hashCookiesToDomain')) {
          var domain = OWA.getSetting('cookie_domain');
          var dhash = OWA.util.getCookieDomainHash(domain);

          if (cookie_value.hasOwnProperty('cdh')) {
            OWA.debug('Cookie value cdh: %s, domain hash: %s', cookie_value.cdh, dhash);

            if (cookie_value.cdh == dhash) {
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
          var lastIndex = cookie_values.length - 1;

          if (i === lastIndex) {
            state = cookie_value;
          }
        }
      }
    }

    if (state) {
      this.stores[store_name] = state;
      this.storeFormats[store_name] = format;
      OWA.debug('Loaded state store: %s with: %s', store_name, JSON.stringify(state));
    } else {
      OWA.debug('No state for store: %s was found. Nothing to Load.', store_name);
    }
  },
  clear: function clear(store_name, key) {
    // delete cookie
    if (!key) {
      delete this.stores[store_name];
      OWA.util.eraseCookie(OWA.getSetting('ns') + store_name); //reload cookies

      this.cookies = OWA.util.readAllCookies();
    } else {
      var state = this.get(store_name);

      if (state && state.hasOwnProperty(key)) {
        delete state['key'];
        this.replaceStore(store_name, state, true, this.getFormat(store_name), this.getExpirationDays(store_name));
      }
    }
  },
  getStoreFormat: function getStoreFormat(store_name) {
    return this.getFormat(store_name);
  },
  setStoreFormat: function setStoreFormat(store_name, format) {
    this.storeFormats[store_name] = format;
  }
};

OWA.uri = function (str) {
  this.components = {};
  this.dirty = false;
  this.options = {
    strictMode: false,
    key: ["source", "protocol", "authority", "userInfo", "user", "password", "host", "port", "relative", "path", "directory", "file", "query", "anchor"],
    q: {
      name: "queryKey",
      parser: /(?:^|&)([^&=]*)=?([^&]*)/g
    },
    parser: {
      strict: /^(?:([^:\/?#]+):)?(?:\/\/((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?))?((((?:[^?#\/]*\/)*)([^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
      loose: /^(?:(?![^:@]+:[^:@\/]*@)([^:\/?#.]+):)?(?:\/\/)?((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?)(((\/(?:[^?#](?![^?#\/]*\.[^?#\/.]+(?:[?#]|$)))*\/?)?([^?#\/]*))(?:\?([^#]*))?(?:#(.*))?)/
    }
  };

  if (str) {
    this.components = this.parseUri(str);
  }
};

OWA.uri.prototype = {
  parseUri: function parseUri(str) {
    // parseUri 1.2.2
    // (c) Steven Levithan <stevenlevithan.com>
    // MIT License
    var o = this.options;
    var m = o.parser[o.strictMode ? "strict" : "loose"].exec(str);
    var uri = {};
    var i = 14;

    while (i--) {
      uri[o.key[i]] = m[i] || "";
    }

    uri[o.q.name] = {};
    uri[o.key[12]].replace(o.q.parser, function ($0, $1, $2) {
      if ($1) uri[o.q.name][$1] = $2;
    });
    return uri;
  },
  getHost: function getHost() {
    if (this.components.hasOwnProperty('host')) {
      return this.components.host;
    }
  },
  getQueryParam: function getQueryParam(name) {
    if (this.components.hasOwnProperty('queryKey') && this.components.queryKey.hasOwnProperty(name)) {
      return OWA.util.urldecode(this.components.queryKey[name]);
    }
  },
  isQueryParam: function isQueryParam(name) {
    if (this.components.hasOwnProperty('queryKey') && this.components.queryKey.hasOwnProperty(name)) {
      return true;
    } else {
      return false;
    }
  },
  getComponent: function getComponent(name) {
    if (this.components.hasOwnProperty(name)) {
      return this.components[name];
    }
  },
  getProtocol: function getProtocol() {
    return this.getComponent('protocol');
  },
  getAnchor: function getAnchor() {
    return this.getComponent('anchor');
  },
  getQuery: function getQuery() {
    return this.getComponent('query');
  },
  getFile: function getFile() {
    return this.getComponent('file');
  },
  getRelative: function getRelative() {
    return this.getComponent('relative');
  },
  getDirectory: function getDirectory() {
    return this.getComponent('directory');
  },
  getPath: function getPath() {
    return this.getComponent('path');
  },
  getPort: function getPort() {
    return this.getComponent('port');
  },
  getPassword: function getPassword() {
    return this.getComponent('password');
  },
  getUser: function getUser() {
    return this.getComponent('user');
  },
  getUserInfo: function getUserInfo() {
    return this.getComponent('userInfo');
  },
  getQueryParams: function getQueryParams() {
    return this.getComponent('queryKey');
  },
  getSource: function getSource() {
    return this.getComponent('source');
  },
  setQueryParam: function setQueryParam(name, value) {
    if (!this.components.hasOwnProperty('queryKey')) {
      this.components.queryKey = {};
    }

    this.components.queryKey[name] = OWA.util.urlEncode(value);
    this.resetQuery();
  },
  removeQueryParam: function removeQueryParam(name) {
    if (this.components.hasOwnProperty('queryKey') && this.components.queryKey.hasOwnProperty(name)) {
      delete this.components.queryKey[name];
      this.resetQuery();
    }
  },
  resetSource: function resetSource() {
    this.components.source = this.assembleUrl(); //alert (this.components.source);
  },
  resetQuery: function resetQuery() {
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
  },
  isDirty: function isDirty() {
    return this.dirty;
  },
  setPath: function setPath(path) {},
  assembleUrl: function assembleUrl() {
    var url = ''; // protocol

    url += this.getProtocol();
    url += '://'; // user

    if (this.getUser()) {
      url += this.getUser();
    } // password


    if (this.getUser() && this.getPassword()) {
      url += ':' + this.password();
    } // host


    url += this.getHost(); // port

    if (this.getPort()) {
      url += ':' + this.getPort();
    } // directory


    url += this.getDirectory(); // file

    url += this.getFile(); // query params

    var query = this.getQuery();

    if (query) {
      url += '?' + query;
    } // query params


    var anchor = this.getAnchor();

    if (anchor) {
      url += '#' + anchor;
    } // anchor


    url += this.getAnchor();
    return url;
  }
};
OWA.util = {
  ns: function ns(string) {
    return OWA.config.ns + string;
  },
  nsAll: function nsAll(obj) {
    var nsObj = new Object();

    for (param in obj) {
      // print out the params
      if (obj.hasOwnProperty(param)) {
        nsObj[OWA.config.ns + param] = obj[param];
      }
    }

    return nsObj;
  },
  getScript: function getScript(file, path) {
    jQuery.getScript(path + file);
    return;
  },
  makeUrl: function makeUrl(template, uri, params) {
    var url = jQuery.sprintf(template, uri, jQuery.param(OWA.util.nsAll(params))); //alert(url);

    return url;
  },
  createCookie: function createCookie(name, value, days, domain) {
    if (days) {
      var date = new Date();
      date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
      var expires = "; expires=" + date.toGMTString();
    } else var expires = "";

    document.cookie = name + "=" + value + expires + "; path=/";
  },
  setCookie: function setCookie(name, value, days, path, domain, secure) {
    var date = new Date();
    date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
    document.cookie = name + "=" + escape(value) + (days ? "; expires=" + date.toGMTString() : "") + (path ? "; path=" + path : "") + (domain ? "; domain=" + domain : "") + (secure ? "; secure" : "");
  },
  readAllCookies: function readAllCookies() {
    OWA.debug('Reading all cookies...'); //var dhash = '';

    var jar = {}; //var nameEQ = name + "=";

    var ca = document.cookie.split(';');

    if (ca) {
      OWA.debug(document.cookie);

      for (var i = 0; i < ca.length; i++) {
        var cat = OWA.util.trim(ca[i]);
        var pos = OWA.util.strpos(cat, '=');
        var key = cat.substring(0, pos);
        var value = cat.substring(pos + 1, cat.length); //OWA.debug('key %s, value %s', key, value);
        // create cookie jar array for that key
        // this is needed because you can have multiple cookies with the same name

        if (!jar.hasOwnProperty(key)) {
          jar[key] = [];
        } // add the value to the array


        jar[key].push(value);
      }

      OWA.debug(JSON.stringify(jar));
      return jar;
    }
  },

  /**
   * Reads and returns values from cookies.
   *
   * NOTE: this function returns an array of values as there can be
   * more than one cookie with the same name.
   *
   * @return    array
   */
  readCookie: function readCookie(name) {
    OWA.debug('Attempting to read cookie: %s', name);
    var jar = OWA.util.readAllCookies();

    if (jar) {
      if (jar.hasOwnProperty(name)) {
        return jar[name];
      } else {
        return '';
      }
    }
  },
  eraseCookie: function eraseCookie(name, domain) {
    OWA.debug(document.cookie);

    if (!domain) {
      domain = OWA.getSetting('cookie_domain') || document.domain;
    }

    OWA.debug("erasing cookie: " + name + " in domain: " + domain);
    this.setCookie(name, "", -1, "/", domain); // attempt to read the cookie again to see if its there under another valid domain

    var test = OWA.util.readCookie(name); // if so then try the alternate domain                

    if (test) {
      var period = domain.substr(0, 1);
      OWA.debug('period: ' + period);

      if (period === '.') {
        var domain2 = domain.substr(1);
        OWA.debug("erasing " + name + " in domain2: " + domain2);
        this.setCookie(name, "", -2, "/", domain2);
      } else {
        //    domain = '.'+ domain
        OWA.debug("erasing " + name + " in domain3: " + domain);
        this.setCookie(name, "", -2, "/", domain);
      } //OWA.debug("erasing " + name + " in domain: ");
      //this.setCookie(name,"",-2,"/");    

    }
  },
  eraseMultipleCookies: function eraseMultipleCookies(names, domain) {
    for (var i = 0; i < names.length; i++) {
      this.eraseCookie(names[i], domain);
    }
  },
  loadScript: function loadScript(url, callback) {
    var script = document.createElement("script");
    script.type = "text/javascript";

    if (script.readyState) {
      //IE
      script.onreadystatechange = function () {
        if (script.readyState == "loaded" || script.readyState == "complete") {
          script.onreadystatechange = null;
          callback();
        }
      };
    } else {
      //Others
      script.onload = function () {
        callback && callback();
      };
    }

    script.src = url;
    document.getElementsByTagName("head")[0].appendChild(script);
  },
  loadCss: function loadCss(url, callback) {
    // Create new link Element 
    var link = document.createElement('link'); // set the attributes for link element 

    link.rel = 'stylesheet';
    link.type = 'text/css';
    link.href = url; // Get HTML head element to append  
    // link element to it  

    document.getElementsByTagName('HEAD')[0].appendChild(link);
  },
  parseCookieString: function parseQuery(v) {
    var queryAsAssoc = new Array();
    var queryString = unescape(v);
    var keyValues = queryString.split("|||"); //alert(keyValues);

    for (var i in keyValues) {
      if (keyValues.hasOwnProperty(i)) {
        var key = keyValues[i].split("=>");
        queryAsAssoc[key[0]] = key[1];
      } //alert(key[0] +"="+ key[1]);

    }

    return queryAsAssoc;
  },
  parseCookieStringToJson: function parseQuery(v) {
    var queryAsObj = new Object();
    var queryString = unescape(v);
    var keyValues = queryString.split("|||"); //alert(keyValues);

    for (var i in keyValues) {
      if (keyValues.hasOwnProperty(i)) {
        var key = keyValues[i].split("=>");
        queryAsObj[key[0]] = key[1]; //alert(key[0] +"="+ key[1]);
      }
    } //alert (queryAsObj.period);


    return queryAsObj;
  },
  nsParams: function nsParams(obj) {
    var new_obj = new Object();

    for (param in obj) {
      if (obj.hasOwnProperty(param)) {
        new_obj[OWA.getSetting('ns') + param] = obj[param];
      }
    }

    return new_obj;
  },
  urlEncode: function urlEncode(str) {
    // URL-encodes string  
    // 
    // version: 1009.2513
    // discuss at: http://phpjs.org/functions/urlencode
    // +   original by: Philip Peterson
    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
    // +      input by: AJ
    // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
    // +   improved by: Brett Zamir (http://brett-zamir.me)
    // +   bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
    // +      input by: travc
    // +      input by: Brett Zamir (http://brett-zamir.me)
    // +   bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
    // +   improved by: Lars Fischer
    // +      input by: Ratheous
    // +      reimplemented by: Brett Zamir (http://brett-zamir.me)
    // +   bugfixed by: Joris
    // +      reimplemented by: Brett Zamir (http://brett-zamir.me)
    // %          note 1: This reflects PHP 5.3/6.0+ behavior
    // %        note 2: Please be aware that this function expects to encode into UTF-8 encoded strings, as found on
    // %        note 2: pages served as UTF-8
    // *     example 1: urlencode('Kevin van Zonneveld!');
    // *     returns 1: 'Kevin+van+Zonneveld%21'
    // *     example 2: urlencode('http://kevin.vanzonneveld.net/');
    // *     returns 2: 'http%3A%2F%2Fkevin.vanzonneveld.net%2F'
    // *     example 3: urlencode('http://www.google.nl/search?q=php.js&ie=utf-8&oe=utf-8&aq=t&rls=com.ubuntu:en-US:unofficial&client=firefox-a');
    // *     returns 3: 'http%3A%2F%2Fwww.google.nl%2Fsearch%3Fq%3Dphp.js%26ie%3Dutf-8%26oe%3Dutf-8%26aq%3Dt%26rls%3Dcom.ubuntu%3Aen-US%3Aunofficial%26client%3Dfirefox-a'
    str = (str + '').toString(); // Tilde should be allowed unescaped in future versions of PHP (as reflected below), but if you want to reflect current
    // PHP behavior, you would need to add ".replace(/~/g, '%7E');" to the following.

    return encodeURIComponent(str).replace(/!/g, '%21').replace(/'/g, '%27').replace(/\(/g, '%28').replace(/\)/g, '%29').replace(/\*/g, '%2A').replace(/%20/g, '+');
  },
  urldecode: function urldecode(str) {
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
  parseUrlParams: function parseUrlParams(url) {
    var _GET = {};

    for (var i, a, m, n, o, v, p = location.href.split(/[?&]/), l = p.length, k = 1; k < l; k++) {
      if ((m = p[k].match(/(.*?)(\..*?|\[.*?\])?=([^#]*)/)) && m.length == 4) {
        n = decodeURI(m[1]).toLowerCase(), o = _GET, v = decodeURI(m[3]);
        if (m[2]) for (a = decodeURI(m[2]).replace(/\[\s*\]/g, "[-1]").split(/[\.\[\]]/), i = 0; i < a.length; i++) {
          o = o[n] ? o[n] : o[n] = parseInt(a[i]) == a[i] ? [] : {}, n = a[i].replace(/^["\'](.*)["\']$/, "$1");
        }
        n != '-1' ? o[n] = v : o[o.length] = v;
      }
    }

    return _GET;
  },
  strpos: function strpos(haystack, needle, offset) {
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
    var i = (haystack + '').indexOf(needle, offset || 0);
    return i === -1 ? false : i;
  },
  strCountOccurances: function strCountOccurances(haystack, needle) {
    return haystack.split(needle).length - 1;
  },
  implode: function implode(glue, pieces) {
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
    var i = '',
        retVal = '',
        tGlue = '';

    if (arguments.length === 1) {
      pieces = glue;
      glue = '';
    }

    if (_typeof(pieces) === 'object') {
      if (pieces instanceof Array) {
        return pieces.join(glue);
      } else {
        for (i in pieces) {
          retVal += tGlue + pieces[i];
          tGlue = glue;
        }

        return retVal;
      }
    } else {
      return pieces;
    }
  },
  checkForState: function checkForState(store_name) {
    return OWA.checkForState(store_name);
  },
  setState: function setState(store_name, key, value, is_perminant, format, expiration_days) {
    return OWA.setState(store_name, key, value, is_perminant, format, expiration_days);
  },
  replaceState: function replaceState(store_name, value, is_perminant, format, expiration_days) {
    return OWA.replaceState(store_name, value, is_perminant, format, expiration_days);
  },
  getRawState: function getRawState(store_name) {
    return OWA.getStateFromCookie(store_name);
  },
  getState: function getState(store_name, key) {
    return OWA.getState(store_name, key);
  },
  clearState: function clearState(store_name, key) {
    return OWA.clearState(store_name, key);
  },
  getCookieValueFormat: function getCookieValueFormat(cstring) {
    var format = '';
    var check = cstring.substr(0, 1);

    if (check === '{') {
      format = 'json';
    } else {
      format = 'assoc';
    }

    return format;
  },
  decodeCookieValue: function decodeCookieValue(string) {
    var format = OWA.util.getCookieValueFormat(string);
    var value = ''; //OWA.debug('decodeCookieValue - string: %s, format: %s', string, format);        

    if (format === 'json') {
      value = JSON.parse(string);
    } else {
      value = OWA.util.jsonFromAssocString(string);
    }

    OWA.debug('decodeCookieValue - string: %s, format: %s, value: %s', string, format, JSON.stringify(value));
    return value;
  },
  encodeJsonForCookie: function encodeJsonForCookie(json_obj, format) {
    format = format || 'assoc';

    if (format === 'json') {
      return JSON.stringify(json_obj);
    } else {
      return OWA.util.assocStringFromJson(json_obj);
    }
  },
  getCookieDomainHash: function getCookieDomainHash(domain) {
    // must be string
    return OWA.util.dechex(OWA.util.crc32(domain));
  },
  loadStateJson: function loadStateJson(store_name) {
    var store = unescape(OWA.util.readCookie(OWA.getSetting('ns') + store_name));

    if (store) {
      state = JSON.parse(store);
    }

    OWA.state[store_name] = state;
    OWA.debug('state store %s: %s', store_name, JSON.stringify(state));
  },
  is_array: function is_array(input) {
    return _typeof(input) == 'object' && input instanceof Array;
  },
  // Returns input string padded on the left or right to specified length with pad_string  
  // 
  // version: 1109.2015
  // discuss at: http://phpjs.org/functions/str_pad
  // +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
  // + namespaced by: Michael White (http://getsprink.com)
  // +      input by: Marco van Oort
  // +   bugfixed by: Brett Zamir (http://brett-zamir.me)
  // *     example 1: str_pad('Kevin van Zonneveld', 30, '-=', 'STR_PAD_LEFT');
  // *     returns 1: '-=-=-=-=-=-Kevin van Zonneveld'
  // *     example 2: str_pad('Kevin van Zonneveld', 30, '-', 'STR_PAD_BOTH');
  // *     returns 2: '------Kevin van Zonneveld-----'
  str_pad: function str_pad(input, pad_length, pad_string, pad_type) {
    var half = '',
        pad_to_go;

    var str_pad_repeater = function str_pad_repeater(s, len) {
      var collect = '',
          i;

      while (collect.length < len) {
        collect += s;
      }

      collect = collect.substr(0, len);
      return collect;
    };

    input += '';
    pad_string = pad_string !== undefined ? pad_string : ' ';

    if (pad_type != 'STR_PAD_LEFT' && pad_type != 'STR_PAD_RIGHT' && pad_type != 'STR_PAD_BOTH') {
      pad_type = 'STR_PAD_RIGHT';
    }

    if ((pad_to_go = pad_length - input.length) > 0) {
      if (pad_type == 'STR_PAD_LEFT') {
        input = str_pad_repeater(pad_string, pad_to_go) + input;
      } else if (pad_type == 'STR_PAD_RIGHT') {
        input = input + str_pad_repeater(pad_string, pad_to_go);
      } else if (pad_type == 'STR_PAD_BOTH') {
        half = str_pad_repeater(pad_string, Math.ceil(pad_to_go / 2));
        input = half + input + half;
        input = input.substr(0, pad_length);
      }
    }

    return input;
  },
  zeroFill: function zeroFill(number, length) {
    return OWA.util.str_pad(number, length, '0', 'STR_PAD_LEFT');
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
  is_object: function is_object(mixed_var) {
    if (mixed_var instanceof Array) {
      return false;
    } else {
      return mixed_var !== null && _typeof(mixed_var) == 'object';
    }
  },
  countObjectProperties: function countObjectProperties(obj) {
    var size = 0,
        key;

    for (key in obj) {
      if (obj.hasOwnProperty(key)) size++;
    }

    return size;
  },
  jsonFromAssocString: function jsonFromAssocString(str, inner, outer) {
    inner = inner || '=>';
    outer = outer || '|||';

    if (str) {
      if (!this.strpos(str, inner)) {
        return str;
      } else {
        var assoc = {};
        var outer_array = str.split(outer); //OWA.debug('outer array: %s', JSON.stringify(outer_array));

        for (var i = 0, n = outer_array.length; i < n; i++) {
          var inside_array = outer_array[i].split(inner);
          assoc[inside_array[0]] = inside_array[1];
        }
      } //OWA.debug('jsonFromAssocString: ' + JSON.stringify(assoc));


      return assoc;
    }
  },
  assocStringFromJson: function assocStringFromJson(obj) {
    var string = '';
    var i = 0;
    var count = OWA.util.countObjectProperties(obj);

    for (var prop in obj) {
      i++;
      string += prop + '=>' + obj[prop];

      if (i < count) {
        string += '|||';
      }
    } //OWA.debug('OWA.util.assocStringFromJson: %s', string);


    return string;
  },
  getDomainFromUrl: function getDomainFromUrl(url, strip_www) {
    var domain = url.split(/\/+/g)[1];

    if (strip_www === true) {
      return OWA.util.stripWwwFromDomain(domain);
    } else {
      return domain;
    }
  },
  // strips www. from begining of domain if present
  // otherwise returns the domain as is.
  stripWwwFromDomain: function stripWwwFromDomain(domain) {
    var fp = domain.split('.')[0];

    if (fp === 'www') {
      return domain.substring(4);
    } else {
      return domain;
    }
  },
  getCurrentUnixTimestamp: function getCurrentUnixTimestamp() {
    return Math.round(new Date().getTime() / 1000);
  },
  generateHash: function generateHash(value) {
    return this.crc32(value);
  },
  generateRandomGuid: function generateRandomGuid() {
    var time = this.getCurrentUnixTimestamp() + '';
    var random = OWA.util.zeroFill(this.rand(0, 999999) + '', 6);
    var client = OWA.util.zeroFill(this.rand(0, 999) + '', 3);
    return time + random + client;
  },
  crc32: function crc32(str) {
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
    crc = crc ^ -1;

    for (var i = 0, iTop = str.length; i < iTop; i++) {
      y = (crc ^ str.charCodeAt(i)) & 0xFF;
      x = "0x" + table.substr(y * 9, 8);
      crc = crc >>> 8 ^ x;
    }

    return crc ^ -1;
  },
  utf8_encode: function utf8_encode(argString) {
    // Encodes an ISO-8859-1 string to UTF-8  
    // 
    // version: 1009.2513
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
    var string = argString + ''; // .replace(/\r\n/g, "\n").replace(/\r/g, "\n");

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
        enc = String.fromCharCode(c1 >> 6 | 192) + String.fromCharCode(c1 & 63 | 128);
      } else {
        enc = String.fromCharCode(c1 >> 12 | 224) + String.fromCharCode(c1 >> 6 & 63 | 128) + String.fromCharCode(c1 & 63 | 128);
      }

      if (enc !== null) {
        if (end > start) {
          utftext += string.substring(start, end);
        }

        utftext += enc;
        start = end = n + 1;
      }
    }

    if (end > start) {
      utftext += string.substring(start, string.length);
    }

    return utftext;
  },
  utf8_decode: function utf8_decode(str_data) {
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
    var tmp_arr = [],
        i = 0,
        ac = 0,
        c1 = 0,
        c2 = 0,
        c3 = 0;
    str_data += '';

    while (i < str_data.length) {
      c1 = str_data.charCodeAt(i);

      if (c1 < 128) {
        tmp_arr[ac++] = String.fromCharCode(c1);
        i++;
      } else if (c1 > 191 && c1 < 224) {
        c2 = str_data.charCodeAt(i + 1);
        tmp_arr[ac++] = String.fromCharCode((c1 & 31) << 6 | c2 & 63);
        i += 2;
      } else {
        c2 = str_data.charCodeAt(i + 1);
        c3 = str_data.charCodeAt(i + 2);
        tmp_arr[ac++] = String.fromCharCode((c1 & 15) << 12 | (c2 & 63) << 6 | c3 & 63);
        i += 3;
      }
    }

    return tmp_arr.join('');
  },
  trim: function trim(str, charlist) {
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
    var whitespace,
        l = 0,
        i = 0;
    str += '';

    if (!charlist) {
      // default list
      whitespace = " \n\r\t\f\x0B\xA0\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200A\u200B\u2028\u2029\u3000";
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
  rand: function rand(min, max) {
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
  base64_encode: function base64_encode(data) {
    // Encodes string using MIME base64 algorithm  
    // 
    // version: 1009.2513
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
    var o1,
        o2,
        o3,
        h1,
        h2,
        h3,
        h4,
        bits,
        i = 0,
        ac = 0,
        enc = "",
        tmp_arr = [];

    if (!data) {
      return data;
    }

    data = this.utf8_encode(data + '');

    do {
      // pack three octets into four hexets
      o1 = data.charCodeAt(i++);
      o2 = data.charCodeAt(i++);
      o3 = data.charCodeAt(i++);
      bits = o1 << 16 | o2 << 8 | o3;
      h1 = bits >> 18 & 0x3f;
      h2 = bits >> 12 & 0x3f;
      h3 = bits >> 6 & 0x3f;
      h4 = bits & 0x3f; // use hexets to index into b64, and append result to encoded string

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
  base64_decode: function base64_decode(data) {
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
    var o1,
        o2,
        o3,
        h1,
        h2,
        h3,
        h4,
        bits,
        i = 0,
        ac = 0,
        dec = "",
        tmp_arr = [];

    if (!data) {
      return data;
    }

    data += '';

    do {
      // unpack four hexets into three octets using index points in b64
      h1 = b64.indexOf(data.charAt(i++));
      h2 = b64.indexOf(data.charAt(i++));
      h3 = b64.indexOf(data.charAt(i++));
      h4 = b64.indexOf(data.charAt(i++));
      bits = h1 << 18 | h2 << 12 | h3 << 6 | h4;
      o1 = bits >> 16 & 0xff;
      o2 = bits >> 8 & 0xff;
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
  sprintf: function sprintf() {
    // Return a formatted string  
    // 
    // version: 1009.2513
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
    var a = arguments,
        i = 0,
        format = a[i++]; // pad()

    var pad = function pad(str, len, chr, leftJustify) {
      if (!chr) {
        chr = ' ';
      }

      var padding = str.length >= len ? '' : Array(1 + len - str.length >>> 0).join(chr);
      return leftJustify ? str + padding : padding + str;
    }; // justify()


    var justify = function justify(value, prefix, leftJustify, minWidth, zeroPad, customPadChar) {
      var diff = minWidth - value.length;

      if (diff > 0) {
        if (leftJustify || !zeroPad) {
          value = pad(value, minWidth, customPadChar, leftJustify);
        } else {
          value = value.slice(0, prefix.length) + pad('', diff, '0', true) + value.slice(prefix.length);
        }
      }

      return value;
    }; // formatBaseX()


    var formatBaseX = function formatBaseX(value, base, prefix, leftJustify, minWidth, precision, zeroPad) {
      // Note: casts negative numbers to positive ones
      var number = value >>> 0;
      prefix = prefix && number && {
        '2': '0b',
        '8': '0',
        '16': '0x'
      }[base] || '';
      value = prefix + pad(number.toString(base), precision || 0, '0', false);
      return justify(value, prefix, leftJustify, minWidth, zeroPad);
    }; // formatString()


    var formatString = function formatString(value, leftJustify, minWidth, precision, zeroPad, customPadChar) {
      if (precision != null) {
        value = value.slice(0, precision);
      }

      return justify(value, '', leftJustify, minWidth, zeroPad, customPadChar);
    }; // doFormat()


    var doFormat = function doFormat(substring, valueIndex, flags, minWidth, _, precision, type) {
      var number;
      var prefix;
      var method;
      var textTransform;
      var value;

      if (substring == '%%') {
        return '%';
      } // parse flags


      var leftJustify = false,
          positivePrefix = '',
          zeroPad = false,
          prefixBaseX = false,
          customPadChar = ' ';
      var flagsl = flags.length;

      for (var j = 0; flags && j < flagsl; j++) {
        switch (flags.charAt(j)) {
          case ' ':
            positivePrefix = ' ';
            break;

          case '+':
            positivePrefix = '+';
            break;

          case '-':
            leftJustify = true;
            break;

          case "'":
            customPadChar = flags.charAt(j + 1);
            break;

          case '0':
            zeroPad = true;
            break;

          case '#':
            prefixBaseX = true;
            break;
        }
      } // parameters may be null, undefined, empty-string or real valued
      // we want to ignore null, undefined and empty-string values


      if (!minWidth) {
        minWidth = 0;
      } else if (minWidth == '*') {
        minWidth = +a[i++];
      } else if (minWidth.charAt(0) == '*') {
        minWidth = +a[minWidth.slice(1, -1)];
      } else {
        minWidth = +minWidth;
      } // Note: undocumented perl feature:


      if (minWidth < 0) {
        minWidth = -minWidth;
        leftJustify = true;
      }

      if (!isFinite(minWidth)) {
        throw new Error('sprintf: (minimum-)width must be finite');
      }

      if (!precision) {
        precision = 'fFeE'.indexOf(type) > -1 ? 6 : type == 'd' ? 0 : undefined;
      } else if (precision == '*') {
        precision = +a[i++];
      } else if (precision.charAt(0) == '*') {
        precision = +a[precision.slice(1, -1)];
      } else {
        precision = +precision;
      } // grab value using valueIndex if required?


      value = valueIndex ? a[valueIndex.slice(0, -1)] : a[i++];

      switch (type) {
        case 's':
          return formatString(String(value), leftJustify, minWidth, precision, zeroPad, customPadChar);

        case 'c':
          return formatString(String.fromCharCode(+value), leftJustify, minWidth, precision, zeroPad);

        case 'b':
          return formatBaseX(value, 2, prefixBaseX, leftJustify, minWidth, precision, zeroPad);

        case 'o':
          return formatBaseX(value, 8, prefixBaseX, leftJustify, minWidth, precision, zeroPad);

        case 'x':
          return formatBaseX(value, 16, prefixBaseX, leftJustify, minWidth, precision, zeroPad);

        case 'X':
          return formatBaseX(value, 16, prefixBaseX, leftJustify, minWidth, precision, zeroPad).toUpperCase();

        case 'u':
          return formatBaseX(value, 10, prefixBaseX, leftJustify, minWidth, precision, zeroPad);

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

        default:
          return substring;
      }
    };

    return format.replace(regex, doFormat);
  },
  clone: function clone(mixed) {
    var newObj = mixed instanceof Array ? [] : {};

    for (var i in mixed) {
      if (mixed[i] && _typeof(mixed[i]) == "object") {
        newObj[i] = OWA.util.clone(mixed[i]);
      } else {
        newObj[i] = mixed[i];
      }
    }

    return newObj;
  },
  strtolower: function strtolower(str) {
    return (str + '').toLowerCase();
  },
  in_array: function in_array(needle, haystack, argStrict) {
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
    var key = '',
        strict = !!argStrict;

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
  },
  dechex: function dechex(number) {
    // Returns a string containing a hexadecimal representation of the given number  
    // 
    // version: 1009.2513
    // discuss at: http://phpjs.org/functions/dechex
    // +   original by: Philippe Baumann
    // +   bugfixed by: Onno Marsman
    // +   improved by: http://stackoverflow.com/questions/57803/how-to-convert-decimal-to-hex-in-javascript
    // +   input by: pilus
    // *     example 1: dechex(10);
    // *     returns 1: 'a'
    // *     example 2: dechex(47);
    // *     returns 2: '2f'
    // *     example 3: dechex(-1415723993);
    // *     returns 3: 'ab9dc427'
    if (number < 0) {
      number = 0xFFFFFFFF + number + 1;
    }

    return parseInt(number, 10).toString(16);
  },
  explode: function explode(delimiter, string, limit) {
    // Splits a string on string separator and return array of components. 
    // If limit is positive only limit number of components is returned. 
    // If limit is negative all components except the last abs(limit) are returned.  
    // 
    // version: 1009.2513
    // discuss at: http://phpjs.org/functions/explode
    // +     original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
    // +     improved by: kenneth
    // +     improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
    // +     improved by: d3x
    // +     bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
    // *     example 1: explode(' ', 'Kevin van Zonneveld');
    // *     returns 1: {0: 'Kevin', 1: 'van', 2: 'Zonneveld'}
    // *     example 2: explode('=', 'a=bc=d', 2);
    // *     returns 2: ['a', 'bc=d']
    var emptyArray = {
      0: ''
    }; // third argument is not required

    if (arguments.length < 2 || typeof arguments[0] == 'undefined' || typeof arguments[1] == 'undefined') {
      return null;
    }

    if (delimiter === '' || delimiter === false || delimiter === null) {
      return false;
    }

    if (typeof delimiter == 'function' || _typeof(delimiter) == 'object' || typeof string == 'function' || _typeof(string) == 'object') {
      return emptyArray;
    }

    if (delimiter === true) {
      delimiter = '1';
    }

    if (!limit) {
      return string.toString().split(delimiter.toString());
    } else {
      // support for limit argument
      var splitted = string.toString().split(delimiter.toString());
      var partA = splitted.splice(0, limit - 1);
      var partB = splitted.join(delimiter.toString());
      partA.push(partB);
      return partA;
    }
  },
  isIE: function isIE() {
    if (/MSIE (\d+\.\d+);/.test(navigator.userAgent)) {
      return true;
    }
  },
  getInternetExplorerVersion: function getInternetExplorerVersion() {
    // Returns the version of Internet Explorer or a -1
    // (indicating the use of another browser).
    var rv = -1; // Return value assumes failure.

    if (navigator.appName == 'Microsoft Internet Explorer') {
      var ua = navigator.userAgent;
      var re = new RegExp("MSIE ([0-9]{1,}[\.0-9]{0,})");
      if (re.exec(ua) != null) rv = parseFloat(RegExp.$1);
    }

    return rv;
  },
  isBrowserTrackable: function isBrowserTrackable() {
    var dntProperties = ['doNotTrack', 'msDoNotTrack'];

    for (var i = 0, l = dntProperties.length; i < l; i++) {
      if (navigator[dntProperties[i]] && navigator[dntProperties[i]] == "1") {
        return false;
      }
    }

    return true;
  }
};
window.OWA = OWA;

/***/ }),

/***/ "./modules/base/js/owa.tracker.js":
/*!****************************************!*\
  !*** ./modules/base/js/owa.tracker.js ***!
  \****************************************/
/*! no static exports found */
/***/ (function(module, exports) {

function _typeof2(obj) { "@babel/helpers - typeof"; if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") { _typeof2 = function _typeof2(obj) { return typeof obj; }; } else { _typeof2 = function _typeof2(obj) { return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }; } return _typeof2(obj); }

/******/
(function (modules) {
  // webpackBootstrap

  /******/
  // The module cache

  /******/
  var installedModules = {};
  /******/

  /******/
  // The require function

  /******/

  function __webpack_require__(moduleId) {
    /******/

    /******/
    // Check if module is in cache

    /******/
    if (installedModules[moduleId]) {
      /******/
      return installedModules[moduleId].exports;
      /******/
    }
    /******/
    // Create a new module (and put it into the cache)

    /******/


    var module = installedModules[moduleId] = {
      /******/
      i: moduleId,

      /******/
      l: false,

      /******/
      exports: {}
      /******/

    };
    /******/

    /******/
    // Execute the module function

    /******/

    modules[moduleId].call(module.exports, module, module.exports, __webpack_require__);
    /******/

    /******/
    // Flag the module as loaded

    /******/

    module.l = true;
    /******/

    /******/
    // Return the exports of the module

    /******/

    return module.exports;
    /******/
  }
  /******/

  /******/

  /******/
  // expose the modules object (__webpack_modules__)

  /******/


  __webpack_require__.m = modules;
  /******/

  /******/
  // expose the module cache

  /******/

  __webpack_require__.c = installedModules;
  /******/

  /******/
  // define getter function for harmony exports

  /******/

  __webpack_require__.d = function (exports, name, getter) {
    /******/
    if (!__webpack_require__.o(exports, name)) {
      /******/
      Object.defineProperty(exports, name, {
        enumerable: true,
        get: getter
      });
      /******/
    }
    /******/

  };
  /******/

  /******/
  // define __esModule on exports

  /******/


  __webpack_require__.r = function (exports) {
    /******/
    if (typeof Symbol !== 'undefined' && Symbol.toStringTag) {
      /******/
      Object.defineProperty(exports, Symbol.toStringTag, {
        value: 'Module'
      });
      /******/
    }
    /******/


    Object.defineProperty(exports, '__esModule', {
      value: true
    });
    /******/
  };
  /******/

  /******/
  // create a fake namespace object

  /******/
  // mode & 1: value is a module id, require it

  /******/
  // mode & 2: merge all properties of value into the ns

  /******/
  // mode & 4: return value when already ns object

  /******/
  // mode & 8|1: behave like require

  /******/


  __webpack_require__.t = function (value, mode) {
    /******/
    if (mode & 1) value = __webpack_require__(value);
    /******/

    if (mode & 8) return value;
    /******/

    if (mode & 4 && _typeof2(value) === 'object' && value && value.__esModule) return value;
    /******/

    var ns = Object.create(null);
    /******/

    __webpack_require__.r(ns);
    /******/


    Object.defineProperty(ns, 'default', {
      enumerable: true,
      value: value
    });
    /******/

    if (mode & 2 && typeof value != 'string') for (var key in value) {
      __webpack_require__.d(ns, key, function (key) {
        return value[key];
      }.bind(null, key));
    }
    /******/

    return ns;
    /******/
  };
  /******/

  /******/
  // getDefaultExport function for compatibility with non-harmony modules

  /******/


  __webpack_require__.n = function (module) {
    /******/
    var getter = module && module.__esModule ?
    /******/
    function getDefault() {
      return module['default'];
    } :
    /******/
    function getModuleExports() {
      return module;
    };
    /******/

    __webpack_require__.d(getter, 'a', getter);
    /******/


    return getter;
    /******/
  };
  /******/

  /******/
  // Object.prototype.hasOwnProperty.call

  /******/


  __webpack_require__.o = function (object, property) {
    return Object.prototype.hasOwnProperty.call(object, property);
  };
  /******/

  /******/
  // __webpack_public_path__

  /******/


  __webpack_require__.p = "/";
  /******/

  /******/

  /******/
  // Load entry module and return exports

  /******/

  return __webpack_require__(__webpack_require__.s = 0);
  /******/
})(
/************************************************************************/

/******/
{
  /***/
  "./modules/base/js/owa.js":
  /*!********************************!*\
    !*** ./modules/base/js/owa.js ***!
    \********************************/

  /*! no static exports found */

  /***/
  function modulesBaseJsOwaJs(module, exports) {
    function _typeof(obj) {
      "@babel/helpers - typeof";

      if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") {
        _typeof = function _typeof(obj) {
          return typeof obj;
        };
      } else {
        _typeof = function _typeof(obj) {
          return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj;
        };
      }

      return _typeof(obj);
    }
    /**
     * OWA Global Object 
     *	
     * @author      Peter Adams <peter@openwebanalytics.com>
     * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
     * @license     http://www.openwebanalytics.com/licenses/ BSD-3 Clause
     */


    var OWA = {
      items: {},
      hooks: {
        actions: {},
        filters: {}
      },
      loadedJsLibs: {},
      overlay: '',
      config: {
        ns: 'owa_',
        baseUrl: '',
        hashCookiesToDomain: true,
        debug: false
      },
      state: {},
      overlayActive: false,
      // depricated
      setSetting: function setSetting(name, value) {
        return this.setOption(name, value);
      },
      // depricated
      getSetting: function getSetting(name) {
        return this.getOption(name);
      },
      setOption: function setOption(name, value) {
        this.config[name] = value;
      },
      getOption: function getOption(name) {
        return this.config[name];
      },
      // localize wrapper
      l: function l(string) {
        return string;
      },
      requireJs: function requireJs(name, url, callback) {
        if (!this.isJsLoaded(name)) {
          OWA.util.loadScript(url, callback);
        }

        this.loadedJsLibs[name] = url;
      },
      isJsLoaded: function isJsLoaded(name) {
        if (this.loadedJsLibs.hasOwnProperty(name)) {
          return true;
        }
      },
      initializeStateManager: function initializeStateManager() {
        if (!this.state.hasOwnProperty('init')) {
          OWA.debug('initializing state manager...');
          this.state = new OWA.stateManager();
        }
      },
      registerStateStore: function registerStateStore(name, expiration, length, format) {
        this.initializeStateManager();
        return this.state.registerStore(name, expiration, length, format);
      },
      checkForState: function checkForState(store_name) {
        this.initializeStateManager();
        return this.state.isPresent(store_name);
      },
      setState: function setState(store_name, key, value, is_perminant, format, expiration_days) {
        this.initializeStateManager();
        return this.state.set(store_name, key, value, is_perminant, format, expiration_days);
      },
      replaceState: function replaceState(store_name, value, is_perminant, format, expiration_days) {
        this.initializeStateManager();
        return this.state.replaceStore(store_name, value, is_perminant, format, expiration_days);
      },
      getStateFromCookie: function getStateFromCookie(store_name) {
        this.initializeStateManager();
        return this.state.getStateFromCookie(store_name);
      },
      getState: function getState(store_name, key) {
        this.initializeStateManager();
        return this.state.get(store_name, key);
      },
      clearState: function clearState(store_name, key) {
        this.initializeStateManager();
        return this.state.clear(store_name, key);
      },
      getStateStoreFormat: function getStateStoreFormat(store_name) {
        this.initializeStateManager();
        return this.state.getStoreFormat(store_name);
      },
      setStateStoreFormat: function setStateStoreFormat(store_name, format) {
        this.initializeStateManager();
        return this.state.setStoreFormat(store_name, format);
      },
      debug: function debug() {
        var debugging = OWA.getSetting('debug') || false; // or true

        if (debugging) {
          if (window.console) {
            if (console.log.apply) {
              if (window.console.firebug) {
                console.log.apply(this, arguments);
              } else {
                console.log.apply(console, arguments);
              }
            }
          }
        }
      },
      setApiEndpoint: function setApiEndpoint(endpoint) {
        this.config['rest_api_endpoint'] = endpoint;
      },
      getApiEndpoint: function getApiEndpoint() {
        return this.config['rest_api_endpoint'] || this.getSetting('baseUrl') + 'api/';
      },
      loadHeatmap: function loadHeatmap(p) {
        var that = this;
        OWA.util.loadScript(OWA.getSetting('baseUrl') + '/modules/base/js/includes/jquery/jquery-1.6.4.min.js', function () {});
        OWA.util.loadCss(OWA.getSetting('baseUrl') + '/modules/base/css/owa.overlay.css', function () {});
        OWA.util.loadScript(OWA.getSetting('baseUrl') + '/modules/base/js/owa.heatmap.js', function () {
          that.overlay = new OWA.heatmap(); //hm.setParams(p);
          //hm.options.demoMode = true;

          that.overlay.options.liveMode = true;
          that.overlay.generate();
        });
      },
      loadPlayer: function loadPlayer() {
        var that = this;
        OWA.debug("Loading Domstream Player");
        OWA.util.loadScript(OWA.getSetting('baseUrl') + '/modules/base/js/includes/jquery/jquery-1.6.4.min.js', function () {});
        OWA.util.loadCss(OWA.getSetting('baseUrl') + '/modules/base/css/owa.overlay.css', function () {});
        OWA.util.loadScript(OWA.getSetting('baseUrl') + '/modules/base/js/owa.player.js', function () {
          that.overlay = new OWA.player();
        });
      },
      startOverlaySession: function startOverlaySession(p) {
        // set global is overlay actve flag
        OWA.overlayActive = true; //alert(JSON.stringify(p));

        if (p.hasOwnProperty('api_url')) {
          OWA.setApiEndpoint(p.api_url);
        } // get param from cookie    
        //var params = OWA.util.parseCookieStringToJson(p);


        var params = p; // evaluate the action param

        if (params.action === 'loadHeatmap') {
          this.loadHeatmap(p);
        } else if (params.action === 'loadPlayer') {
          this.loadPlayer(p);
        }
      },
      endOverlaySession: function endOverlaySession() {
        OWA.util.eraseCookie(OWA.getSetting('ns') + 'overlay', document.domain);
        OWA.overlayActive = false;
      },

      /**
      * Add a new Filter callback
      * Note: filter functions must return the value variable.
      *
      * @param	tag			string	 	The tag that will be called by applyFilters
      * @param	callback	function	The callback function to call
      * @param	priority 	int			Priority of filter to apply.
      * @return	value		mixed		the value to return.	
      */
      addFilter: function addFilter(tag, callback, priority) {
        if ("undefined" === typeof priority) {
          priority = 10;
        } // Make tag if it doesn't already exist


        this.hooks.filters[tag] = this.hooks.filters[tag] || [];
        this.hooks.filters[tag].push({
          priority: priority,
          callback: callback
        });
      },

      /**
      * Add a new Action callback
      *
      * @param	tag			string	 	The tag that will be called by doAction
      * @param	callback	function	The callback function to call
      * @param	priority 	int			Priority of filter to apply.
      */
      addAction: function addAction(tag, callback, priority) {
        OWA.debug('Adding Action callback for: ' + tag);

        if (typeof priority === "undefined") {
          priority = 10;
        } // Make tag if it doesn't already exist


        this.hooks.actions[tag] = this.hooks.actions[tag] || [];
        this.hooks.actions[tag].push({
          priority: priority,
          callback: callback
        });
      },

      /**
       * trigger filter callbacks
       *
       * @param 	tag			string			filter name
       * @param	value		mixed			the value being filtered
       * @param	options		object||array	Optional object to pass to the callbacks
       */
      applyFilters: function applyFilters(tag, value, options) {
        OWA.debug('Filtering ' + tag + ' with value:');
        OWA.debug(value);
        var filters = [];

        if ("undefined" !== typeof this.hooks.filters[tag] && this.hooks.filters[tag].length > 0) {
          OWA.debug('Applying filters for ' + tag);
          this.hooks.filters[tag].forEach(function (hook) {
            filters[hook.priority] = filters[hook.priority] || [];
            filters[hook.priority].push(hook.callback);
          });
          filters.forEach(function (hooks) {
            hooks.forEach(function (callback) {
              value = callback(value, options);
              OWA.debug('Filter returned value: ');
              OWA.debug(value);
            });
          });
        }

        return value;
      },

      /**
       * trigger action callbacks
       *
       * @param 	tag		 string			A registered tag
       * @param	options	 object||array	Optional object to pass to the callbacks
       */
      doAction: function doAction(tag, options) {
        OWA.debug('Doing Action: ' + tag);
        var actions = [];

        if ("undefined" !== typeof this.hooks.actions[tag] && this.hooks.actions[tag].length > 0) {
          OWA.debug(this.hooks.actions[tag]);
          this.hooks.actions[tag].forEach(function (hook) {
            actions[hook.priority] = actions[hook.priority] || [];
            actions[hook.priority].push(hook.callback);
          });
          actions.forEach(function (hooks) {
            OWA.debug('Executing Action callabck for: ' + tag);
            hooks.forEach(function (callback) {
              callback(options);
            });
          });
        }
      },

      /**
       * Remove an Action callback
       *
       * Must be the exact same callback signature.
       * Note: Anonymous functions can not be removed.
       * @param tag		The tag specified by applyFilters
       * @param callback	The callback function to remove
       */
      removeAction: function removeAction(tag, callback) {
        this.hooks.actions[tag] = this.hooks.actions[tag] || [];
        this.hooks.actions[tag].forEach(function (filter, i) {
          if (filter.callback === callback) {
            this.hooks.actions[tag].splice(i, 1);
          }
        });
      },

      /**
       * Remove a Filter callback
       *
       * Must be the exact same callback signature.
       * Note: Anonymous functions can not be removed.
       * @param tag		The tag specified by applyFilters
       * @param callback	The callback function to remove
       */
      removeFilter: function removeFilter(tag, callabck) {
        this.hooks.filters[tag] = this.hooks.filters[tag] || [];
        this.hooks.filters[tag].forEach(function (filter, i) {
          if (filter.callback === callback) {
            this.hooks.filters[tag].splice(i, 1);
          }
        });
      }
    };

    OWA.stateManager = function () {
      this.cookies = OWA.util.readAllCookies();
      this.init = true;
    };

    OWA.stateManager.prototype = {
      init: false,
      cookies: '',
      stores: {},
      storeFormats: {},
      storeMeta: {},
      registerStore: function registerStore(name, expiration, length, format) {
        this.storeMeta[name] = {
          'expiration': expiration,
          'length': length,
          'format': format
        };
      },
      getExpirationDays: function getExpirationDays(store_name) {
        if (this.storeMeta.hasOwnProperty(store_name)) {
          return this.storeMeta[store_name].expiration;
        }
      },
      getFormat: function getFormat(store_name) {
        if (this.storeMeta.hasOwnProperty(store_name)) {
          return this.storeMeta[store_name].format;
        }
      },
      isPresent: function isPresent(store_name) {
        if (this.stores.hasOwnProperty(store_name)) {
          return true;
        }
      },
      set: function set(store_name, key, value, is_perminant, format, expiration_days) {
        if (!this.isPresent(store_name)) {
          this.load(store_name);
        }

        if (!this.isPresent(store_name)) {
          OWA.debug('Creating state store (%s)', store_name);
          this.stores[store_name] = {}; // add cookie domain hash

          if (OWA.getSetting('hashCookiesToDomain')) {
            this.stores[store_name].cdh = OWA.util.getCookieDomainHash(OWA.getSetting('cookie_domain'));
          }
        }

        if (key) {
          this.stores[store_name][key] = value;
        } else {
          this.stores[store_name] = value;
        }

        format = this.getFormat(store_name);

        if (!format) {
          // check the orginal format that the state store was loaded from.
          if (this.storeFormats.hasOwnProperty(store_name)) {
            format = this.storeFormats[store_name];
          }
        }

        var state_value = '';

        if (format === 'json') {
          state_value = JSON.stringify(this.stores[store_name]);
        } else {
          state_value = OWA.util.assocStringFromJson(this.stores[store_name]);
        }

        expiration_days = this.getExpirationDays(store_name);

        if (!expiration_days) {
          if (is_perminant) {
            expiration_days = 364;
          }
        } // set or reset the campaign cookie


        OWA.debug('Populating state store (%s) with value: %s', store_name, state_value);
        var domain = OWA.getSetting('cookie_domain') || document.domain; // erase cookie
        //OWA.util.eraseCookie( 'owa_'+store_name, domain );
        // set cookie

        OWA.util.setCookie(OWA.getSetting('ns') + store_name, state_value, expiration_days, '/', domain);
      },
      replaceStore: function replaceStore(store_name, value, is_perminant, format, expiration_days) {
        OWA.debug('replace state format: %s, value: %s', format, JSON.stringify(value));

        if (store_name) {
          if (value) {
            format = this.getFormat(store_name);
            this.stores[store_name] = value;
            this.storeFormats[store_name] = format;

            if (format === 'json') {
              cookie_value = JSON.stringify(value);
            } else {
              cookie_value = OWA.util.assocStringFromJson(value);
            }
          }

          var domain = OWA.getSetting('cookie_domain') || document.domain;
          expiration_days = this.getExpirationDays(store_name);
          OWA.debug('About to replace state store (%s) with: %s', store_name, cookie_value);
          OWA.util.setCookie(OWA.getSetting('ns') + store_name, cookie_value, expiration_days, '/', domain);
        }
      },
      getStateFromCookie: function getStateFromCookie(store_name) {
        var store = unescape(OWA.util.readCookie(OWA.getSetting('ns') + store_name));

        if (store) {
          return store;
        }
      },
      get: function get(store_name, key) {
        if (!this.isPresent(store_name)) {
          this.load(store_name);
        }

        if (this.isPresent(store_name)) {
          if (key) {
            if (this.stores[store_name].hasOwnProperty(key)) {
              return this.stores[store_name][key];
            }
          } else {
            return this.stores[store_name];
          }
        } else {
          OWA.debug('No state store (%s) was found', store_name);
          return '';
        }
      },
      getCookieValues: function getCookieValues(cookie_name) {
        if (this.cookies.hasOwnProperty(cookie_name)) {
          return this.cookies[cookie_name];
        }
      },
      load: function load(store_name) {
        var state = '';
        var cookie_values = this.getCookieValues(OWA.getSetting('ns') + store_name);

        if (cookie_values) {
          for (var i = 0; i < cookie_values.length; i++) {
            var raw_cookie_value = unescape(cookie_values[i]);
            var cookie_value = OWA.util.decodeCookieValue(raw_cookie_value); //OWA.debug(raw_cookie_value);

            var format = OWA.util.getCookieValueFormat(raw_cookie_value);

            if (OWA.getSetting('hashCookiesToDomain')) {
              var domain = OWA.getSetting('cookie_domain');
              var dhash = OWA.util.getCookieDomainHash(domain);

              if (cookie_value.hasOwnProperty('cdh')) {
                OWA.debug('Cookie value cdh: %s, domain hash: %s', cookie_value.cdh, dhash);

                if (cookie_value.cdh == dhash) {
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
              var lastIndex = cookie_values.length - 1;

              if (i === lastIndex) {
                state = cookie_value;
              }
            }
          }
        }

        if (state) {
          this.stores[store_name] = state;
          this.storeFormats[store_name] = format;
          OWA.debug('Loaded state store: %s with: %s', store_name, JSON.stringify(state));
        } else {
          OWA.debug('No state for store: %s was found. Nothing to Load.', store_name);
        }
      },
      clear: function clear(store_name, key) {
        // delete cookie
        if (!key) {
          delete this.stores[store_name];
          OWA.util.eraseCookie(OWA.getSetting('ns') + store_name); //reload cookies

          this.cookies = OWA.util.readAllCookies();
        } else {
          var state = this.get(store_name);

          if (state && state.hasOwnProperty(key)) {
            delete state['key'];
            this.replaceStore(store_name, state, true, this.getFormat(store_name), this.getExpirationDays(store_name));
          }
        }
      },
      getStoreFormat: function getStoreFormat(store_name) {
        return this.getFormat(store_name);
      },
      setStoreFormat: function setStoreFormat(store_name, format) {
        this.storeFormats[store_name] = format;
      }
    };

    OWA.uri = function (str) {
      this.components = {};
      this.dirty = false;
      this.options = {
        strictMode: false,
        key: ["source", "protocol", "authority", "userInfo", "user", "password", "host", "port", "relative", "path", "directory", "file", "query", "anchor"],
        q: {
          name: "queryKey",
          parser: /(?:^|&)([^&=]*)=?([^&]*)/g
        },
        parser: {
          strict: /^(?:([^:\/?#]+):)?(?:\/\/((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?))?((((?:[^?#\/]*\/)*)([^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
          loose: /^(?:(?![^:@]+:[^:@\/]*@)([^:\/?#.]+):)?(?:\/\/)?((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?)(((\/(?:[^?#](?![^?#\/]*\.[^?#\/.]+(?:[?#]|$)))*\/?)?([^?#\/]*))(?:\?([^#]*))?(?:#(.*))?)/
        }
      };

      if (str) {
        this.components = this.parseUri(str);
      }
    };

    OWA.uri.prototype = {
      parseUri: function parseUri(str) {
        // parseUri 1.2.2
        // (c) Steven Levithan <stevenlevithan.com>
        // MIT License
        var o = this.options;
        var m = o.parser[o.strictMode ? "strict" : "loose"].exec(str);
        var uri = {};
        var i = 14;

        while (i--) {
          uri[o.key[i]] = m[i] || "";
        }

        uri[o.q.name] = {};
        uri[o.key[12]].replace(o.q.parser, function ($0, $1, $2) {
          if ($1) uri[o.q.name][$1] = $2;
        });
        return uri;
      },
      getHost: function getHost() {
        if (this.components.hasOwnProperty('host')) {
          return this.components.host;
        }
      },
      getQueryParam: function getQueryParam(name) {
        if (this.components.hasOwnProperty('queryKey') && this.components.queryKey.hasOwnProperty(name)) {
          return OWA.util.urldecode(this.components.queryKey[name]);
        }
      },
      isQueryParam: function isQueryParam(name) {
        if (this.components.hasOwnProperty('queryKey') && this.components.queryKey.hasOwnProperty(name)) {
          return true;
        } else {
          return false;
        }
      },
      getComponent: function getComponent(name) {
        if (this.components.hasOwnProperty(name)) {
          return this.components[name];
        }
      },
      getProtocol: function getProtocol() {
        return this.getComponent('protocol');
      },
      getAnchor: function getAnchor() {
        return this.getComponent('anchor');
      },
      getQuery: function getQuery() {
        return this.getComponent('query');
      },
      getFile: function getFile() {
        return this.getComponent('file');
      },
      getRelative: function getRelative() {
        return this.getComponent('relative');
      },
      getDirectory: function getDirectory() {
        return this.getComponent('directory');
      },
      getPath: function getPath() {
        return this.getComponent('path');
      },
      getPort: function getPort() {
        return this.getComponent('port');
      },
      getPassword: function getPassword() {
        return this.getComponent('password');
      },
      getUser: function getUser() {
        return this.getComponent('user');
      },
      getUserInfo: function getUserInfo() {
        return this.getComponent('userInfo');
      },
      getQueryParams: function getQueryParams() {
        return this.getComponent('queryKey');
      },
      getSource: function getSource() {
        return this.getComponent('source');
      },
      setQueryParam: function setQueryParam(name, value) {
        if (!this.components.hasOwnProperty('queryKey')) {
          this.components.queryKey = {};
        }

        this.components.queryKey[name] = OWA.util.urlEncode(value);
        this.resetQuery();
      },
      removeQueryParam: function removeQueryParam(name) {
        if (this.components.hasOwnProperty('queryKey') && this.components.queryKey.hasOwnProperty(name)) {
          delete this.components.queryKey[name];
          this.resetQuery();
        }
      },
      resetSource: function resetSource() {
        this.components.source = this.assembleUrl(); //alert (this.components.source);
      },
      resetQuery: function resetQuery() {
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
      },
      isDirty: function isDirty() {
        return this.dirty;
      },
      setPath: function setPath(path) {},
      assembleUrl: function assembleUrl() {
        var url = ''; // protocol

        url += this.getProtocol();
        url += '://'; // user

        if (this.getUser()) {
          url += this.getUser();
        } // password


        if (this.getUser() && this.getPassword()) {
          url += ':' + this.password();
        } // host


        url += this.getHost(); // port

        if (this.getPort()) {
          url += ':' + this.getPort();
        } // directory


        url += this.getDirectory(); // file

        url += this.getFile(); // query params

        var query = this.getQuery();

        if (query) {
          url += '?' + query;
        } // query params


        var anchor = this.getAnchor();

        if (anchor) {
          url += '#' + anchor;
        } // anchor


        url += this.getAnchor();
        return url;
      }
    };
    OWA.util = {
      ns: function ns(string) {
        return OWA.config.ns + string;
      },
      nsAll: function nsAll(obj) {
        var nsObj = new Object();

        for (param in obj) {
          // print out the params
          if (obj.hasOwnProperty(param)) {
            nsObj[OWA.config.ns + param] = obj[param];
          }
        }

        return nsObj;
      },
      getScript: function getScript(file, path) {
        jQuery.getScript(path + file);
        return;
      },
      makeUrl: function makeUrl(template, uri, params) {
        var url = jQuery.sprintf(template, uri, jQuery.param(OWA.util.nsAll(params))); //alert(url);

        return url;
      },
      createCookie: function createCookie(name, value, days, domain) {
        if (days) {
          var date = new Date();
          date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
          var expires = "; expires=" + date.toGMTString();
        } else var expires = "";

        document.cookie = name + "=" + value + expires + "; path=/";
      },
      setCookie: function setCookie(name, value, days, path, domain, secure) {
        var date = new Date();
        date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
        document.cookie = name + "=" + escape(value) + (days ? "; expires=" + date.toGMTString() : "") + (path ? "; path=" + path : "") + (domain ? "; domain=" + domain : "") + (secure ? "; secure" : "");
      },
      readAllCookies: function readAllCookies() {
        OWA.debug('Reading all cookies...'); //var dhash = '';

        var jar = {}; //var nameEQ = name + "=";

        var ca = document.cookie.split(';');

        if (ca) {
          OWA.debug(document.cookie);

          for (var i = 0; i < ca.length; i++) {
            var cat = OWA.util.trim(ca[i]);
            var pos = OWA.util.strpos(cat, '=');
            var key = cat.substring(0, pos);
            var value = cat.substring(pos + 1, cat.length); //OWA.debug('key %s, value %s', key, value);
            // create cookie jar array for that key
            // this is needed because you can have multiple cookies with the same name

            if (!jar.hasOwnProperty(key)) {
              jar[key] = [];
            } // add the value to the array


            jar[key].push(value);
          }

          OWA.debug(JSON.stringify(jar));
          return jar;
        }
      },

      /**
       * Reads and returns values from cookies.
       *
       * NOTE: this function returns an array of values as there can be
       * more than one cookie with the same name.
       *
       * @return    array
       */
      readCookie: function readCookie(name) {
        OWA.debug('Attempting to read cookie: %s', name);
        var jar = OWA.util.readAllCookies();

        if (jar) {
          if (jar.hasOwnProperty(name)) {
            return jar[name];
          } else {
            return '';
          }
        }
      },
      eraseCookie: function eraseCookie(name, domain) {
        OWA.debug(document.cookie);

        if (!domain) {
          domain = OWA.getSetting('cookie_domain') || document.domain;
        }

        OWA.debug("erasing cookie: " + name + " in domain: " + domain);
        this.setCookie(name, "", -1, "/", domain); // attempt to read the cookie again to see if its there under another valid domain

        var test = OWA.util.readCookie(name); // if so then try the alternate domain                

        if (test) {
          var period = domain.substr(0, 1);
          OWA.debug('period: ' + period);

          if (period === '.') {
            var domain2 = domain.substr(1);
            OWA.debug("erasing " + name + " in domain2: " + domain2);
            this.setCookie(name, "", -2, "/", domain2);
          } else {
            //    domain = '.'+ domain
            OWA.debug("erasing " + name + " in domain3: " + domain);
            this.setCookie(name, "", -2, "/", domain);
          } //OWA.debug("erasing " + name + " in domain: ");
          //this.setCookie(name,"",-2,"/");    

        }
      },
      eraseMultipleCookies: function eraseMultipleCookies(names, domain) {
        for (var i = 0; i < names.length; i++) {
          this.eraseCookie(names[i], domain);
        }
      },
      loadScript: function loadScript(url, callback) {
        var script = document.createElement("script");
        script.type = "text/javascript";

        if (script.readyState) {
          //IE
          script.onreadystatechange = function () {
            if (script.readyState == "loaded" || script.readyState == "complete") {
              script.onreadystatechange = null;
              callback();
            }
          };
        } else {
          //Others
          script.onload = function () {
            callback();
          };
        }

        script.src = url;
        document.getElementsByTagName("head")[0].appendChild(script);
      },
      loadCss: function loadCss(url, callback) {
        // Create new link Element 
        var link = document.createElement('link'); // set the attributes for link element 

        link.rel = 'stylesheet';
        link.type = 'text/css';
        link.href = url; // Get HTML head element to append  
        // link element to it  

        document.getElementsByTagName('HEAD')[0].appendChild(link);
      },
      parseCookieString: function parseQuery(v) {
        var queryAsAssoc = new Array();
        var queryString = unescape(v);
        var keyValues = queryString.split("|||"); //alert(keyValues);

        for (var i in keyValues) {
          if (keyValues.hasOwnProperty(i)) {
            var key = keyValues[i].split("=>");
            queryAsAssoc[key[0]] = key[1];
          } //alert(key[0] +"="+ key[1]);

        }

        return queryAsAssoc;
      },
      parseCookieStringToJson: function parseQuery(v) {
        var queryAsObj = new Object();
        var queryString = unescape(v);
        var keyValues = queryString.split("|||"); //alert(keyValues);

        for (var i in keyValues) {
          if (keyValues.hasOwnProperty(i)) {
            var key = keyValues[i].split("=>");
            queryAsObj[key[0]] = key[1]; //alert(key[0] +"="+ key[1]);
          }
        } //alert (queryAsObj.period);


        return queryAsObj;
      },
      nsParams: function nsParams(obj) {
        var new_obj = new Object();

        for (param in obj) {
          if (obj.hasOwnProperty(param)) {
            new_obj[OWA.getSetting('ns') + param] = obj[param];
          }
        }

        return new_obj;
      },
      urlEncode: function urlEncode(str) {
        // URL-encodes string  
        // 
        // version: 1009.2513
        // discuss at: http://phpjs.org/functions/urlencode
        // +   original by: Philip Peterson
        // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +      input by: AJ
        // +   improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +   improved by: Brett Zamir (http://brett-zamir.me)
        // +   bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +      input by: travc
        // +      input by: Brett Zamir (http://brett-zamir.me)
        // +   bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +   improved by: Lars Fischer
        // +      input by: Ratheous
        // +      reimplemented by: Brett Zamir (http://brett-zamir.me)
        // +   bugfixed by: Joris
        // +      reimplemented by: Brett Zamir (http://brett-zamir.me)
        // %          note 1: This reflects PHP 5.3/6.0+ behavior
        // %        note 2: Please be aware that this function expects to encode into UTF-8 encoded strings, as found on
        // %        note 2: pages served as UTF-8
        // *     example 1: urlencode('Kevin van Zonneveld!');
        // *     returns 1: 'Kevin+van+Zonneveld%21'
        // *     example 2: urlencode('http://kevin.vanzonneveld.net/');
        // *     returns 2: 'http%3A%2F%2Fkevin.vanzonneveld.net%2F'
        // *     example 3: urlencode('http://www.google.nl/search?q=php.js&ie=utf-8&oe=utf-8&aq=t&rls=com.ubuntu:en-US:unofficial&client=firefox-a');
        // *     returns 3: 'http%3A%2F%2Fwww.google.nl%2Fsearch%3Fq%3Dphp.js%26ie%3Dutf-8%26oe%3Dutf-8%26aq%3Dt%26rls%3Dcom.ubuntu%3Aen-US%3Aunofficial%26client%3Dfirefox-a'
        str = (str + '').toString(); // Tilde should be allowed unescaped in future versions of PHP (as reflected below), but if you want to reflect current
        // PHP behavior, you would need to add ".replace(/~/g, '%7E');" to the following.

        return encodeURIComponent(str).replace(/!/g, '%21').replace(/'/g, '%27').replace(/\(/g, '%28').replace(/\)/g, '%29').replace(/\*/g, '%2A').replace(/%20/g, '+');
      },
      urldecode: function urldecode(str) {
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
      parseUrlParams: function parseUrlParams(url) {
        var _GET = {};

        for (var i, a, m, n, o, v, p = location.href.split(/[?&]/), l = p.length, k = 1; k < l; k++) {
          if ((m = p[k].match(/(.*?)(\..*?|\[.*?\])?=([^#]*)/)) && m.length == 4) {
            n = decodeURI(m[1]).toLowerCase(), o = _GET, v = decodeURI(m[3]);
            if (m[2]) for (a = decodeURI(m[2]).replace(/\[\s*\]/g, "[-1]").split(/[\.\[\]]/), i = 0; i < a.length; i++) {
              o = o[n] ? o[n] : o[n] = parseInt(a[i]) == a[i] ? [] : {}, n = a[i].replace(/^["\'](.*)["\']$/, "$1");
            }
            n != '-1' ? o[n] = v : o[o.length] = v;
          }
        }

        return _GET;
      },
      strpos: function strpos(haystack, needle, offset) {
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
        var i = (haystack + '').indexOf(needle, offset || 0);
        return i === -1 ? false : i;
      },
      strCountOccurances: function strCountOccurances(haystack, needle) {
        return haystack.split(needle).length - 1;
      },
      implode: function implode(glue, pieces) {
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
        var i = '',
            retVal = '',
            tGlue = '';

        if (arguments.length === 1) {
          pieces = glue;
          glue = '';
        }

        if (_typeof(pieces) === 'object') {
          if (pieces instanceof Array) {
            return pieces.join(glue);
          } else {
            for (i in pieces) {
              retVal += tGlue + pieces[i];
              tGlue = glue;
            }

            return retVal;
          }
        } else {
          return pieces;
        }
      },
      checkForState: function checkForState(store_name) {
        return OWA.checkForState(store_name);
      },
      setState: function setState(store_name, key, value, is_perminant, format, expiration_days) {
        return OWA.setState(store_name, key, value, is_perminant, format, expiration_days);
      },
      replaceState: function replaceState(store_name, value, is_perminant, format, expiration_days) {
        return OWA.replaceState(store_name, value, is_perminant, format, expiration_days);
      },
      getRawState: function getRawState(store_name) {
        return OWA.getStateFromCookie(store_name);
      },
      getState: function getState(store_name, key) {
        return OWA.getState(store_name, key);
      },
      clearState: function clearState(store_name, key) {
        return OWA.clearState(store_name, key);
      },
      getCookieValueFormat: function getCookieValueFormat(cstring) {
        var format = '';
        var check = cstring.substr(0, 1);

        if (check === '{') {
          format = 'json';
        } else {
          format = 'assoc';
        }

        return format;
      },
      decodeCookieValue: function decodeCookieValue(string) {
        var format = OWA.util.getCookieValueFormat(string);
        var value = ''; //OWA.debug('decodeCookieValue - string: %s, format: %s', string, format);        

        if (format === 'json') {
          value = JSON.parse(string);
        } else {
          value = OWA.util.jsonFromAssocString(string);
        }

        OWA.debug('decodeCookieValue - string: %s, format: %s, value: %s', string, format, JSON.stringify(value));
        return value;
      },
      encodeJsonForCookie: function encodeJsonForCookie(json_obj, format) {
        format = format || 'assoc';

        if (format === 'json') {
          return JSON.stringify(json_obj);
        } else {
          return OWA.util.assocStringFromJson(json_obj);
        }
      },
      getCookieDomainHash: function getCookieDomainHash(domain) {
        // must be string
        return OWA.util.dechex(OWA.util.crc32(domain));
      },
      loadStateJson: function loadStateJson(store_name) {
        var store = unescape(OWA.util.readCookie(OWA.getSetting('ns') + store_name));

        if (store) {
          state = JSON.parse(store);
        }

        OWA.state[store_name] = state;
        OWA.debug('state store %s: %s', store_name, JSON.stringify(state));
      },
      is_array: function is_array(input) {
        return _typeof(input) == 'object' && input instanceof Array;
      },
      // Returns input string padded on the left or right to specified length with pad_string  
      // 
      // version: 1109.2015
      // discuss at: http://phpjs.org/functions/str_pad
      // +   original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
      // + namespaced by: Michael White (http://getsprink.com)
      // +      input by: Marco van Oort
      // +   bugfixed by: Brett Zamir (http://brett-zamir.me)
      // *     example 1: str_pad('Kevin van Zonneveld', 30, '-=', 'STR_PAD_LEFT');
      // *     returns 1: '-=-=-=-=-=-Kevin van Zonneveld'
      // *     example 2: str_pad('Kevin van Zonneveld', 30, '-', 'STR_PAD_BOTH');
      // *     returns 2: '------Kevin van Zonneveld-----'
      str_pad: function str_pad(input, pad_length, pad_string, pad_type) {
        var half = '',
            pad_to_go;

        var str_pad_repeater = function str_pad_repeater(s, len) {
          var collect = '',
              i;

          while (collect.length < len) {
            collect += s;
          }

          collect = collect.substr(0, len);
          return collect;
        };

        input += '';
        pad_string = pad_string !== undefined ? pad_string : ' ';

        if (pad_type != 'STR_PAD_LEFT' && pad_type != 'STR_PAD_RIGHT' && pad_type != 'STR_PAD_BOTH') {
          pad_type = 'STR_PAD_RIGHT';
        }

        if ((pad_to_go = pad_length - input.length) > 0) {
          if (pad_type == 'STR_PAD_LEFT') {
            input = str_pad_repeater(pad_string, pad_to_go) + input;
          } else if (pad_type == 'STR_PAD_RIGHT') {
            input = input + str_pad_repeater(pad_string, pad_to_go);
          } else if (pad_type == 'STR_PAD_BOTH') {
            half = str_pad_repeater(pad_string, Math.ceil(pad_to_go / 2));
            input = half + input + half;
            input = input.substr(0, pad_length);
          }
        }

        return input;
      },
      zeroFill: function zeroFill(number, length) {
        return OWA.util.str_pad(number, length, '0', 'STR_PAD_LEFT');
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
      is_object: function is_object(mixed_var) {
        if (mixed_var instanceof Array) {
          return false;
        } else {
          return mixed_var !== null && _typeof(mixed_var) == 'object';
        }
      },
      countObjectProperties: function countObjectProperties(obj) {
        var size = 0,
            key;

        for (key in obj) {
          if (obj.hasOwnProperty(key)) size++;
        }

        return size;
      },
      jsonFromAssocString: function jsonFromAssocString(str, inner, outer) {
        inner = inner || '=>';
        outer = outer || '|||';

        if (str) {
          if (!this.strpos(str, inner)) {
            return str;
          } else {
            var assoc = {};
            var outer_array = str.split(outer); //OWA.debug('outer array: %s', JSON.stringify(outer_array));

            for (var i = 0, n = outer_array.length; i < n; i++) {
              var inside_array = outer_array[i].split(inner);
              assoc[inside_array[0]] = inside_array[1];
            }
          } //OWA.debug('jsonFromAssocString: ' + JSON.stringify(assoc));


          return assoc;
        }
      },
      assocStringFromJson: function assocStringFromJson(obj) {
        var string = '';
        var i = 0;
        var count = OWA.util.countObjectProperties(obj);

        for (var prop in obj) {
          i++;
          string += prop + '=>' + obj[prop];

          if (i < count) {
            string += '|||';
          }
        } //OWA.debug('OWA.util.assocStringFromJson: %s', string);


        return string;
      },
      getDomainFromUrl: function getDomainFromUrl(url, strip_www) {
        var domain = url.split(/\/+/g)[1];

        if (strip_www === true) {
          return OWA.util.stripWwwFromDomain(domain);
        } else {
          return domain;
        }
      },
      // strips www. from begining of domain if present
      // otherwise returns the domain as is.
      stripWwwFromDomain: function stripWwwFromDomain(domain) {
        var fp = domain.split('.')[0];

        if (fp === 'www') {
          return domain.substring(4);
        } else {
          return domain;
        }
      },
      getCurrentUnixTimestamp: function getCurrentUnixTimestamp() {
        return Math.round(new Date().getTime() / 1000);
      },
      generateHash: function generateHash(value) {
        return this.crc32(value);
      },
      generateRandomGuid: function generateRandomGuid() {
        var time = this.getCurrentUnixTimestamp() + '';
        var random = OWA.util.zeroFill(this.rand(0, 999999) + '', 6);
        var client = OWA.util.zeroFill(this.rand(0, 999) + '', 3);
        return time + random + client;
      },
      crc32: function crc32(str) {
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
        crc = crc ^ -1;

        for (var i = 0, iTop = str.length; i < iTop; i++) {
          y = (crc ^ str.charCodeAt(i)) & 0xFF;
          x = "0x" + table.substr(y * 9, 8);
          crc = crc >>> 8 ^ x;
        }

        return crc ^ -1;
      },
      utf8_encode: function utf8_encode(argString) {
        // Encodes an ISO-8859-1 string to UTF-8  
        // 
        // version: 1009.2513
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
        var string = argString + ''; // .replace(/\r\n/g, "\n").replace(/\r/g, "\n");

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
            enc = String.fromCharCode(c1 >> 6 | 192) + String.fromCharCode(c1 & 63 | 128);
          } else {
            enc = String.fromCharCode(c1 >> 12 | 224) + String.fromCharCode(c1 >> 6 & 63 | 128) + String.fromCharCode(c1 & 63 | 128);
          }

          if (enc !== null) {
            if (end > start) {
              utftext += string.substring(start, end);
            }

            utftext += enc;
            start = end = n + 1;
          }
        }

        if (end > start) {
          utftext += string.substring(start, string.length);
        }

        return utftext;
      },
      utf8_decode: function utf8_decode(str_data) {
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
        var tmp_arr = [],
            i = 0,
            ac = 0,
            c1 = 0,
            c2 = 0,
            c3 = 0;
        str_data += '';

        while (i < str_data.length) {
          c1 = str_data.charCodeAt(i);

          if (c1 < 128) {
            tmp_arr[ac++] = String.fromCharCode(c1);
            i++;
          } else if (c1 > 191 && c1 < 224) {
            c2 = str_data.charCodeAt(i + 1);
            tmp_arr[ac++] = String.fromCharCode((c1 & 31) << 6 | c2 & 63);
            i += 2;
          } else {
            c2 = str_data.charCodeAt(i + 1);
            c3 = str_data.charCodeAt(i + 2);
            tmp_arr[ac++] = String.fromCharCode((c1 & 15) << 12 | (c2 & 63) << 6 | c3 & 63);
            i += 3;
          }
        }

        return tmp_arr.join('');
      },
      trim: function trim(str, charlist) {
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
        var whitespace,
            l = 0,
            i = 0;
        str += '';

        if (!charlist) {
          // default list
          whitespace = " \n\r\t\f\x0B\xA0\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200A\u200B\u2028\u2029\u3000";
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
      rand: function rand(min, max) {
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
      base64_encode: function base64_encode(data) {
        // Encodes string using MIME base64 algorithm  
        // 
        // version: 1009.2513
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
        var o1,
            o2,
            o3,
            h1,
            h2,
            h3,
            h4,
            bits,
            i = 0,
            ac = 0,
            enc = "",
            tmp_arr = [];

        if (!data) {
          return data;
        }

        data = this.utf8_encode(data + '');

        do {
          // pack three octets into four hexets
          o1 = data.charCodeAt(i++);
          o2 = data.charCodeAt(i++);
          o3 = data.charCodeAt(i++);
          bits = o1 << 16 | o2 << 8 | o3;
          h1 = bits >> 18 & 0x3f;
          h2 = bits >> 12 & 0x3f;
          h3 = bits >> 6 & 0x3f;
          h4 = bits & 0x3f; // use hexets to index into b64, and append result to encoded string

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
      base64_decode: function base64_decode(data) {
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
        var o1,
            o2,
            o3,
            h1,
            h2,
            h3,
            h4,
            bits,
            i = 0,
            ac = 0,
            dec = "",
            tmp_arr = [];

        if (!data) {
          return data;
        }

        data += '';

        do {
          // unpack four hexets into three octets using index points in b64
          h1 = b64.indexOf(data.charAt(i++));
          h2 = b64.indexOf(data.charAt(i++));
          h3 = b64.indexOf(data.charAt(i++));
          h4 = b64.indexOf(data.charAt(i++));
          bits = h1 << 18 | h2 << 12 | h3 << 6 | h4;
          o1 = bits >> 16 & 0xff;
          o2 = bits >> 8 & 0xff;
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
      sprintf: function sprintf() {
        // Return a formatted string  
        // 
        // version: 1009.2513
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
        var a = arguments,
            i = 0,
            format = a[i++]; // pad()

        var pad = function pad(str, len, chr, leftJustify) {
          if (!chr) {
            chr = ' ';
          }

          var padding = str.length >= len ? '' : Array(1 + len - str.length >>> 0).join(chr);
          return leftJustify ? str + padding : padding + str;
        }; // justify()


        var justify = function justify(value, prefix, leftJustify, minWidth, zeroPad, customPadChar) {
          var diff = minWidth - value.length;

          if (diff > 0) {
            if (leftJustify || !zeroPad) {
              value = pad(value, minWidth, customPadChar, leftJustify);
            } else {
              value = value.slice(0, prefix.length) + pad('', diff, '0', true) + value.slice(prefix.length);
            }
          }

          return value;
        }; // formatBaseX()


        var formatBaseX = function formatBaseX(value, base, prefix, leftJustify, minWidth, precision, zeroPad) {
          // Note: casts negative numbers to positive ones
          var number = value >>> 0;
          prefix = prefix && number && {
            '2': '0b',
            '8': '0',
            '16': '0x'
          }[base] || '';
          value = prefix + pad(number.toString(base), precision || 0, '0', false);
          return justify(value, prefix, leftJustify, minWidth, zeroPad);
        }; // formatString()


        var formatString = function formatString(value, leftJustify, minWidth, precision, zeroPad, customPadChar) {
          if (precision != null) {
            value = value.slice(0, precision);
          }

          return justify(value, '', leftJustify, minWidth, zeroPad, customPadChar);
        }; // doFormat()


        var doFormat = function doFormat(substring, valueIndex, flags, minWidth, _, precision, type) {
          var number;
          var prefix;
          var method;
          var textTransform;
          var value;

          if (substring == '%%') {
            return '%';
          } // parse flags


          var leftJustify = false,
              positivePrefix = '',
              zeroPad = false,
              prefixBaseX = false,
              customPadChar = ' ';
          var flagsl = flags.length;

          for (var j = 0; flags && j < flagsl; j++) {
            switch (flags.charAt(j)) {
              case ' ':
                positivePrefix = ' ';
                break;

              case '+':
                positivePrefix = '+';
                break;

              case '-':
                leftJustify = true;
                break;

              case "'":
                customPadChar = flags.charAt(j + 1);
                break;

              case '0':
                zeroPad = true;
                break;

              case '#':
                prefixBaseX = true;
                break;
            }
          } // parameters may be null, undefined, empty-string or real valued
          // we want to ignore null, undefined and empty-string values


          if (!minWidth) {
            minWidth = 0;
          } else if (minWidth == '*') {
            minWidth = +a[i++];
          } else if (minWidth.charAt(0) == '*') {
            minWidth = +a[minWidth.slice(1, -1)];
          } else {
            minWidth = +minWidth;
          } // Note: undocumented perl feature:


          if (minWidth < 0) {
            minWidth = -minWidth;
            leftJustify = true;
          }

          if (!isFinite(minWidth)) {
            throw new Error('sprintf: (minimum-)width must be finite');
          }

          if (!precision) {
            precision = 'fFeE'.indexOf(type) > -1 ? 6 : type == 'd' ? 0 : undefined;
          } else if (precision == '*') {
            precision = +a[i++];
          } else if (precision.charAt(0) == '*') {
            precision = +a[precision.slice(1, -1)];
          } else {
            precision = +precision;
          } // grab value using valueIndex if required?


          value = valueIndex ? a[valueIndex.slice(0, -1)] : a[i++];

          switch (type) {
            case 's':
              return formatString(String(value), leftJustify, minWidth, precision, zeroPad, customPadChar);

            case 'c':
              return formatString(String.fromCharCode(+value), leftJustify, minWidth, precision, zeroPad);

            case 'b':
              return formatBaseX(value, 2, prefixBaseX, leftJustify, minWidth, precision, zeroPad);

            case 'o':
              return formatBaseX(value, 8, prefixBaseX, leftJustify, minWidth, precision, zeroPad);

            case 'x':
              return formatBaseX(value, 16, prefixBaseX, leftJustify, minWidth, precision, zeroPad);

            case 'X':
              return formatBaseX(value, 16, prefixBaseX, leftJustify, minWidth, precision, zeroPad).toUpperCase();

            case 'u':
              return formatBaseX(value, 10, prefixBaseX, leftJustify, minWidth, precision, zeroPad);

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

            default:
              return substring;
          }
        };

        return format.replace(regex, doFormat);
      },
      clone: function clone(mixed) {
        var newObj = mixed instanceof Array ? [] : {};

        for (var i in mixed) {
          if (mixed[i] && _typeof(mixed[i]) == "object") {
            newObj[i] = OWA.util.clone(mixed[i]);
          } else {
            newObj[i] = mixed[i];
          }
        }

        return newObj;
      },
      strtolower: function strtolower(str) {
        return (str + '').toLowerCase();
      },
      in_array: function in_array(needle, haystack, argStrict) {
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
        var key = '',
            strict = !!argStrict;

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
      },
      dechex: function dechex(number) {
        // Returns a string containing a hexadecimal representation of the given number  
        // 
        // version: 1009.2513
        // discuss at: http://phpjs.org/functions/dechex
        // +   original by: Philippe Baumann
        // +   bugfixed by: Onno Marsman
        // +   improved by: http://stackoverflow.com/questions/57803/how-to-convert-decimal-to-hex-in-javascript
        // +   input by: pilus
        // *     example 1: dechex(10);
        // *     returns 1: 'a'
        // *     example 2: dechex(47);
        // *     returns 2: '2f'
        // *     example 3: dechex(-1415723993);
        // *     returns 3: 'ab9dc427'
        if (number < 0) {
          number = 0xFFFFFFFF + number + 1;
        }

        return parseInt(number, 10).toString(16);
      },
      explode: function explode(delimiter, string, limit) {
        // Splits a string on string separator and return array of components. 
        // If limit is positive only limit number of components is returned. 
        // If limit is negative all components except the last abs(limit) are returned.  
        // 
        // version: 1009.2513
        // discuss at: http://phpjs.org/functions/explode
        // +     original by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +     improved by: kenneth
        // +     improved by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // +     improved by: d3x
        // +     bugfixed by: Kevin van Zonneveld (http://kevin.vanzonneveld.net)
        // *     example 1: explode(' ', 'Kevin van Zonneveld');
        // *     returns 1: {0: 'Kevin', 1: 'van', 2: 'Zonneveld'}
        // *     example 2: explode('=', 'a=bc=d', 2);
        // *     returns 2: ['a', 'bc=d']
        var emptyArray = {
          0: ''
        }; // third argument is not required

        if (arguments.length < 2 || typeof arguments[0] == 'undefined' || typeof arguments[1] == 'undefined') {
          return null;
        }

        if (delimiter === '' || delimiter === false || delimiter === null) {
          return false;
        }

        if (typeof delimiter == 'function' || _typeof(delimiter) == 'object' || typeof string == 'function' || _typeof(string) == 'object') {
          return emptyArray;
        }

        if (delimiter === true) {
          delimiter = '1';
        }

        if (!limit) {
          return string.toString().split(delimiter.toString());
        } else {
          // support for limit argument
          var splitted = string.toString().split(delimiter.toString());
          var partA = splitted.splice(0, limit - 1);
          var partB = splitted.join(delimiter.toString());
          partA.push(partB);
          return partA;
        }
      },
      isIE: function isIE() {
        if (/MSIE (\d+\.\d+);/.test(navigator.userAgent)) {
          return true;
        }
      },
      getInternetExplorerVersion: function getInternetExplorerVersion() {
        // Returns the version of Internet Explorer or a -1
        // (indicating the use of another browser).
        var rv = -1; // Return value assumes failure.

        if (navigator.appName == 'Microsoft Internet Explorer') {
          var ua = navigator.userAgent;
          var re = new RegExp("MSIE ([0-9]{1,}[\.0-9]{0,})");
          if (re.exec(ua) != null) rv = parseFloat(RegExp.$1);
        }

        return rv;
      },
      isBrowserTrackable: function isBrowserTrackable() {
        var dntProperties = ['doNotTrack', 'msDoNotTrack'];

        for (var i = 0, l = dntProperties.length; i < l; i++) {
          if (navigator[dntProperties[i]] && navigator[dntProperties[i]] == "1") {
            return false;
          }
        }

        return true;
      }
    };
    window.OWA = OWA;
    /***/
  },

  /***/
  "./modules/base/js/owa.tracker.js":
  /*!****************************************!*\
    !*** ./modules/base/js/owa.tracker.js ***!
    \****************************************/

  /*! no static exports found */

  /***/
  function modulesBaseJsOwaTrackerJs(module, exports) {
    function _typeof(obj) {
      "@babel/helpers - typeof";

      if (typeof Symbol === "function" && typeof Symbol.iterator === "symbol") {
        _typeof = function _typeof(obj) {
          return typeof obj;
        };
      } else {
        _typeof = function _typeof(obj) {
          return obj && typeof Symbol === "function" && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj;
        };
      }

      return _typeof(obj);
    }
    /*! For license information please see owa.tracker.js.LICENSE.txt */


    !function (t) {
      var e = {};

      function n(r) {
        if (e[r]) return e[r].exports;
        var o = e[r] = {
          i: r,
          l: !1,
          exports: {}
        };
        return t[r].call(o.exports, o, o.exports, n), o.l = !0, o.exports;
      }

      n.m = t, n.c = e, n.d = function (t, e, r) {
        n.o(t, e) || Object.defineProperty(t, e, {
          enumerable: !0,
          get: r
        });
      }, n.r = function (t) {
        "undefined" != typeof Symbol && Symbol.toStringTag && Object.defineProperty(t, Symbol.toStringTag, {
          value: "Module"
        }), Object.defineProperty(t, "__esModule", {
          value: !0
        });
      }, n.t = function (t, e) {
        if (1 & e && (t = n(t)), 8 & e) return t;
        if (4 & e && "object" == _typeof(t) && t && t.__esModule) return t;
        var r = Object.create(null);
        if (n.r(r), Object.defineProperty(r, "default", {
          enumerable: !0,
          value: t
        }), 2 & e && "string" != typeof t) for (var o in t) {
          n.d(r, o, function (e) {
            return t[e];
          }.bind(null, o));
        }
        return r;
      }, n.n = function (t) {
        var e = t && t.__esModule ? function () {
          return t["default"];
        } : function () {
          return t;
        };
        return n.d(e, "a", e), e;
      }, n.o = function (t, e) {
        return Object.prototype.hasOwnProperty.call(t, e);
      }, n.p = "/", n(n.s = 1);
    }([function (t, e) {
      function n(t) {
        return (n = "function" == typeof Symbol && "symbol" == _typeof(Symbol.iterator) ? function (t) {
          return _typeof(t);
        } : function (t) {
          return t && "function" == typeof Symbol && t.constructor === Symbol && t !== Symbol.prototype ? "symbol" : _typeof(t);
        })(t);
      }

      var r = {
        items: {},
        hooks: {
          actions: {},
          filters: {}
        },
        loadedJsLibs: {},
        overlay: "",
        config: {
          ns: "owa_",
          baseUrl: "",
          hashCookiesToDomain: !0,
          debug: !1
        },
        state: {},
        overlayActive: !1,
        setSetting: function setSetting(t, e) {
          return this.setOption(t, e);
        },
        getSetting: function getSetting(t) {
          return this.getOption(t);
        },
        setOption: function setOption(t, e) {
          this.config[t] = e;
        },
        getOption: function getOption(t) {
          return this.config[t];
        },
        l: function l(t) {
          return t;
        },
        requireJs: function requireJs(t, e, n) {
          this.isJsLoaded(t) || r.util.loadScript(e, n), this.loadedJsLibs[t] = e;
        },
        isJsLoaded: function isJsLoaded(t) {
          if (this.loadedJsLibs.hasOwnProperty(t)) return !0;
        },
        initializeStateManager: function initializeStateManager() {
          this.state.hasOwnProperty("init") || (r.debug("initializing state manager..."), this.state = new r.stateManager());
        },
        registerStateStore: function registerStateStore(t, e, n, r) {
          return this.initializeStateManager(), this.state.registerStore(t, e, n, r);
        },
        checkForState: function checkForState(t) {
          return this.initializeStateManager(), this.state.isPresent(t);
        },
        setState: function setState(t, e, n, r, o, i) {
          return this.initializeStateManager(), this.state.set(t, e, n, r, o, i);
        },
        replaceState: function replaceState(t, e, n, r, o) {
          return this.initializeStateManager(), this.state.replaceStore(t, e, n, r, o);
        },
        getStateFromCookie: function getStateFromCookie(t) {
          return this.initializeStateManager(), this.state.getStateFromCookie(t);
        },
        getState: function getState(t, e) {
          return this.initializeStateManager(), this.state.get(t, e);
        },
        clearState: function clearState(t, e) {
          return this.initializeStateManager(), this.state.clear(t, e);
        },
        getStateStoreFormat: function getStateStoreFormat(t) {
          return this.initializeStateManager(), this.state.getStoreFormat(t);
        },
        setStateStoreFormat: function setStateStoreFormat(t, e) {
          return this.initializeStateManager(), this.state.setStoreFormat(t, e);
        },
        debug: function debug() {
          var t = r.getSetting("debug") || !1;
          t && window.console && console.log.apply && (window.console.firebug ? console.log.apply(this, arguments) : console.log.apply(console, arguments));
        },
        setApiEndpoint: function setApiEndpoint(t) {
          this.config.rest_api_endpoint = t;
        },
        getApiEndpoint: function getApiEndpoint() {
          return this.config.rest_api_endpoint || this.getSetting("baseUrl") + "api/";
        },
        loadHeatmap: function loadHeatmap(t) {
          var e = this;
          r.util.loadScript(r.getSetting("baseUrl") + "/modules/base/js/includes/jquery/jquery-1.6.4.min.js", function () {}), r.util.loadCss(r.getSetting("baseUrl") + "/modules/base/css/owa.overlay.css", function () {}), r.util.loadScript(r.getSetting("baseUrl") + "/modules/base/js/owa.heatmap.js", function () {
            e.overlay = new r.heatmap(), e.overlay.options.liveMode = !0, e.overlay.generate();
          });
        },
        loadPlayer: function loadPlayer() {
          var t = this;
          r.debug("Loading Domstream Player"), r.util.loadScript(r.getSetting("baseUrl") + "/modules/base/js/includes/jquery/jquery-1.6.4.min.js", function () {}), r.util.loadCss(r.getSetting("baseUrl") + "/modules/base/css/owa.overlay.css", function () {}), r.util.loadScript(r.getSetting("baseUrl") + "/modules/base/js/owa.player.js", function () {
            t.overlay = new r.player();
          });
        },
        startOverlaySession: function startOverlaySession(t) {
          r.overlayActive = !0, t.hasOwnProperty("api_url") && r.setApiEndpoint(t.api_url);
          var e = t;
          "loadHeatmap" === e.action ? this.loadHeatmap(t) : "loadPlayer" === e.action && this.loadPlayer(t);
        },
        endOverlaySession: function endOverlaySession() {
          r.util.eraseCookie(r.getSetting("ns") + "overlay", document.domain), r.overlayActive = !1;
        },
        addFilter: function addFilter(t, e, n) {
          void 0 === n && (n = 10), this.hooks.filters[t] = this.hooks.filters[t] || [], this.hooks.filters[t].push({
            priority: n,
            callback: e
          });
        },
        addAction: function addAction(t, e, n) {
          r.debug("Adding Action callback for: " + t), void 0 === n && (n = 10), this.hooks.actions[t] = this.hooks.actions[t] || [], this.hooks.actions[t].push({
            priority: n,
            callback: e
          });
        },
        applyFilters: function applyFilters(t, e, n) {
          r.debug("Filtering " + t + " with value:"), r.debug(e);
          var o = [];
          return void 0 !== this.hooks.filters[t] && this.hooks.filters[t].length > 0 && (r.debug("Applying filters for " + t), this.hooks.filters[t].forEach(function (t) {
            o[t.priority] = o[t.priority] || [], o[t.priority].push(t.callback);
          }), o.forEach(function (t) {
            t.forEach(function (t) {
              e = t(e, n), r.debug("Filter returned value: "), r.debug(e);
            });
          })), e;
        },
        doAction: function doAction(t, e) {
          r.debug("Doing Action: " + t);
          var n = [];
          void 0 !== this.hooks.actions[t] && this.hooks.actions[t].length > 0 && (r.debug(this.hooks.actions[t]), this.hooks.actions[t].forEach(function (t) {
            n[t.priority] = n[t.priority] || [], n[t.priority].push(t.callback);
          }), n.forEach(function (n) {
            r.debug("Executing Action callabck for: " + t), n.forEach(function (t) {
              t(e);
            });
          }));
        },
        removeAction: function removeAction(t, e) {
          this.hooks.actions[t] = this.hooks.actions[t] || [], this.hooks.actions[t].forEach(function (n, r) {
            n.callback === e && this.hooks.actions[t].splice(r, 1);
          });
        },
        removeFilter: function removeFilter(t, e) {
          this.hooks.filters[t] = this.hooks.filters[t] || [], this.hooks.filters[t].forEach(function (e, n) {
            e.callback === callback && this.hooks.filters[t].splice(n, 1);
          });
        },
        stateManager: function stateManager() {
          this.cookies = r.util.readAllCookies(), this.init = !0;
        }
      };
      r.stateManager.prototype = {
        init: !1,
        cookies: "",
        stores: {},
        storeFormats: {},
        storeMeta: {},
        registerStore: function registerStore(t, e, n, r) {
          this.storeMeta[t] = {
            expiration: e,
            length: n,
            format: r
          };
        },
        getExpirationDays: function getExpirationDays(t) {
          if (this.storeMeta.hasOwnProperty(t)) return this.storeMeta[t].expiration;
        },
        getFormat: function getFormat(t) {
          if (this.storeMeta.hasOwnProperty(t)) return this.storeMeta[t].format;
        },
        isPresent: function isPresent(t) {
          if (this.stores.hasOwnProperty(t)) return !0;
        },
        set: function set(t, e, n, o, i, s) {
          this.isPresent(t) || this.load(t), this.isPresent(t) || (r.debug("Creating state store (%s)", t), this.stores[t] = {}, r.getSetting("hashCookiesToDomain") && (this.stores[t].cdh = r.util.getCookieDomainHash(r.getSetting("cookie_domain")))), e ? this.stores[t][e] = n : this.stores[t] = n, (i = this.getFormat(t)) || this.storeFormats.hasOwnProperty(t) && (i = this.storeFormats[t]);
          var a = "";
          a = "json" === i ? JSON.stringify(this.stores[t]) : r.util.assocStringFromJson(this.stores[t]), (s = this.getExpirationDays(t)) || o && (s = 364), r.debug("Populating state store (%s) with value: %s", t, a);
          var u = r.getSetting("cookie_domain") || document.domain;
          r.util.setCookie(r.getSetting("ns") + t, a, s, "/", u);
        },
        replaceStore: function replaceStore(t, e, n, o, i) {
          if (r.debug("replace state format: %s, value: %s", o, JSON.stringify(e)), t) {
            e && (o = this.getFormat(t), this.stores[t] = e, this.storeFormats[t] = o, cookie_value = "json" === o ? JSON.stringify(e) : r.util.assocStringFromJson(e));
            var s = r.getSetting("cookie_domain") || document.domain;
            i = this.getExpirationDays(t), r.debug("About to replace state store (%s) with: %s", t, cookie_value), r.util.setCookie(r.getSetting("ns") + t, cookie_value, i, "/", s);
          }
        },
        getStateFromCookie: function getStateFromCookie(t) {
          var e = unescape(r.util.readCookie(r.getSetting("ns") + t));
          if (e) return e;
        },
        get: function get(t, e) {
          return this.isPresent(t) || this.load(t), this.isPresent(t) ? e ? this.stores[t].hasOwnProperty(e) ? this.stores[t][e] : void 0 : this.stores[t] : (r.debug("No state store (%s) was found", t), "");
        },
        getCookieValues: function getCookieValues(t) {
          if (this.cookies.hasOwnProperty(t)) return this.cookies[t];
        },
        load: function load(t) {
          var e = "",
              n = this.getCookieValues(r.getSetting("ns") + t);
          if (n) for (var o = 0; o < n.length; o++) {
            var i = unescape(n[o]),
                s = r.util.decodeCookieValue(i),
                a = r.util.getCookieValueFormat(i);

            if (r.getSetting("hashCookiesToDomain")) {
              var u = r.getSetting("cookie_domain"),
                  c = r.util.getCookieDomainHash(u);

              if (s.hasOwnProperty("cdh")) {
                if (r.debug("Cookie value cdh: %s, domain hash: %s", s.cdh, c), s.cdh == c) {
                  r.debug("Cookie: %s, index: %s domain hash matches current cookie domain. Loading...", t, o), e = s;
                  break;
                }

                r.debug("Cookie: %s, index: %s domain hash does not match current cookie domain. Not loading.", t, o);
              } else r.debug("Cookie: %s, index: %s has no domain hash. Not going to Load it.", t, o);
            } else {
              o === n.length - 1 && (e = s);
            }
          }
          e ? (this.stores[t] = e, this.storeFormats[t] = a, r.debug("Loaded state store: %s with: %s", t, JSON.stringify(e))) : r.debug("No state for store: %s was found. Nothing to Load.", t);
        },
        clear: function clear(t, e) {
          if (e) {
            var n = this.get(t);
            n && n.hasOwnProperty(e) && (delete n.key, this.replaceStore(t, n, !0, this.getFormat(t), this.getExpirationDays(t)));
          } else delete this.stores[t], r.util.eraseCookie(r.getSetting("ns") + t), this.cookies = r.util.readAllCookies();
        },
        getStoreFormat: function getStoreFormat(t) {
          return this.getFormat(t);
        },
        setStoreFormat: function setStoreFormat(t, e) {
          this.storeFormats[t] = e;
        }
      }, r.uri = function (t) {
        this.components = {}, this.dirty = !1, this.options = {
          strictMode: !1,
          key: ["source", "protocol", "authority", "userInfo", "user", "password", "host", "port", "relative", "path", "directory", "file", "query", "anchor"],
          q: {
            name: "queryKey",
            parser: /(?:^|&)([^&=]*)=?([^&]*)/g
          },
          parser: {
            strict: /^(?:([^:\/?#]+):)?(?:\/\/((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?))?((((?:[^?#\/]*\/)*)([^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
            loose: /^(?:(?![^:@]+:[^:@\/]*@)([^:\/?#.]+):)?(?:\/\/)?((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?)(((\/(?:[^?#](?![^?#\/]*\.[^?#\/.]+(?:[?#]|$)))*\/?)?([^?#\/]*))(?:\?([^#]*))?(?:#(.*))?)/
          }
        }, t && (this.components = this.parseUri(t));
      }, r.uri.prototype = {
        parseUri: function parseUri(t) {
          for (var e = this.options, n = e.parser[e.strictMode ? "strict" : "loose"].exec(t), r = {}, o = 14; o--;) {
            r[e.key[o]] = n[o] || "";
          }

          return r[e.q.name] = {}, r[e.key[12]].replace(e.q.parser, function (t, n, o) {
            n && (r[e.q.name][n] = o);
          }), r;
        },
        getHost: function getHost() {
          if (this.components.hasOwnProperty("host")) return this.components.host;
        },
        getQueryParam: function getQueryParam(t) {
          if (this.components.hasOwnProperty("queryKey") && this.components.queryKey.hasOwnProperty(t)) return r.util.urldecode(this.components.queryKey[t]);
        },
        isQueryParam: function isQueryParam(t) {
          return !(!this.components.hasOwnProperty("queryKey") || !this.components.queryKey.hasOwnProperty(t));
        },
        getComponent: function getComponent(t) {
          if (this.components.hasOwnProperty(t)) return this.components[t];
        },
        getProtocol: function getProtocol() {
          return this.getComponent("protocol");
        },
        getAnchor: function getAnchor() {
          return this.getComponent("anchor");
        },
        getQuery: function getQuery() {
          return this.getComponent("query");
        },
        getFile: function getFile() {
          return this.getComponent("file");
        },
        getRelative: function getRelative() {
          return this.getComponent("relative");
        },
        getDirectory: function getDirectory() {
          return this.getComponent("directory");
        },
        getPath: function getPath() {
          return this.getComponent("path");
        },
        getPort: function getPort() {
          return this.getComponent("port");
        },
        getPassword: function getPassword() {
          return this.getComponent("password");
        },
        getUser: function getUser() {
          return this.getComponent("user");
        },
        getUserInfo: function getUserInfo() {
          return this.getComponent("userInfo");
        },
        getQueryParams: function getQueryParams() {
          return this.getComponent("queryKey");
        },
        getSource: function getSource() {
          return this.getComponent("source");
        },
        setQueryParam: function setQueryParam(t, e) {
          this.components.hasOwnProperty("queryKey") || (this.components.queryKey = {}), this.components.queryKey[t] = r.util.urlEncode(e), this.resetQuery();
        },
        removeQueryParam: function removeQueryParam(t) {
          this.components.hasOwnProperty("queryKey") && this.components.queryKey.hasOwnProperty(t) && (delete this.components.queryKey[t], this.resetQuery());
        },
        resetSource: function resetSource() {
          this.components.source = this.assembleUrl();
        },
        resetQuery: function resetQuery() {
          var t = this.getQueryParams();

          if (t) {
            var e = "",
                n = r.util.countObjectProperties(t);

            for (var o in t) {
              e += o + "=" + t[o], 1 < n && (e += "&");
            }

            this.components.query = e, this.resetSource();
          }
        },
        isDirty: function isDirty() {
          return this.dirty;
        },
        setPath: function setPath(t) {},
        assembleUrl: function assembleUrl() {
          var t = "";
          t += this.getProtocol(), t += "://", this.getUser() && (t += this.getUser()), this.getUser() && this.getPassword() && (t += ":" + this.password()), t += this.getHost(), this.getPort() && (t += ":" + this.getPort()), t += this.getDirectory(), t += this.getFile();
          var e = this.getQuery();
          e && (t += "?" + e);
          var n = this.getAnchor();
          return n && (t += "#" + n), t += this.getAnchor();
        }
      }, r.util = {
        ns: function ns(t) {
          return r.config.ns + t;
        },
        nsAll: function nsAll(t) {
          var e = new Object();

          for (param in t) {
            t.hasOwnProperty(param) && (e[r.config.ns + param] = t[param]);
          }

          return e;
        },
        getScript: function getScript(t, e) {
          jQuery.getScript(e + t);
        },
        makeUrl: function makeUrl(t, e, n) {
          return jQuery.sprintf(t, e, jQuery.param(r.util.nsAll(n)));
        },
        createCookie: function createCookie(t, e, n, r) {
          if (n) {
            var o = new Date();
            o.setTime(o.getTime() + 24 * n * 60 * 60 * 1e3);
            var i = "; expires=" + o.toGMTString();
          } else i = "";

          document.cookie = t + "=" + e + i + "; path=/";
        },
        setCookie: function setCookie(t, e, n, r, o, i) {
          var s = new Date();
          s.setTime(s.getTime() + 24 * n * 60 * 60 * 1e3), document.cookie = t + "=" + escape(e) + (n ? "; expires=" + s.toGMTString() : "") + (r ? "; path=" + r : "") + (o ? "; domain=" + o : "") + (i ? "; secure" : "");
        },
        readAllCookies: function readAllCookies() {
          r.debug("Reading all cookies...");
          var t = {},
              e = document.cookie.split(";");

          if (e) {
            r.debug(document.cookie);

            for (var n = 0; n < e.length; n++) {
              var o = r.util.trim(e[n]),
                  i = r.util.strpos(o, "="),
                  s = o.substring(0, i),
                  a = o.substring(i + 1, o.length);
              t.hasOwnProperty(s) || (t[s] = []), t[s].push(a);
            }

            return r.debug(JSON.stringify(t)), t;
          }
        },
        readCookie: function readCookie(t) {
          r.debug("Attempting to read cookie: %s", t);
          var e = r.util.readAllCookies();
          if (e) return e.hasOwnProperty(t) ? e[t] : "";
        },
        eraseCookie: function eraseCookie(t, e) {
          if (r.debug(document.cookie), e || (e = r.getSetting("cookie_domain") || document.domain), r.debug("erasing cookie: " + t + " in domain: " + e), this.setCookie(t, "", -1, "/", e), r.util.readCookie(t)) {
            var n = e.substr(0, 1);

            if (r.debug("period: " + n), "." === n) {
              var o = e.substr(1);
              r.debug("erasing " + t + " in domain2: " + o), this.setCookie(t, "", -2, "/", o);
            } else r.debug("erasing " + t + " in domain3: " + e), this.setCookie(t, "", -2, "/", e);
          }
        },
        eraseMultipleCookies: function eraseMultipleCookies(t, e) {
          for (var n = 0; n < t.length; n++) {
            this.eraseCookie(t[n], e);
          }
        },
        loadScript: function loadScript(t, e) {
          var n = document.createElement("script");
          n.type = "text/javascript", n.readyState ? n.onreadystatechange = function () {
            "loaded" != n.readyState && "complete" != n.readyState || (n.onreadystatechange = null, e());
          } : n.onload = function () {
            e();
          }, n.src = t, document.getElementsByTagName("head")[0].appendChild(n);
        },
        loadCss: function loadCss(t, e) {
          var n = document.createElement("link");
          n.rel = "stylesheet", n.type = "text/css", n.href = t, document.getElementsByTagName("HEAD")[0].appendChild(n);
        },
        parseCookieString: function parseCookieString(t) {
          var e = new Array(),
              n = unescape(t).split("|||");

          for (var r in n) {
            if (n.hasOwnProperty(r)) {
              var o = n[r].split("=>");
              e[o[0]] = o[1];
            }
          }

          return e;
        },
        parseCookieStringToJson: function parseCookieStringToJson(t) {
          var e = new Object(),
              n = unescape(t).split("|||");

          for (var r in n) {
            if (n.hasOwnProperty(r)) {
              var o = n[r].split("=>");
              e[o[0]] = o[1];
            }
          }

          return e;
        },
        nsParams: function nsParams(t) {
          var e = new Object();

          for (param in t) {
            t.hasOwnProperty(param) && (e[r.getSetting("ns") + param] = t[param]);
          }

          return e;
        },
        urlEncode: function urlEncode(t) {
          return t = (t + "").toString(), encodeURIComponent(t).replace(/!/g, "%21").replace(/'/g, "%27").replace(/\(/g, "%28").replace(/\)/g, "%29").replace(/\*/g, "%2A").replace(/%20/g, "+");
        },
        urldecode: function urldecode(t) {
          return decodeURIComponent(t.replace(/\+/g, "%20"));
        },
        parseUrlParams: function parseUrlParams(t) {
          for (var e, n, r, o, i, s, a = {}, u = location.href.split(/[?&]/), c = u.length, l = 1; l < c; l++) {
            if ((r = u[l].match(/(.*?)(\..*?|\[.*?\])?=([^#]*)/)) && 4 == r.length) {
              if (o = decodeURI(r[1]).toLowerCase(), i = a, s = decodeURI(r[3]), r[2]) for (n = decodeURI(r[2]).replace(/\[\s*\]/g, "[-1]").split(/[\.\[\]]/), e = 0; e < n.length; e++) {
                i = i[o] ? i[o] : i[o] = parseInt(n[e]) == n[e] ? [] : {}, o = n[e].replace(/^["\'](.*)["\']$/, "$1");
              }
              "-1" != o ? i[o] = s : i[i.length] = s;
            }
          }

          return a;
        },
        strpos: function strpos(t, e, n) {
          var r = (t + "").indexOf(e, n || 0);
          return -1 !== r && r;
        },
        strCountOccurances: function strCountOccurances(t, e) {
          return t.split(e).length - 1;
        },
        implode: function implode(t, e) {
          var r = "",
              o = "",
              i = "";

          if (1 === arguments.length && (e = t, t = ""), "object" === n(e)) {
            if (e instanceof Array) return e.join(t);

            for (r in e) {
              o += i + e[r], i = t;
            }

            return o;
          }

          return e;
        },
        checkForState: function checkForState(t) {
          return r.checkForState(t);
        },
        setState: function setState(t, e, n, o, i, s) {
          return r.setState(t, e, n, o, i, s);
        },
        replaceState: function replaceState(t, e, n, o, i) {
          return r.replaceState(t, e, n, o, i);
        },
        getRawState: function getRawState(t) {
          return r.getStateFromCookie(t);
        },
        getState: function getState(t, e) {
          return r.getState(t, e);
        },
        clearState: function clearState(t, e) {
          return r.clearState(t, e);
        },
        getCookieValueFormat: function getCookieValueFormat(t) {
          return "{" === t.substr(0, 1) ? "json" : "assoc";
        },
        decodeCookieValue: function decodeCookieValue(t) {
          var e = r.util.getCookieValueFormat(t),
              n = "";
          return n = "json" === e ? JSON.parse(t) : r.util.jsonFromAssocString(t), r.debug("decodeCookieValue - string: %s, format: %s, value: %s", t, e, JSON.stringify(n)), n;
        },
        encodeJsonForCookie: function encodeJsonForCookie(t, e) {
          return "json" === (e = e || "assoc") ? JSON.stringify(t) : r.util.assocStringFromJson(t);
        },
        getCookieDomainHash: function getCookieDomainHash(t) {
          return r.util.dechex(r.util.crc32(t));
        },
        loadStateJson: function loadStateJson(t) {
          var e = unescape(r.util.readCookie(r.getSetting("ns") + t));
          e && (state = JSON.parse(e)), r.state[t] = state, r.debug("state store %s: %s", t, JSON.stringify(state));
        },
        is_array: function is_array(t) {
          return "object" == n(t) && t instanceof Array;
        },
        str_pad: function str_pad(t, e, n, r) {
          var o,
              i = "",
              s = function s(t, e) {
            for (var n = ""; n.length < e;) {
              n += t;
            }

            return n = n.substr(0, e);
          };

          return n = void 0 !== n ? n : " ", "STR_PAD_LEFT" != r && "STR_PAD_RIGHT" != r && "STR_PAD_BOTH" != r && (r = "STR_PAD_RIGHT"), (o = e - (t += "").length) > 0 && ("STR_PAD_LEFT" == r ? t = s(n, o) + t : "STR_PAD_RIGHT" == r ? t += s(n, o) : "STR_PAD_BOTH" == r && (t = (t = (i = s(n, Math.ceil(o / 2))) + t + i).substr(0, e))), t;
        },
        zeroFill: function zeroFill(t, e) {
          return r.util.str_pad(t, e, "0", "STR_PAD_LEFT");
        },
        is_object: function is_object(t) {
          return !(t instanceof Array) && null !== t && "object" == n(t);
        },
        countObjectProperties: function countObjectProperties(t) {
          var e,
              n = 0;

          for (e in t) {
            t.hasOwnProperty(e) && n++;
          }

          return n;
        },
        jsonFromAssocString: function jsonFromAssocString(t, e, n) {
          if (e = e || "=>", n = n || "|||", t) {
            if (!this.strpos(t, e)) return t;

            for (var r = {}, o = t.split(n), i = 0, s = o.length; i < s; i++) {
              var a = o[i].split(e);
              r[a[0]] = a[1];
            }

            return r;
          }
        },
        assocStringFromJson: function assocStringFromJson(t) {
          var e = "",
              n = 0,
              o = r.util.countObjectProperties(t);

          for (var i in t) {
            n++, e += i + "=>" + t[i], n < o && (e += "|||");
          }

          return e;
        },
        getDomainFromUrl: function getDomainFromUrl(t, e) {
          var n = t.split(/\/+/g)[1];
          return !0 === e ? r.util.stripWwwFromDomain(n) : n;
        },
        stripWwwFromDomain: function stripWwwFromDomain(t) {
          return "www" === t.split(".")[0] ? t.substring(4) : t;
        },
        getCurrentUnixTimestamp: function getCurrentUnixTimestamp() {
          return Math.round(new Date().getTime() / 1e3);
        },
        generateHash: function generateHash(t) {
          return this.crc32(t);
        },
        generateRandomGuid: function generateRandomGuid() {
          return this.getCurrentUnixTimestamp() + "" + r.util.zeroFill(this.rand(0, 999999) + "", 6) + r.util.zeroFill(this.rand(0, 999) + "", 3);
        },
        crc32: function crc32(t) {
          var e = 0,
              n = 0;
          e ^= -1;

          for (var r = 0, o = (t = this.utf8_encode(t)).length; r < o; r++) {
            n = 255 & (e ^ t.charCodeAt(r)), e = e >>> 8 ^ "0x" + "00000000 77073096 EE0E612C 990951BA 076DC419 706AF48F E963A535 9E6495A3 0EDB8832 79DCB8A4 E0D5E91E 97D2D988 09B64C2B 7EB17CBD E7B82D07 90BF1D91 1DB71064 6AB020F2 F3B97148 84BE41DE 1ADAD47D 6DDDE4EB F4D4B551 83D385C7 136C9856 646BA8C0 FD62F97A 8A65C9EC 14015C4F 63066CD9 FA0F3D63 8D080DF5 3B6E20C8 4C69105E D56041E4 A2677172 3C03E4D1 4B04D447 D20D85FD A50AB56B 35B5A8FA 42B2986C DBBBC9D6 ACBCF940 32D86CE3 45DF5C75 DCD60DCF ABD13D59 26D930AC 51DE003A C8D75180 BFD06116 21B4F4B5 56B3C423 CFBA9599 B8BDA50F 2802B89E 5F058808 C60CD9B2 B10BE924 2F6F7C87 58684C11 C1611DAB B6662D3D 76DC4190 01DB7106 98D220BC EFD5102A 71B18589 06B6B51F 9FBFE4A5 E8B8D433 7807C9A2 0F00F934 9609A88E E10E9818 7F6A0DBB 086D3D2D 91646C97 E6635C01 6B6B51F4 1C6C6162 856530D8 F262004E 6C0695ED 1B01A57B 8208F4C1 F50FC457 65B0D9C6 12B7E950 8BBEB8EA FCB9887C 62DD1DDF 15DA2D49 8CD37CF3 FBD44C65 4DB26158 3AB551CE A3BC0074 D4BB30E2 4ADFA541 3DD895D7 A4D1C46D D3D6F4FB 4369E96A 346ED9FC AD678846 DA60B8D0 44042D73 33031DE5 AA0A4C5F DD0D7CC9 5005713C 270241AA BE0B1010 C90C2086 5768B525 206F85B3 B966D409 CE61E49F 5EDEF90E 29D9C998 B0D09822 C7D7A8B4 59B33D17 2EB40D81 B7BD5C3B C0BA6CAD EDB88320 9ABFB3B6 03B6E20C 74B1D29A EAD54739 9DD277AF 04DB2615 73DC1683 E3630B12 94643B84 0D6D6A3E 7A6A5AA8 E40ECF0B 9309FF9D 0A00AE27 7D079EB1 F00F9344 8708A3D2 1E01F268 6906C2FE F762575D 806567CB 196C3671 6E6B06E7 FED41B76 89D32BE0 10DA7A5A 67DD4ACC F9B9DF6F 8EBEEFF9 17B7BE43 60B08ED5 D6D6A3E8 A1D1937E 38D8C2C4 4FDFF252 D1BB67F1 A6BC5767 3FB506DD 48B2364B D80D2BDA AF0A1B4C 36034AF6 41047A60 DF60EFC3 A867DF55 316E8EEF 4669BE79 CB61B38C BC66831A 256FD2A0 5268E236 CC0C7795 BB0B4703 220216B9 5505262F C5BA3BBE B2BD0B28 2BB45A92 5CB36A04 C2D7FFA7 B5D0CF31 2CD99E8B 5BDEAE1D 9B64C2B0 EC63F226 756AA39C 026D930A 9C0906A9 EB0E363F 72076785 05005713 95BF4A82 E2B87A14 7BB12BAE 0CB61B38 92D28E9B E5D5BE0D 7CDCEFB7 0BDBDF21 86D3D2D4 F1D4E242 68DDB3F8 1FDA836E 81BE16CD F6B9265B 6FB077E1 18B74777 88085AE6 FF0F6A70 66063BCA 11010B5C 8F659EFF F862AE69 616BFFD3 166CCF45 A00AE278 D70DD2EE 4E048354 3903B3C2 A7672661 D06016F7 4969474D 3E6E77DB AED16A4A D9D65ADC 40DF0B66 37D83BF0 A9BCAE53 DEBB9EC5 47B2CF7F 30B5FFE9 BDBDF21C CABAC28A 53B39330 24B4A3A6 BAD03605 CDD70693 54DE5729 23D967BF B3667A2E C4614AB8 5D681B02 2A6F2B94 B40BBE37 C30C8EA1 5A05DF1B 2D02EF8D".substr(9 * n, 8);
          }

          return -1 ^ e;
        },
        utf8_encode: function utf8_encode(t) {
          var e,
              n,
              r,
              o = t + "",
              i = "";
          e = n = 0, r = o.length;

          for (var s = 0; s < r; s++) {
            var a = o.charCodeAt(s),
                u = null;
            a < 128 ? n++ : u = a > 127 && a < 2048 ? String.fromCharCode(a >> 6 | 192) + String.fromCharCode(63 & a | 128) : String.fromCharCode(a >> 12 | 224) + String.fromCharCode(a >> 6 & 63 | 128) + String.fromCharCode(63 & a | 128), null !== u && (n > e && (i += o.substring(e, n)), i += u, e = n = s + 1);
          }

          return n > e && (i += o.substring(e, o.length)), i;
        },
        utf8_decode: function utf8_decode(t) {
          var e = [],
              n = 0,
              r = 0,
              o = 0,
              i = 0,
              s = 0;

          for (t += ""; n < t.length;) {
            (o = t.charCodeAt(n)) < 128 ? (e[r++] = String.fromCharCode(o), n++) : o > 191 && o < 224 ? (i = t.charCodeAt(n + 1), e[r++] = String.fromCharCode((31 & o) << 6 | 63 & i), n += 2) : (i = t.charCodeAt(n + 1), s = t.charCodeAt(n + 2), e[r++] = String.fromCharCode((15 & o) << 12 | (63 & i) << 6 | 63 & s), n += 3);
          }

          return e.join("");
        },
        trim: function trim(t, e) {
          var n,
              r = 0,
              o = 0;

          for (t += "", n = e ? (e += "").replace(/([\[\]\(\)\.\?\/\*\{\}\+\$\^\:])/g, "$1") : " \n\r\t\f\x0B\xA0\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200A\u200B\u2028\u2029\u3000", r = t.length, o = 0; o < r; o++) {
            if (-1 === n.indexOf(t.charAt(o))) {
              t = t.substring(o);
              break;
            }
          }

          for (o = (r = t.length) - 1; o >= 0; o--) {
            if (-1 === n.indexOf(t.charAt(o))) {
              t = t.substring(0, o + 1);
              break;
            }
          }

          return -1 === n.indexOf(t.charAt(0)) ? t : "";
        },
        rand: function rand(t, e) {
          var n = arguments.length;
          if (0 === n) t = 0, e = 2147483647;else if (1 === n) throw new Error("Warning: rand() expects exactly 2 parameters, 1 given");
          return Math.floor(Math.random() * (e - t + 1)) + t;
        },
        base64_encode: function base64_encode(t) {
          var e,
              n,
              r,
              o,
              i,
              s = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
              a = 0,
              u = 0,
              c = "",
              l = [];
          if (!t) return t;
          t = this.utf8_encode(t + "");

          do {
            e = (i = t.charCodeAt(a++) << 16 | t.charCodeAt(a++) << 8 | t.charCodeAt(a++)) >> 18 & 63, n = i >> 12 & 63, r = i >> 6 & 63, o = 63 & i, l[u++] = s.charAt(e) + s.charAt(n) + s.charAt(r) + s.charAt(o);
          } while (a < t.length);

          switch (c = l.join(""), t.length % 3) {
            case 1:
              c = c.slice(0, -2) + "==";
              break;

            case 2:
              c = c.slice(0, -1) + "=";
          }

          return c;
        },
        base64_decode: function base64_decode(t) {
          var e,
              n,
              r,
              o,
              i,
              s,
              a = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
              u = 0,
              c = 0,
              l = "",
              h = [];
          if (!t) return t;
          t += "";

          do {
            e = (s = a.indexOf(t.charAt(u++)) << 18 | a.indexOf(t.charAt(u++)) << 12 | (o = a.indexOf(t.charAt(u++))) << 6 | (i = a.indexOf(t.charAt(u++)))) >> 16 & 255, n = s >> 8 & 255, r = 255 & s, h[c++] = 64 == o ? String.fromCharCode(e) : 64 == i ? String.fromCharCode(e, n) : String.fromCharCode(e, n, r);
          } while (u < t.length);

          return l = h.join(""), l = this.utf8_decode(l);
        },
        sprintf: function sprintf() {
          var t = /%%|%(\d+\$)?([-+\'#0 ]*)(\*\d+\$|\*|\d+)?(\.(\*\d+\$|\*|\d+))?([scboxXuidfegEG])/g,
              e = arguments,
              n = 0,
              r = e[n++],
              o = function o(t, e, n, r) {
            n || (n = " ");
            var o = t.length >= e ? "" : Array(1 + e - t.length >>> 0).join(n);
            return r ? t + o : o + t;
          },
              i = function i(t, e, n, r, _i, s) {
            var a = r - t.length;
            return a > 0 && (t = n || !_i ? o(t, r, s, n) : t.slice(0, e.length) + o("", a, "0", !0) + t.slice(e.length)), t;
          },
              s = function s(t, e, n, r, _s, a, u) {
            var c = t >>> 0;
            return t = (n = n && c && {
              2: "0b",
              8: "0",
              16: "0x"
            }[e] || "") + o(c.toString(e), a || 0, "0", !1), i(t, n, r, _s, u);
          },
              a = function a(t, e, n, r, o, s) {
            return null != r && (t = t.slice(0, r)), i(t, "", e, n, o, s);
          },
              u = function u(t, r, _u, c, l, h, g) {
            var f, d, p, m, C;
            if ("%%" == t) return "%";

            for (var A = !1, y = "", D = !1, v = !1, S = " ", b = _u.length, E = 0; _u && E < b; E++) {
              switch (_u.charAt(E)) {
                case " ":
                  y = " ";
                  break;

                case "+":
                  y = "+";
                  break;

                case "-":
                  A = !0;
                  break;

                case "'":
                  S = _u.charAt(E + 1);
                  break;

                case "0":
                  D = !0;
                  break;

                case "#":
                  v = !0;
              }
            }

            if ((c = c ? "*" == c ? +e[n++] : "*" == c.charAt(0) ? +e[c.slice(1, -1)] : +c : 0) < 0 && (c = -c, A = !0), !isFinite(c)) throw new Error("sprintf: (minimum-)width must be finite");

            switch (h = h ? "*" == h ? +e[n++] : "*" == h.charAt(0) ? +e[h.slice(1, -1)] : +h : "fFeE".indexOf(g) > -1 ? 6 : "d" == g ? 0 : void 0, C = r ? e[r.slice(0, -1)] : e[n++], g) {
              case "s":
                return a(String(C), A, c, h, D, S);

              case "c":
                return a(String.fromCharCode(+C), A, c, h, D);

              case "b":
                return s(C, 2, v, A, c, h, D);

              case "o":
                return s(C, 8, v, A, c, h, D);

              case "x":
                return s(C, 16, v, A, c, h, D);

              case "X":
                return s(C, 16, v, A, c, h, D).toUpperCase();

              case "u":
                return s(C, 10, v, A, c, h, D);

              case "i":
              case "d":
                return C = (d = (f = parseInt(+C, 10)) < 0 ? "-" : y) + o(String(Math.abs(f)), h, "0", !1), i(C, d, A, c, D);

              case "e":
              case "E":
              case "f":
              case "F":
              case "g":
              case "G":
                return d = (f = +C) < 0 ? "-" : y, p = ["toExponential", "toFixed", "toPrecision"]["efg".indexOf(g.toLowerCase())], m = ["toString", "toUpperCase"]["eEfFgG".indexOf(g) % 2], C = d + Math.abs(f)[p](h), i(C, d, A, c, D)[m]();

              default:
                return t;
            }
          };

          return r.replace(t, u);
        },
        clone: function clone(t) {
          var e = t instanceof Array ? [] : {};

          for (var o in t) {
            t[o] && "object" == n(t[o]) ? e[o] = r.util.clone(t[o]) : e[o] = t[o];
          }

          return e;
        },
        strtolower: function strtolower(t) {
          return (t + "").toLowerCase();
        },
        in_array: function in_array(t, e, n) {
          var r = "";

          if (!!n) {
            for (r in e) {
              if (e[r] === t) return !0;
            }
          } else for (r in e) {
            if (e[r] == t) return !0;
          }

          return !1;
        },
        dechex: function dechex(t) {
          return t < 0 && (t = 4294967295 + t + 1), parseInt(t, 10).toString(16);
        },
        explode: function explode(t, e, r) {
          var o = {
            0: ""
          };
          if (arguments.length < 2 || void 0 === arguments[0] || void 0 === arguments[1]) return null;
          if ("" === t || !1 === t || null === t) return !1;
          if ("function" == typeof t || "object" == n(t) || "function" == typeof e || "object" == n(e)) return o;

          if (!0 === t && (t = "1"), r) {
            var i = e.toString().split(t.toString()),
                s = i.splice(0, r - 1),
                a = i.join(t.toString());
            return s.push(a), s;
          }

          return e.toString().split(t.toString());
        },
        isIE: function isIE() {
          if (/MSIE (\d+\.\d+);/.test(navigator.userAgent)) return !0;
        },
        getInternetExplorerVersion: function getInternetExplorerVersion() {
          var t = -1;

          if ("Microsoft Internet Explorer" == navigator.appName) {
            var e = navigator.userAgent;
            null != new RegExp("MSIE ([0-9]{1,}[.0-9]{0,})").exec(e) && (t = parseFloat(RegExp.$1));
          }

          return t;
        },
        isBrowserTrackable: function isBrowserTrackable() {
          for (var t = ["doNotTrack", "msDoNotTrack"], e = 0, n = t.length; e < n; e++) {
            if (navigator[t[e]] && "1" == navigator[t[e]]) return !1;
          }

          return !0;
        }
      }, window.OWA = r;
    }, function (t, e, n) {
      n(0), n(2), n(10), t.exports = n(15);
    }, function (t, e) {
      function n(t) {
        return (n = "function" == typeof Symbol && "symbol" == _typeof(Symbol.iterator) ? function (t) {
          return _typeof(t);
        } : function (t) {
          return t && "function" == typeof Symbol && t.constructor === Symbol && t !== Symbol.prototype ? "symbol" : _typeof(t);
        })(t);
      }

      !function (t) {
        var e = {};

        function r(n) {
          if (e[n]) return e[n].exports;
          var o = e[n] = {
            i: n,
            l: !1,
            exports: {}
          };
          return t[n].call(o.exports, o, o.exports, r), o.l = !0, o.exports;
        }

        r.m = t, r.c = e, r.d = function (t, e, n) {
          r.o(t, e) || Object.defineProperty(t, e, {
            enumerable: !0,
            get: n
          });
        }, r.r = function (t) {
          "undefined" != typeof Symbol && Symbol.toStringTag && Object.defineProperty(t, Symbol.toStringTag, {
            value: "Module"
          }), Object.defineProperty(t, "__esModule", {
            value: !0
          });
        }, r.t = function (t, e) {
          if (1 & e && (t = r(t)), 8 & e) return t;
          if (4 & e && "object" == n(t) && t && t.__esModule) return t;
          var o = Object.create(null);
          if (r.r(o), Object.defineProperty(o, "default", {
            enumerable: !0,
            value: t
          }), 2 & e && "string" != typeof t) for (var i in t) {
            r.d(o, i, function (e) {
              return t[e];
            }.bind(null, i));
          }
          return o;
        }, r.n = function (t) {
          var e = t && t.__esModule ? function () {
            return t["default"];
          } : function () {
            return t;
          };
          return r.d(e, "a", e), e;
        }, r.o = function (t, e) {
          return Object.prototype.hasOwnProperty.call(t, e);
        }, r.p = "/", r(r.s = 1);
      }([function (t, e) {
        function r(t) {
          return (r = "function" == typeof Symbol && "symbol" == n(Symbol.iterator) ? function (t) {
            return n(t);
          } : function (t) {
            return t && "function" == typeof Symbol && t.constructor === Symbol && t !== Symbol.prototype ? "symbol" : n(t);
          })(t);
        }

        var o = {
          items: {},
          hooks: {
            actions: {},
            filters: {}
          },
          loadedJsLibs: {},
          overlay: "",
          config: {
            ns: "owa_",
            baseUrl: "",
            hashCookiesToDomain: !0,
            debug: !1
          },
          state: {},
          overlayActive: !1,
          setSetting: function setSetting(t, e) {
            return this.setOption(t, e);
          },
          getSetting: function getSetting(t) {
            return this.getOption(t);
          },
          setOption: function setOption(t, e) {
            this.config[t] = e;
          },
          getOption: function getOption(t) {
            return this.config[t];
          },
          l: function l(t) {
            return t;
          },
          requireJs: function requireJs(t, e, n) {
            this.isJsLoaded(t) || o.util.loadScript(e, n), this.loadedJsLibs[t] = e;
          },
          isJsLoaded: function isJsLoaded(t) {
            if (this.loadedJsLibs.hasOwnProperty(t)) return !0;
          },
          initializeStateManager: function initializeStateManager() {
            this.state.hasOwnProperty("init") || (o.debug("initializing state manager..."), this.state = new o.stateManager());
          },
          registerStateStore: function registerStateStore(t, e, n, r) {
            return this.initializeStateManager(), this.state.registerStore(t, e, n, r);
          },
          checkForState: function checkForState(t) {
            return this.initializeStateManager(), this.state.isPresent(t);
          },
          setState: function setState(t, e, n, r, o, i) {
            return this.initializeStateManager(), this.state.set(t, e, n, r, o, i);
          },
          replaceState: function replaceState(t, e, n, r, o) {
            return this.initializeStateManager(), this.state.replaceStore(t, e, n, r, o);
          },
          getStateFromCookie: function getStateFromCookie(t) {
            return this.initializeStateManager(), this.state.getStateFromCookie(t);
          },
          getState: function getState(t, e) {
            return this.initializeStateManager(), this.state.get(t, e);
          },
          clearState: function clearState(t, e) {
            return this.initializeStateManager(), this.state.clear(t, e);
          },
          getStateStoreFormat: function getStateStoreFormat(t) {
            return this.initializeStateManager(), this.state.getStoreFormat(t);
          },
          setStateStoreFormat: function setStateStoreFormat(t, e) {
            return this.initializeStateManager(), this.state.setStoreFormat(t, e);
          },
          debug: function debug() {
            var t = o.getSetting("debug") || !1;
            t && window.console && console.log.apply && (window.console.firebug ? console.log.apply(this, arguments) : console.log.apply(console, arguments));
          },
          setApiEndpoint: function setApiEndpoint(t) {
            this.config.rest_api_endpoint = t;
          },
          getApiEndpoint: function getApiEndpoint() {
            return this.config.rest_api_endpoint || this.getSetting("baseUrl") + "api/";
          },
          loadHeatmap: function loadHeatmap(t) {
            var e = this;
            o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/includes/jquery/jquery-1.6.4.min.js", function () {}), o.util.loadCss(o.getSetting("baseUrl") + "/modules/base/css/owa.overlay.css", function () {}), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/owa.heatmap.js", function () {
              e.overlay = new o.heatmap(), e.overlay.options.liveMode = !0, e.overlay.generate();
            });
          },
          loadPlayer: function loadPlayer() {
            var t = this;
            o.debug("Loading Domstream Player"), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/includes/jquery/jquery-1.6.4.min.js", function () {}), o.util.loadCss(o.getSetting("baseUrl") + "/modules/base/css/owa.overlay.css", function () {}), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/owa.player.js", function () {
              t.overlay = new o.player();
            });
          },
          startOverlaySession: function startOverlaySession(t) {
            o.overlayActive = !0, t.hasOwnProperty("api_url") && o.setApiEndpoint(t.api_url);
            var e = t;
            "loadHeatmap" === e.action ? this.loadHeatmap(t) : "loadPlayer" === e.action && this.loadPlayer(t);
          },
          endOverlaySession: function endOverlaySession() {
            o.util.eraseCookie(o.getSetting("ns") + "overlay", document.domain), o.overlayActive = !1;
          },
          addFilter: function addFilter(t, e, n) {
            void 0 === n && (n = 10), this.hooks.filters[t] = this.hooks.filters[t] || [], this.hooks.filters[t].push({
              priority: n,
              callback: e
            });
          },
          addAction: function addAction(t, e, n) {
            o.debug("Adding Action callback for: " + t), void 0 === n && (n = 10), this.hooks.actions[t] = this.hooks.actions[t] || [], this.hooks.actions[t].push({
              priority: n,
              callback: e
            });
          },
          applyFilters: function applyFilters(t, e, n) {
            o.debug("Filtering " + t + " with value:"), o.debug(e);
            var r = [];
            return void 0 !== this.hooks.filters[t] && this.hooks.filters[t].length > 0 && (o.debug("Applying filters for " + t), this.hooks.filters[t].forEach(function (t) {
              r[t.priority] = r[t.priority] || [], r[t.priority].push(t.callback);
            }), r.forEach(function (t) {
              t.forEach(function (t) {
                e = t(e, n), o.debug("Filter returned value: "), o.debug(e);
              });
            })), e;
          },
          doAction: function doAction(t, e) {
            o.debug("Doing Action: " + t);
            var n = [];
            void 0 !== this.hooks.actions[t] && this.hooks.actions[t].length > 0 && (o.debug(this.hooks.actions[t]), this.hooks.actions[t].forEach(function (t) {
              n[t.priority] = n[t.priority] || [], n[t.priority].push(t.callback);
            }), n.forEach(function (n) {
              o.debug("Executing Action callabck for: " + t), n.forEach(function (t) {
                t(e);
              });
            }));
          },
          removeAction: function removeAction(t, e) {
            this.hooks.actions[t] = this.hooks.actions[t] || [], this.hooks.actions[t].forEach(function (n, r) {
              n.callback === e && this.hooks.actions[t].splice(r, 1);
            });
          },
          removeFilter: function removeFilter(t, e) {
            this.hooks.filters[t] = this.hooks.filters[t] || [], this.hooks.filters[t].forEach(function (e, n) {
              e.callback === callback && this.hooks.filters[t].splice(n, 1);
            });
          },
          stateManager: function stateManager() {
            this.cookies = o.util.readAllCookies(), this.init = !0;
          }
        };
        o.stateManager.prototype = {
          init: !1,
          cookies: "",
          stores: {},
          storeFormats: {},
          storeMeta: {},
          registerStore: function registerStore(t, e, n, r) {
            this.storeMeta[t] = {
              expiration: e,
              length: n,
              format: r
            };
          },
          getExpirationDays: function getExpirationDays(t) {
            if (this.storeMeta.hasOwnProperty(t)) return this.storeMeta[t].expiration;
          },
          getFormat: function getFormat(t) {
            if (this.storeMeta.hasOwnProperty(t)) return this.storeMeta[t].format;
          },
          isPresent: function isPresent(t) {
            if (this.stores.hasOwnProperty(t)) return !0;
          },
          set: function set(t, e, n, r, i, s) {
            this.isPresent(t) || this.load(t), this.isPresent(t) || (o.debug("Creating state store (%s)", t), this.stores[t] = {}, o.getSetting("hashCookiesToDomain") && (this.stores[t].cdh = o.util.getCookieDomainHash(o.getSetting("cookie_domain")))), e ? this.stores[t][e] = n : this.stores[t] = n, (i = this.getFormat(t)) || this.storeFormats.hasOwnProperty(t) && (i = this.storeFormats[t]);
            var a;
            a = "json" === i ? JSON.stringify(this.stores[t]) : o.util.assocStringFromJson(this.stores[t]), (s = this.getExpirationDays(t)) || r && (s = 364), o.debug("Populating state store (%s) with value: %s", t, a);
            var u = o.getSetting("cookie_domain") || document.domain;
            o.util.setCookie(o.getSetting("ns") + t, a, s, "/", u);
          },
          replaceStore: function replaceStore(t, e, n, r, i) {
            if (o.debug("replace state format: %s, value: %s", r, JSON.stringify(e)), t) {
              e && (r = this.getFormat(t), this.stores[t] = e, this.storeFormats[t] = r, cookie_value = "json" === r ? JSON.stringify(e) : o.util.assocStringFromJson(e));
              var s = o.getSetting("cookie_domain") || document.domain;
              i = this.getExpirationDays(t), o.debug("About to replace state store (%s) with: %s", t, cookie_value), o.util.setCookie(o.getSetting("ns") + t, cookie_value, i, "/", s);
            }
          },
          getStateFromCookie: function getStateFromCookie(t) {
            var e = unescape(o.util.readCookie(o.getSetting("ns") + t));
            if (e) return e;
          },
          get: function get(t, e) {
            return this.isPresent(t) || this.load(t), this.isPresent(t) ? e ? this.stores[t].hasOwnProperty(e) ? this.stores[t][e] : void 0 : this.stores[t] : (o.debug("No state store (%s) was found", t), "");
          },
          getCookieValues: function getCookieValues(t) {
            if (this.cookies.hasOwnProperty(t)) return this.cookies[t];
          },
          load: function load(t) {
            var e = "",
                n = this.getCookieValues(o.getSetting("ns") + t);
            if (n) for (var r = 0; r < n.length; r++) {
              var i = unescape(n[r]),
                  s = o.util.decodeCookieValue(i),
                  a = o.util.getCookieValueFormat(i);

              if (o.getSetting("hashCookiesToDomain")) {
                var u = o.getSetting("cookie_domain"),
                    c = o.util.getCookieDomainHash(u);

                if (s.hasOwnProperty("cdh")) {
                  if (o.debug("Cookie value cdh: %s, domain hash: %s", s.cdh, c), s.cdh == c) {
                    o.debug("Cookie: %s, index: %s domain hash matches current cookie domain. Loading...", t, r), e = s;
                    break;
                  }

                  o.debug("Cookie: %s, index: %s domain hash does not match current cookie domain. Not loading.", t, r);
                } else o.debug("Cookie: %s, index: %s has no domain hash. Not going to Load it.", t, r);
              } else r === n.length - 1 && (e = s);
            }
            e ? (this.stores[t] = e, this.storeFormats[t] = a, o.debug("Loaded state store: %s with: %s", t, JSON.stringify(e))) : o.debug("No state for store: %s was found. Nothing to Load.", t);
          },
          clear: function clear(t, e) {
            if (e) {
              var n = this.get(t);
              n && n.hasOwnProperty(e) && (delete n.key, this.replaceStore(t, n, !0, this.getFormat(t), this.getExpirationDays(t)));
            } else delete this.stores[t], o.util.eraseCookie(o.getSetting("ns") + t), this.cookies = o.util.readAllCookies();
          },
          getStoreFormat: function getStoreFormat(t) {
            return this.getFormat(t);
          },
          setStoreFormat: function setStoreFormat(t, e) {
            this.storeFormats[t] = e;
          }
        }, o.uri = function (t) {
          this.components = {}, this.dirty = !1, this.options = {
            strictMode: !1,
            key: ["source", "protocol", "authority", "userInfo", "user", "password", "host", "port", "relative", "path", "directory", "file", "query", "anchor"],
            q: {
              name: "queryKey",
              parser: /(?:^|&)([^&=]*)=?([^&]*)/g
            },
            parser: {
              strict: /^(?:([^:\/?#]+):)?(?:\/\/((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?))?((((?:[^?#\/]*\/)*)([^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
              loose: /^(?:(?![^:@]+:[^:@\/]*@)([^:\/?#.]+):)?(?:\/\/)?((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?)(((\/(?:[^?#](?![^?#\/]*\.[^?#\/.]+(?:[?#]|$)))*\/?)?([^?#\/]*))(?:\?([^#]*))?(?:#(.*))?)/
            }
          }, t && (this.components = this.parseUri(t));
        }, o.uri.prototype = {
          parseUri: function parseUri(t) {
            for (var e = this.options, n = e.parser[e.strictMode ? "strict" : "loose"].exec(t), r = {}, o = 14; o--;) {
              r[e.key[o]] = n[o] || "";
            }

            return r[e.q.name] = {}, r[e.key[12]].replace(e.q.parser, function (t, n, o) {
              n && (r[e.q.name][n] = o);
            }), r;
          },
          getHost: function getHost() {
            if (this.components.hasOwnProperty("host")) return this.components.host;
          },
          getQueryParam: function getQueryParam(t) {
            if (this.components.hasOwnProperty("queryKey") && this.components.queryKey.hasOwnProperty(t)) return o.util.urldecode(this.components.queryKey[t]);
          },
          isQueryParam: function isQueryParam(t) {
            return !(!this.components.hasOwnProperty("queryKey") || !this.components.queryKey.hasOwnProperty(t));
          },
          getComponent: function getComponent(t) {
            if (this.components.hasOwnProperty(t)) return this.components[t];
          },
          getProtocol: function getProtocol() {
            return this.getComponent("protocol");
          },
          getAnchor: function getAnchor() {
            return this.getComponent("anchor");
          },
          getQuery: function getQuery() {
            return this.getComponent("query");
          },
          getFile: function getFile() {
            return this.getComponent("file");
          },
          getRelative: function getRelative() {
            return this.getComponent("relative");
          },
          getDirectory: function getDirectory() {
            return this.getComponent("directory");
          },
          getPath: function getPath() {
            return this.getComponent("path");
          },
          getPort: function getPort() {
            return this.getComponent("port");
          },
          getPassword: function getPassword() {
            return this.getComponent("password");
          },
          getUser: function getUser() {
            return this.getComponent("user");
          },
          getUserInfo: function getUserInfo() {
            return this.getComponent("userInfo");
          },
          getQueryParams: function getQueryParams() {
            return this.getComponent("queryKey");
          },
          getSource: function getSource() {
            return this.getComponent("source");
          },
          setQueryParam: function setQueryParam(t, e) {
            this.components.hasOwnProperty("queryKey") || (this.components.queryKey = {}), this.components.queryKey[t] = o.util.urlEncode(e), this.resetQuery();
          },
          removeQueryParam: function removeQueryParam(t) {
            this.components.hasOwnProperty("queryKey") && this.components.queryKey.hasOwnProperty(t) && (delete this.components.queryKey[t], this.resetQuery());
          },
          resetSource: function resetSource() {
            this.components.source = this.assembleUrl();
          },
          resetQuery: function resetQuery() {
            var t = this.getQueryParams();

            if (t) {
              var e = "",
                  n = o.util.countObjectProperties(t);

              for (var r in t) {
                e += r + "=" + t[r], 1 < n && (e += "&");
              }

              this.components.query = e, this.resetSource();
            }
          },
          isDirty: function isDirty() {
            return this.dirty;
          },
          setPath: function setPath(t) {},
          assembleUrl: function assembleUrl() {
            var t = "";
            t += this.getProtocol(), t += "://", this.getUser() && (t += this.getUser()), this.getUser() && this.getPassword() && (t += ":" + this.password()), t += this.getHost(), this.getPort() && (t += ":" + this.getPort()), t += this.getDirectory(), t += this.getFile();
            var e = this.getQuery();
            e && (t += "?" + e);
            var n = this.getAnchor();
            return n && (t += "#" + n), t + this.getAnchor();
          }
        }, o.util = {
          ns: function ns(t) {
            return o.config.ns + t;
          },
          nsAll: function nsAll(t) {
            var e = new Object();

            for (param in t) {
              t.hasOwnProperty(param) && (e[o.config.ns + param] = t[param]);
            }

            return e;
          },
          getScript: function getScript(t, e) {
            jQuery.getScript(e + t);
          },
          makeUrl: function makeUrl(t, e, n) {
            return jQuery.sprintf(t, e, jQuery.param(o.util.nsAll(n)));
          },
          createCookie: function createCookie(t, e, n, r) {
            if (n) {
              var o = new Date();
              o.setTime(o.getTime() + 24 * n * 60 * 60 * 1e3);
              var i = "; expires=" + o.toGMTString();
            } else i = "";

            document.cookie = t + "=" + e + i + "; path=/";
          },
          setCookie: function setCookie(t, e, n, r, o, i) {
            var s = new Date();
            s.setTime(s.getTime() + 24 * n * 60 * 60 * 1e3), document.cookie = t + "=" + escape(e) + (n ? "; expires=" + s.toGMTString() : "") + (r ? "; path=" + r : "") + (o ? "; domain=" + o : "") + (i ? "; secure" : "");
          },
          readAllCookies: function readAllCookies() {
            o.debug("Reading all cookies...");
            var t = {},
                e = document.cookie.split(";");

            if (e) {
              o.debug(document.cookie);

              for (var n = 0; n < e.length; n++) {
                var r = o.util.trim(e[n]),
                    i = o.util.strpos(r, "="),
                    s = r.substring(0, i),
                    a = r.substring(i + 1, r.length);
                t.hasOwnProperty(s) || (t[s] = []), t[s].push(a);
              }

              return o.debug(JSON.stringify(t)), t;
            }
          },
          readCookie: function readCookie(t) {
            o.debug("Attempting to read cookie: %s", t);
            var e = o.util.readAllCookies();
            if (e) return e.hasOwnProperty(t) ? e[t] : "";
          },
          eraseCookie: function eraseCookie(t, e) {
            if (o.debug(document.cookie), e || (e = o.getSetting("cookie_domain") || document.domain), o.debug("erasing cookie: " + t + " in domain: " + e), this.setCookie(t, "", -1, "/", e), o.util.readCookie(t)) {
              var n = e.substr(0, 1);

              if (o.debug("period: " + n), "." === n) {
                var r = e.substr(1);
                o.debug("erasing " + t + " in domain2: " + r), this.setCookie(t, "", -2, "/", r);
              } else o.debug("erasing " + t + " in domain3: " + e), this.setCookie(t, "", -2, "/", e);
            }
          },
          eraseMultipleCookies: function eraseMultipleCookies(t, e) {
            for (var n = 0; n < t.length; n++) {
              this.eraseCookie(t[n], e);
            }
          },
          loadScript: function loadScript(t, e) {
            var n = document.createElement("script");
            n.type = "text/javascript", n.readyState ? n.onreadystatechange = function () {
              "loaded" != n.readyState && "complete" != n.readyState || (n.onreadystatechange = null, e());
            } : n.onload = function () {
              e();
            }, n.src = t, document.getElementsByTagName("head")[0].appendChild(n);
          },
          loadCss: function loadCss(t, e) {
            var n = document.createElement("link");
            n.rel = "stylesheet", n.type = "text/css", n.href = t, document.getElementsByTagName("HEAD")[0].appendChild(n);
          },
          parseCookieString: function parseCookieString(t) {
            var e = new Array(),
                n = unescape(t).split("|||");

            for (var r in n) {
              if (n.hasOwnProperty(r)) {
                var o = n[r].split("=>");
                e[o[0]] = o[1];
              }
            }

            return e;
          },
          parseCookieStringToJson: function parseCookieStringToJson(t) {
            var e = new Object(),
                n = unescape(t).split("|||");

            for (var r in n) {
              if (n.hasOwnProperty(r)) {
                var o = n[r].split("=>");
                e[o[0]] = o[1];
              }
            }

            return e;
          },
          nsParams: function nsParams(t) {
            var e = new Object();

            for (param in t) {
              t.hasOwnProperty(param) && (e[o.getSetting("ns") + param] = t[param]);
            }

            return e;
          },
          urlEncode: function urlEncode(t) {
            return t = (t + "").toString(), encodeURIComponent(t).replace(/!/g, "%21").replace(/'/g, "%27").replace(/\(/g, "%28").replace(/\)/g, "%29").replace(/\*/g, "%2A").replace(/%20/g, "+");
          },
          urldecode: function urldecode(t) {
            return decodeURIComponent(t.replace(/\+/g, "%20"));
          },
          parseUrlParams: function parseUrlParams(t) {
            for (var e, n, r, o, i, s, a = {}, u = location.href.split(/[?&]/), c = u.length, l = 1; l < c; l++) {
              if ((r = u[l].match(/(.*?)(\..*?|\[.*?\])?=([^#]*)/)) && 4 == r.length) {
                if (o = decodeURI(r[1]).toLowerCase(), i = a, s = decodeURI(r[3]), r[2]) for (n = decodeURI(r[2]).replace(/\[\s*\]/g, "[-1]").split(/[\.\[\]]/), e = 0; e < n.length; e++) {
                  i = i[o] ? i[o] : i[o] = parseInt(n[e]) == n[e] ? [] : {}, o = n[e].replace(/^["\'](.*)["\']$/, "$1");
                }
                "-1" != o ? i[o] = s : i[i.length] = s;
              }
            }

            return a;
          },
          strpos: function strpos(t, e, n) {
            var r = (t + "").indexOf(e, n || 0);
            return -1 !== r && r;
          },
          strCountOccurances: function strCountOccurances(t, e) {
            return t.split(e).length - 1;
          },
          implode: function implode(t, e) {
            var n = "",
                o = "",
                i = "";

            if (1 === arguments.length && (e = t, t = ""), "object" === r(e)) {
              if (e instanceof Array) return e.join(t);

              for (n in e) {
                o += i + e[n], i = t;
              }

              return o;
            }

            return e;
          },
          checkForState: function checkForState(t) {
            return o.checkForState(t);
          },
          setState: function setState(t, e, n, r, i, s) {
            return o.setState(t, e, n, r, i, s);
          },
          replaceState: function replaceState(t, e, n, r, i) {
            return o.replaceState(t, e, n, r, i);
          },
          getRawState: function getRawState(t) {
            return o.getStateFromCookie(t);
          },
          getState: function getState(t, e) {
            return o.getState(t, e);
          },
          clearState: function clearState(t, e) {
            return o.clearState(t, e);
          },
          getCookieValueFormat: function getCookieValueFormat(t) {
            return "{" === t.substr(0, 1) ? "json" : "assoc";
          },
          decodeCookieValue: function decodeCookieValue(t) {
            var e,
                n = o.util.getCookieValueFormat(t);
            return e = "json" === n ? JSON.parse(t) : o.util.jsonFromAssocString(t), o.debug("decodeCookieValue - string: %s, format: %s, value: %s", t, n, JSON.stringify(e)), e;
          },
          encodeJsonForCookie: function encodeJsonForCookie(t, e) {
            return "json" === (e = e || "assoc") ? JSON.stringify(t) : o.util.assocStringFromJson(t);
          },
          getCookieDomainHash: function getCookieDomainHash(t) {
            return o.util.dechex(o.util.crc32(t));
          },
          loadStateJson: function loadStateJson(t) {
            var e = unescape(o.util.readCookie(o.getSetting("ns") + t));
            e && (state = JSON.parse(e)), o.state[t] = state, o.debug("state store %s: %s", t, JSON.stringify(state));
          },
          is_array: function is_array(t) {
            return "object" == r(t) && t instanceof Array;
          },
          str_pad: function str_pad(t, e, n, r) {
            var o,
                i = "",
                s = function s(t, e) {
              for (var n = ""; n.length < e;) {
                n += t;
              }

              return n.substr(0, e);
            };

            return n = void 0 !== n ? n : " ", "STR_PAD_LEFT" != r && "STR_PAD_RIGHT" != r && "STR_PAD_BOTH" != r && (r = "STR_PAD_RIGHT"), (o = e - (t += "").length) > 0 && ("STR_PAD_LEFT" == r ? t = s(n, o) + t : "STR_PAD_RIGHT" == r ? t += s(n, o) : "STR_PAD_BOTH" == r && (t = (t = (i = s(n, Math.ceil(o / 2))) + t + i).substr(0, e))), t;
          },
          zeroFill: function zeroFill(t, e) {
            return o.util.str_pad(t, e, "0", "STR_PAD_LEFT");
          },
          is_object: function is_object(t) {
            return !(t instanceof Array) && null !== t && "object" == r(t);
          },
          countObjectProperties: function countObjectProperties(t) {
            var e,
                n = 0;

            for (e in t) {
              t.hasOwnProperty(e) && n++;
            }

            return n;
          },
          jsonFromAssocString: function jsonFromAssocString(t, e, n) {
            if (e = e || "=>", n = n || "|||", t) {
              if (!this.strpos(t, e)) return t;

              for (var r = {}, o = t.split(n), i = 0, s = o.length; i < s; i++) {
                var a = o[i].split(e);
                r[a[0]] = a[1];
              }

              return r;
            }
          },
          assocStringFromJson: function assocStringFromJson(t) {
            var e = "",
                n = 0,
                r = o.util.countObjectProperties(t);

            for (var i in t) {
              n++, e += i + "=>" + t[i], n < r && (e += "|||");
            }

            return e;
          },
          getDomainFromUrl: function getDomainFromUrl(t, e) {
            var n = t.split(/\/+/g)[1];
            return !0 === e ? o.util.stripWwwFromDomain(n) : n;
          },
          stripWwwFromDomain: function stripWwwFromDomain(t) {
            return "www" === t.split(".")[0] ? t.substring(4) : t;
          },
          getCurrentUnixTimestamp: function getCurrentUnixTimestamp() {
            return Math.round(new Date().getTime() / 1e3);
          },
          generateHash: function generateHash(t) {
            return this.crc32(t);
          },
          generateRandomGuid: function generateRandomGuid() {
            return this.getCurrentUnixTimestamp() + "" + o.util.zeroFill(this.rand(0, 999999) + "", 6) + o.util.zeroFill(this.rand(0, 999) + "", 3);
          },
          crc32: function crc32(t) {
            var e = 0,
                n = 0;
            e ^= -1;

            for (var r = 0, o = (t = this.utf8_encode(t)).length; r < o; r++) {
              n = 255 & (e ^ t.charCodeAt(r)), e = e >>> 8 ^ "0x" + "00000000 77073096 EE0E612C 990951BA 076DC419 706AF48F E963A535 9E6495A3 0EDB8832 79DCB8A4 E0D5E91E 97D2D988 09B64C2B 7EB17CBD E7B82D07 90BF1D91 1DB71064 6AB020F2 F3B97148 84BE41DE 1ADAD47D 6DDDE4EB F4D4B551 83D385C7 136C9856 646BA8C0 FD62F97A 8A65C9EC 14015C4F 63066CD9 FA0F3D63 8D080DF5 3B6E20C8 4C69105E D56041E4 A2677172 3C03E4D1 4B04D447 D20D85FD A50AB56B 35B5A8FA 42B2986C DBBBC9D6 ACBCF940 32D86CE3 45DF5C75 DCD60DCF ABD13D59 26D930AC 51DE003A C8D75180 BFD06116 21B4F4B5 56B3C423 CFBA9599 B8BDA50F 2802B89E 5F058808 C60CD9B2 B10BE924 2F6F7C87 58684C11 C1611DAB B6662D3D 76DC4190 01DB7106 98D220BC EFD5102A 71B18589 06B6B51F 9FBFE4A5 E8B8D433 7807C9A2 0F00F934 9609A88E E10E9818 7F6A0DBB 086D3D2D 91646C97 E6635C01 6B6B51F4 1C6C6162 856530D8 F262004E 6C0695ED 1B01A57B 8208F4C1 F50FC457 65B0D9C6 12B7E950 8BBEB8EA FCB9887C 62DD1DDF 15DA2D49 8CD37CF3 FBD44C65 4DB26158 3AB551CE A3BC0074 D4BB30E2 4ADFA541 3DD895D7 A4D1C46D D3D6F4FB 4369E96A 346ED9FC AD678846 DA60B8D0 44042D73 33031DE5 AA0A4C5F DD0D7CC9 5005713C 270241AA BE0B1010 C90C2086 5768B525 206F85B3 B966D409 CE61E49F 5EDEF90E 29D9C998 B0D09822 C7D7A8B4 59B33D17 2EB40D81 B7BD5C3B C0BA6CAD EDB88320 9ABFB3B6 03B6E20C 74B1D29A EAD54739 9DD277AF 04DB2615 73DC1683 E3630B12 94643B84 0D6D6A3E 7A6A5AA8 E40ECF0B 9309FF9D 0A00AE27 7D079EB1 F00F9344 8708A3D2 1E01F268 6906C2FE F762575D 806567CB 196C3671 6E6B06E7 FED41B76 89D32BE0 10DA7A5A 67DD4ACC F9B9DF6F 8EBEEFF9 17B7BE43 60B08ED5 D6D6A3E8 A1D1937E 38D8C2C4 4FDFF252 D1BB67F1 A6BC5767 3FB506DD 48B2364B D80D2BDA AF0A1B4C 36034AF6 41047A60 DF60EFC3 A867DF55 316E8EEF 4669BE79 CB61B38C BC66831A 256FD2A0 5268E236 CC0C7795 BB0B4703 220216B9 5505262F C5BA3BBE B2BD0B28 2BB45A92 5CB36A04 C2D7FFA7 B5D0CF31 2CD99E8B 5BDEAE1D 9B64C2B0 EC63F226 756AA39C 026D930A 9C0906A9 EB0E363F 72076785 05005713 95BF4A82 E2B87A14 7BB12BAE 0CB61B38 92D28E9B E5D5BE0D 7CDCEFB7 0BDBDF21 86D3D2D4 F1D4E242 68DDB3F8 1FDA836E 81BE16CD F6B9265B 6FB077E1 18B74777 88085AE6 FF0F6A70 66063BCA 11010B5C 8F659EFF F862AE69 616BFFD3 166CCF45 A00AE278 D70DD2EE 4E048354 3903B3C2 A7672661 D06016F7 4969474D 3E6E77DB AED16A4A D9D65ADC 40DF0B66 37D83BF0 A9BCAE53 DEBB9EC5 47B2CF7F 30B5FFE9 BDBDF21C CABAC28A 53B39330 24B4A3A6 BAD03605 CDD70693 54DE5729 23D967BF B3667A2E C4614AB8 5D681B02 2A6F2B94 B40BBE37 C30C8EA1 5A05DF1B 2D02EF8D".substr(9 * n, 8);
            }

            return -1 ^ e;
          },
          utf8_encode: function utf8_encode(t) {
            var e,
                n,
                r,
                o = t + "",
                i = "";
            e = n = 0, r = o.length;

            for (var s = 0; s < r; s++) {
              var a = o.charCodeAt(s),
                  u = null;
              a < 128 ? n++ : u = a > 127 && a < 2048 ? String.fromCharCode(a >> 6 | 192) + String.fromCharCode(63 & a | 128) : String.fromCharCode(a >> 12 | 224) + String.fromCharCode(a >> 6 & 63 | 128) + String.fromCharCode(63 & a | 128), null !== u && (n > e && (i += o.substring(e, n)), i += u, e = n = s + 1);
            }

            return n > e && (i += o.substring(e, o.length)), i;
          },
          utf8_decode: function utf8_decode(t) {
            var e = [],
                n = 0,
                r = 0,
                o = 0,
                i = 0,
                s = 0;

            for (t += ""; n < t.length;) {
              (o = t.charCodeAt(n)) < 128 ? (e[r++] = String.fromCharCode(o), n++) : o > 191 && o < 224 ? (i = t.charCodeAt(n + 1), e[r++] = String.fromCharCode((31 & o) << 6 | 63 & i), n += 2) : (i = t.charCodeAt(n + 1), s = t.charCodeAt(n + 2), e[r++] = String.fromCharCode((15 & o) << 12 | (63 & i) << 6 | 63 & s), n += 3);
            }

            return e.join("");
          },
          trim: function trim(t, e) {
            var n,
                r = 0,
                o = 0;

            for (t += "", n = e ? (e += "").replace(/([\[\]\(\)\.\?\/\*\{\}\+\$\^\:])/g, "$1") : " \n\r\t\f\x0B\xA0\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200A\u200B\u2028\u2029\u3000", r = t.length, o = 0; o < r; o++) {
              if (-1 === n.indexOf(t.charAt(o))) {
                t = t.substring(o);
                break;
              }
            }

            for (o = (r = t.length) - 1; o >= 0; o--) {
              if (-1 === n.indexOf(t.charAt(o))) {
                t = t.substring(0, o + 1);
                break;
              }
            }

            return -1 === n.indexOf(t.charAt(0)) ? t : "";
          },
          rand: function rand(t, e) {
            var n = arguments.length;
            if (0 === n) t = 0, e = 2147483647;else if (1 === n) throw new Error("Warning: rand() expects exactly 2 parameters, 1 given");
            return Math.floor(Math.random() * (e - t + 1)) + t;
          },
          base64_encode: function base64_encode(t) {
            var e,
                n,
                r,
                o,
                i,
                s = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
                a = 0,
                u = 0,
                c = "",
                l = [];
            if (!t) return t;
            t = this.utf8_encode(t + "");

            do {
              e = (i = t.charCodeAt(a++) << 16 | t.charCodeAt(a++) << 8 | t.charCodeAt(a++)) >> 18 & 63, n = i >> 12 & 63, r = i >> 6 & 63, o = 63 & i, l[u++] = s.charAt(e) + s.charAt(n) + s.charAt(r) + s.charAt(o);
            } while (a < t.length);

            switch (c = l.join(""), t.length % 3) {
              case 1:
                c = c.slice(0, -2) + "==";
                break;

              case 2:
                c = c.slice(0, -1) + "=";
            }

            return c;
          },
          base64_decode: function base64_decode(t) {
            var e,
                n,
                r,
                o,
                i,
                s,
                a = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
                u = 0,
                c = 0,
                l = "",
                h = [];
            if (!t) return t;
            t += "";

            do {
              e = (s = a.indexOf(t.charAt(u++)) << 18 | a.indexOf(t.charAt(u++)) << 12 | (o = a.indexOf(t.charAt(u++))) << 6 | (i = a.indexOf(t.charAt(u++)))) >> 16 & 255, n = s >> 8 & 255, r = 255 & s, h[c++] = 64 == o ? String.fromCharCode(e) : 64 == i ? String.fromCharCode(e, n) : String.fromCharCode(e, n, r);
            } while (u < t.length);

            return l = h.join(""), this.utf8_decode(l);
          },
          sprintf: function sprintf() {
            var t = /%%|%(\d+\$)?([-+\'#0 ]*)(\*\d+\$|\*|\d+)?(\.(\*\d+\$|\*|\d+))?([scboxXuidfegEG])/g,
                e = arguments,
                n = 0,
                r = e[n++],
                o = function o(t, e, n, r) {
              n || (n = " ");
              var o = t.length >= e ? "" : Array(1 + e - t.length >>> 0).join(n);
              return r ? t + o : o + t;
            },
                i = function i(t, e, n, r, _i2, s) {
              var a = r - t.length;
              return a > 0 && (t = n || !_i2 ? o(t, r, s, n) : t.slice(0, e.length) + o("", a, "0", !0) + t.slice(e.length)), t;
            },
                s = function s(t, e, n, r, _s2, a, u) {
              var c = t >>> 0;
              return t = (n = n && c && {
                2: "0b",
                8: "0",
                16: "0x"
              }[e] || "") + o(c.toString(e), a || 0, "0", !1), i(t, n, r, _s2, u);
            },
                a = function a(t, e, n, r, o, s) {
              return null != r && (t = t.slice(0, r)), i(t, "", e, n, o, s);
            },
                u = function u(t, r, _u2, c, l, h, g) {
              var f, d, p, m, C;
              if ("%%" == t) return "%";

              for (var A = !1, y = "", D = !1, v = !1, S = " ", b = _u2.length, E = 0; _u2 && E < b; E++) {
                switch (_u2.charAt(E)) {
                  case " ":
                    y = " ";
                    break;

                  case "+":
                    y = "+";
                    break;

                  case "-":
                    A = !0;
                    break;

                  case "'":
                    S = _u2.charAt(E + 1);
                    break;

                  case "0":
                    D = !0;
                    break;

                  case "#":
                    v = !0;
                }
              }

              if ((c = c ? "*" == c ? +e[n++] : "*" == c.charAt(0) ? +e[c.slice(1, -1)] : +c : 0) < 0 && (c = -c, A = !0), !isFinite(c)) throw new Error("sprintf: (minimum-)width must be finite");

              switch (h = h ? "*" == h ? +e[n++] : "*" == h.charAt(0) ? +e[h.slice(1, -1)] : +h : "fFeE".indexOf(g) > -1 ? 6 : "d" == g ? 0 : void 0, C = r ? e[r.slice(0, -1)] : e[n++], g) {
                case "s":
                  return a(String(C), A, c, h, D, S);

                case "c":
                  return a(String.fromCharCode(+C), A, c, h, D);

                case "b":
                  return s(C, 2, v, A, c, h, D);

                case "o":
                  return s(C, 8, v, A, c, h, D);

                case "x":
                  return s(C, 16, v, A, c, h, D);

                case "X":
                  return s(C, 16, v, A, c, h, D).toUpperCase();

                case "u":
                  return s(C, 10, v, A, c, h, D);

                case "i":
                case "d":
                  return C = (d = (f = parseInt(+C, 10)) < 0 ? "-" : y) + o(String(Math.abs(f)), h, "0", !1), i(C, d, A, c, D);

                case "e":
                case "E":
                case "f":
                case "F":
                case "g":
                case "G":
                  return d = (f = +C) < 0 ? "-" : y, p = ["toExponential", "toFixed", "toPrecision"]["efg".indexOf(g.toLowerCase())], m = ["toString", "toUpperCase"]["eEfFgG".indexOf(g) % 2], C = d + Math.abs(f)[p](h), i(C, d, A, c, D)[m]();

                default:
                  return t;
              }
            };

            return r.replace(t, u);
          },
          clone: function clone(t) {
            var e = t instanceof Array ? [] : {};

            for (var n in t) {
              t[n] && "object" == r(t[n]) ? e[n] = o.util.clone(t[n]) : e[n] = t[n];
            }

            return e;
          },
          strtolower: function strtolower(t) {
            return (t + "").toLowerCase();
          },
          in_array: function in_array(t, e, n) {
            var r = "";

            if (n) {
              for (r in e) {
                if (e[r] === t) return !0;
              }
            } else for (r in e) {
              if (e[r] == t) return !0;
            }

            return !1;
          },
          dechex: function dechex(t) {
            return t < 0 && (t = 4294967295 + t + 1), parseInt(t, 10).toString(16);
          },
          explode: function explode(t, e, n) {
            var o = {
              0: ""
            };
            if (arguments.length < 2 || void 0 === arguments[0] || void 0 === arguments[1]) return null;
            if ("" === t || !1 === t || null === t) return !1;
            if ("function" == typeof t || "object" == r(t) || "function" == typeof e || "object" == r(e)) return o;

            if (!0 === t && (t = "1"), n) {
              var i = e.toString().split(t.toString()),
                  s = i.splice(0, n - 1),
                  a = i.join(t.toString());
              return s.push(a), s;
            }

            return e.toString().split(t.toString());
          },
          isIE: function isIE() {
            if (/MSIE (\d+\.\d+);/.test(navigator.userAgent)) return !0;
          },
          getInternetExplorerVersion: function getInternetExplorerVersion() {
            var t = -1;

            if ("Microsoft Internet Explorer" == navigator.appName) {
              var e = navigator.userAgent;
              null != new RegExp("MSIE ([0-9]{1,}[.0-9]{0,})").exec(e) && (t = parseFloat(RegExp.$1));
            }

            return t;
          },
          isBrowserTrackable: function isBrowserTrackable() {
            for (var t = ["doNotTrack", "msDoNotTrack"], e = 0, n = t.length; e < n; e++) {
              if (navigator[t[e]] && "1" == navigator[t[e]]) return !1;
            }

            return !0;
          }
        };
      }, function (t, e, n) {
        n(0), n(2), n(10), t.exports = n(15);
      }, function (t, e) {
        function r(t) {
          return (r = "function" == typeof Symbol && "symbol" == n(Symbol.iterator) ? function (t) {
            return n(t);
          } : function (t) {
            return t && "function" == typeof Symbol && t.constructor === Symbol && t !== Symbol.prototype ? "symbol" : n(t);
          })(t);
        }

        !function (t) {
          var e = {};

          function n(r) {
            if (e[r]) return e[r].exports;
            var o = e[r] = {
              i: r,
              l: !1,
              exports: {}
            };
            return t[r].call(o.exports, o, o.exports, n), o.l = !0, o.exports;
          }

          n.m = t, n.c = e, n.d = function (t, e, r) {
            n.o(t, e) || Object.defineProperty(t, e, {
              enumerable: !0,
              get: r
            });
          }, n.r = function (t) {
            "undefined" != typeof Symbol && Symbol.toStringTag && Object.defineProperty(t, Symbol.toStringTag, {
              value: "Module"
            }), Object.defineProperty(t, "__esModule", {
              value: !0
            });
          }, n.t = function (t, e) {
            if (1 & e && (t = n(t)), 8 & e) return t;
            if (4 & e && "object" == r(t) && t && t.__esModule) return t;
            var o = Object.create(null);
            if (n.r(o), Object.defineProperty(o, "default", {
              enumerable: !0,
              value: t
            }), 2 & e && "string" != typeof t) for (var i in t) {
              n.d(o, i, function (e) {
                return t[e];
              }.bind(null, i));
            }
            return o;
          }, n.n = function (t) {
            var e = t && t.__esModule ? function () {
              return t["default"];
            } : function () {
              return t;
            };
            return n.d(e, "a", e), e;
          }, n.o = function (t, e) {
            return Object.prototype.hasOwnProperty.call(t, e);
          }, n.p = "/", n(n.s = 1);
        }({
          0: function _(t, e) {
            function n(t) {
              return (n = "function" == typeof Symbol && "symbol" == r(Symbol.iterator) ? function (t) {
                return r(t);
              } : function (t) {
                return t && "function" == typeof Symbol && t.constructor === Symbol && t !== Symbol.prototype ? "symbol" : r(t);
              })(t);
            }

            var o = {
              items: {},
              hooks: {
                actions: {},
                filters: {}
              },
              loadedJsLibs: {},
              overlay: "",
              config: {
                ns: "owa_",
                baseUrl: "",
                hashCookiesToDomain: !0,
                debug: !1
              },
              state: {},
              overlayActive: !1,
              setSetting: function setSetting(t, e) {
                return this.setOption(t, e);
              },
              getSetting: function getSetting(t) {
                return this.getOption(t);
              },
              setOption: function setOption(t, e) {
                this.config[t] = e;
              },
              getOption: function getOption(t) {
                return this.config[t];
              },
              l: function l(t) {
                return t;
              },
              requireJs: function requireJs(t, e, n) {
                this.isJsLoaded(t) || o.util.loadScript(e, n), this.loadedJsLibs[t] = e;
              },
              isJsLoaded: function isJsLoaded(t) {
                if (this.loadedJsLibs.hasOwnProperty(t)) return !0;
              },
              initializeStateManager: function initializeStateManager() {
                this.state.hasOwnProperty("init") || (o.debug("initializing state manager..."), this.state = new o.stateManager());
              },
              registerStateStore: function registerStateStore(t, e, n, r) {
                return this.initializeStateManager(), this.state.registerStore(t, e, n, r);
              },
              checkForState: function checkForState(t) {
                return this.initializeStateManager(), this.state.isPresent(t);
              },
              setState: function setState(t, e, n, r, o, i) {
                return this.initializeStateManager(), this.state.set(t, e, n, r, o, i);
              },
              replaceState: function replaceState(t, e, n, r, o) {
                return this.initializeStateManager(), this.state.replaceStore(t, e, n, r, o);
              },
              getStateFromCookie: function getStateFromCookie(t) {
                return this.initializeStateManager(), this.state.getStateFromCookie(t);
              },
              getState: function getState(t, e) {
                return this.initializeStateManager(), this.state.get(t, e);
              },
              clearState: function clearState(t, e) {
                return this.initializeStateManager(), this.state.clear(t, e);
              },
              getStateStoreFormat: function getStateStoreFormat(t) {
                return this.initializeStateManager(), this.state.getStoreFormat(t);
              },
              setStateStoreFormat: function setStateStoreFormat(t, e) {
                return this.initializeStateManager(), this.state.setStoreFormat(t, e);
              },
              debug: function debug() {
                var t = o.getSetting("debug") || !1;
                t && window.console && console.log.apply && (window.console.firebug ? console.log.apply(this, arguments) : console.log.apply(console, arguments));
              },
              setApiEndpoint: function setApiEndpoint(t) {
                this.config.rest_api_endpoint = t;
              },
              getApiEndpoint: function getApiEndpoint() {
                return this.config.rest_api_endpoint || this.getSetting("baseUrl") + "api/";
              },
              loadHeatmap: function loadHeatmap(t) {
                var e = this;
                o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/includes/jquery/jquery-1.6.4.min.js", function () {}), o.util.loadCss(o.getSetting("baseUrl") + "/modules/base/css/owa.overlay.css", function () {}), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/owa.heatmap.js", function () {
                  e.overlay = new o.heatmap(), e.overlay.options.liveMode = !0, e.overlay.generate();
                });
              },
              loadPlayer: function loadPlayer() {
                var t = this;
                o.debug("Loading Domstream Player"), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/includes/jquery/jquery-1.6.4.min.js", function () {}), o.util.loadCss(o.getSetting("baseUrl") + "/modules/base/css/owa.overlay.css", function () {}), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/owa.player.js", function () {
                  t.overlay = new o.player();
                });
              },
              startOverlaySession: function startOverlaySession(t) {
                o.overlayActive = !0, t.hasOwnProperty("api_url") && o.setApiEndpoint(t.api_url);
                var e = t;
                "loadHeatmap" === e.action ? this.loadHeatmap(t) : "loadPlayer" === e.action && this.loadPlayer(t);
              },
              endOverlaySession: function endOverlaySession() {
                o.util.eraseCookie(o.getSetting("ns") + "overlay", document.domain), o.overlayActive = !1;
              },
              addFilter: function addFilter(t, e, n) {
                void 0 === n && (n = 10), this.hooks.filters[t] = this.hooks.filters[t] || [], this.hooks.filters[t].push({
                  priority: n,
                  callback: e
                });
              },
              addAction: function addAction(t, e, n) {
                o.debug("Adding Action callback for: " + t), void 0 === n && (n = 10), this.hooks.actions[t] = this.hooks.actions[t] || [], this.hooks.actions[t].push({
                  priority: n,
                  callback: e
                });
              },
              applyFilters: function applyFilters(t, e, n) {
                o.debug("Filtering " + t + " with value:"), o.debug(e);
                var r = [];
                return void 0 !== this.hooks.filters[t] && this.hooks.filters[t].length > 0 && (o.debug("Applying filters for " + t), this.hooks.filters[t].forEach(function (t) {
                  r[t.priority] = r[t.priority] || [], r[t.priority].push(t.callback);
                }), r.forEach(function (t) {
                  t.forEach(function (t) {
                    e = t(e, n), o.debug("Filter returned value: "), o.debug(e);
                  });
                })), e;
              },
              doAction: function doAction(t, e) {
                o.debug("Doing Action: " + t);
                var n = [];
                void 0 !== this.hooks.actions[t] && this.hooks.actions[t].length > 0 && (o.debug(this.hooks.actions[t]), this.hooks.actions[t].forEach(function (t) {
                  n[t.priority] = n[t.priority] || [], n[t.priority].push(t.callback);
                }), n.forEach(function (n) {
                  o.debug("Executing Action callabck for: " + t), n.forEach(function (t) {
                    t(e);
                  });
                }));
              },
              removeAction: function removeAction(t, e) {
                this.hooks.actions[t] = this.hooks.actions[t] || [], this.hooks.actions[t].forEach(function (n, r) {
                  n.callback === e && this.hooks.actions[t].splice(r, 1);
                });
              },
              removeFilter: function removeFilter(t, e) {
                this.hooks.filters[t] = this.hooks.filters[t] || [], this.hooks.filters[t].forEach(function (e, n) {
                  e.callback === callback && this.hooks.filters[t].splice(n, 1);
                });
              },
              stateManager: function stateManager() {
                this.cookies = o.util.readAllCookies(), this.init = !0;
              }
            };
            o.stateManager.prototype = {
              init: !1,
              cookies: "",
              stores: {},
              storeFormats: {},
              storeMeta: {},
              registerStore: function registerStore(t, e, n, r) {
                this.storeMeta[t] = {
                  expiration: e,
                  length: n,
                  format: r
                };
              },
              getExpirationDays: function getExpirationDays(t) {
                if (this.storeMeta.hasOwnProperty(t)) return this.storeMeta[t].expiration;
              },
              getFormat: function getFormat(t) {
                if (this.storeMeta.hasOwnProperty(t)) return this.storeMeta[t].format;
              },
              isPresent: function isPresent(t) {
                if (this.stores.hasOwnProperty(t)) return !0;
              },
              set: function set(t, e, n, r, i, s) {
                var a;
                this.isPresent(t) || this.load(t), this.isPresent(t) || (o.debug("Creating state store (%s)", t), this.stores[t] = {}, o.getSetting("hashCookiesToDomain") && (this.stores[t].cdh = o.util.getCookieDomainHash(o.getSetting("cookie_domain")))), e ? this.stores[t][e] = n : this.stores[t] = n, (i = this.getFormat(t)) || this.storeFormats.hasOwnProperty(t) && (i = this.storeFormats[t]), a = "json" === i ? JSON.stringify(this.stores[t]) : o.util.assocStringFromJson(this.stores[t]), (s = this.getExpirationDays(t)) || r && (s = 364), o.debug("Populating state store (%s) with value: %s", t, a);
                var u = o.getSetting("cookie_domain") || document.domain;
                o.util.setCookie(o.getSetting("ns") + t, a, s, "/", u);
              },
              replaceStore: function replaceStore(t, e, n, r, i) {
                if (o.debug("replace state format: %s, value: %s", r, JSON.stringify(e)), t) {
                  e && (r = this.getFormat(t), this.stores[t] = e, this.storeFormats[t] = r, cookie_value = "json" === r ? JSON.stringify(e) : o.util.assocStringFromJson(e));
                  var s = o.getSetting("cookie_domain") || document.domain;
                  i = this.getExpirationDays(t), o.debug("About to replace state store (%s) with: %s", t, cookie_value), o.util.setCookie(o.getSetting("ns") + t, cookie_value, i, "/", s);
                }
              },
              getStateFromCookie: function getStateFromCookie(t) {
                var e = unescape(o.util.readCookie(o.getSetting("ns") + t));
                if (e) return e;
              },
              get: function get(t, e) {
                return this.isPresent(t) || this.load(t), this.isPresent(t) ? e ? this.stores[t].hasOwnProperty(e) ? this.stores[t][e] : void 0 : this.stores[t] : (o.debug("No state store (%s) was found", t), "");
              },
              getCookieValues: function getCookieValues(t) {
                if (this.cookies.hasOwnProperty(t)) return this.cookies[t];
              },
              load: function load(t) {
                var e = "",
                    n = this.getCookieValues(o.getSetting("ns") + t);
                if (n) for (var r = 0; r < n.length; r++) {
                  var i = unescape(n[r]),
                      s = o.util.decodeCookieValue(i),
                      a = o.util.getCookieValueFormat(i);

                  if (o.getSetting("hashCookiesToDomain")) {
                    var u = o.getSetting("cookie_domain"),
                        c = o.util.getCookieDomainHash(u);

                    if (s.hasOwnProperty("cdh")) {
                      if (o.debug("Cookie value cdh: %s, domain hash: %s", s.cdh, c), s.cdh == c) {
                        o.debug("Cookie: %s, index: %s domain hash matches current cookie domain. Loading...", t, r), e = s;
                        break;
                      }

                      o.debug("Cookie: %s, index: %s domain hash does not match current cookie domain. Not loading.", t, r);
                    } else o.debug("Cookie: %s, index: %s has no domain hash. Not going to Load it.", t, r);
                  } else r === n.length - 1 && (e = s);
                }
                e ? (this.stores[t] = e, this.storeFormats[t] = a, o.debug("Loaded state store: %s with: %s", t, JSON.stringify(e))) : o.debug("No state for store: %s was found. Nothing to Load.", t);
              },
              clear: function clear(t, e) {
                if (e) {
                  var n = this.get(t);
                  n && n.hasOwnProperty(e) && (delete n.key, this.replaceStore(t, n, !0, this.getFormat(t), this.getExpirationDays(t)));
                } else delete this.stores[t], o.util.eraseCookie(o.getSetting("ns") + t), this.cookies = o.util.readAllCookies();
              },
              getStoreFormat: function getStoreFormat(t) {
                return this.getFormat(t);
              },
              setStoreFormat: function setStoreFormat(t, e) {
                this.storeFormats[t] = e;
              }
            }, o.uri = function (t) {
              this.components = {}, this.dirty = !1, this.options = {
                strictMode: !1,
                key: ["source", "protocol", "authority", "userInfo", "user", "password", "host", "port", "relative", "path", "directory", "file", "query", "anchor"],
                q: {
                  name: "queryKey",
                  parser: /(?:^|&)([^&=]*)=?([^&]*)/g
                },
                parser: {
                  strict: /^(?:([^:\/?#]+):)?(?:\/\/((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?))?((((?:[^?#\/]*\/)*)([^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
                  loose: /^(?:(?![^:@]+:[^:@\/]*@)([^:\/?#.]+):)?(?:\/\/)?((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?)(((\/(?:[^?#](?![^?#\/]*\.[^?#\/.]+(?:[?#]|$)))*\/?)?([^?#\/]*))(?:\?([^#]*))?(?:#(.*))?)/
                }
              }, t && (this.components = this.parseUri(t));
            }, o.uri.prototype = {
              parseUri: function parseUri(t) {
                for (var e = this.options, n = e.parser[e.strictMode ? "strict" : "loose"].exec(t), r = {}, o = 14; o--;) {
                  r[e.key[o]] = n[o] || "";
                }

                return r[e.q.name] = {}, r[e.key[12]].replace(e.q.parser, function (t, n, o) {
                  n && (r[e.q.name][n] = o);
                }), r;
              },
              getHost: function getHost() {
                if (this.components.hasOwnProperty("host")) return this.components.host;
              },
              getQueryParam: function getQueryParam(t) {
                if (this.components.hasOwnProperty("queryKey") && this.components.queryKey.hasOwnProperty(t)) return o.util.urldecode(this.components.queryKey[t]);
              },
              isQueryParam: function isQueryParam(t) {
                return !(!this.components.hasOwnProperty("queryKey") || !this.components.queryKey.hasOwnProperty(t));
              },
              getComponent: function getComponent(t) {
                if (this.components.hasOwnProperty(t)) return this.components[t];
              },
              getProtocol: function getProtocol() {
                return this.getComponent("protocol");
              },
              getAnchor: function getAnchor() {
                return this.getComponent("anchor");
              },
              getQuery: function getQuery() {
                return this.getComponent("query");
              },
              getFile: function getFile() {
                return this.getComponent("file");
              },
              getRelative: function getRelative() {
                return this.getComponent("relative");
              },
              getDirectory: function getDirectory() {
                return this.getComponent("directory");
              },
              getPath: function getPath() {
                return this.getComponent("path");
              },
              getPort: function getPort() {
                return this.getComponent("port");
              },
              getPassword: function getPassword() {
                return this.getComponent("password");
              },
              getUser: function getUser() {
                return this.getComponent("user");
              },
              getUserInfo: function getUserInfo() {
                return this.getComponent("userInfo");
              },
              getQueryParams: function getQueryParams() {
                return this.getComponent("queryKey");
              },
              getSource: function getSource() {
                return this.getComponent("source");
              },
              setQueryParam: function setQueryParam(t, e) {
                this.components.hasOwnProperty("queryKey") || (this.components.queryKey = {}), this.components.queryKey[t] = o.util.urlEncode(e), this.resetQuery();
              },
              removeQueryParam: function removeQueryParam(t) {
                this.components.hasOwnProperty("queryKey") && this.components.queryKey.hasOwnProperty(t) && (delete this.components.queryKey[t], this.resetQuery());
              },
              resetSource: function resetSource() {
                this.components.source = this.assembleUrl();
              },
              resetQuery: function resetQuery() {
                var t = this.getQueryParams();

                if (t) {
                  var e = "",
                      n = o.util.countObjectProperties(t);

                  for (var r in t) {
                    e += r + "=" + t[r], 1 < n && (e += "&");
                  }

                  this.components.query = e, this.resetSource();
                }
              },
              isDirty: function isDirty() {
                return this.dirty;
              },
              setPath: function setPath(t) {},
              assembleUrl: function assembleUrl() {
                var t = "";
                t += this.getProtocol(), t += "://", this.getUser() && (t += this.getUser()), this.getUser() && this.getPassword() && (t += ":" + this.password()), t += this.getHost(), this.getPort() && (t += ":" + this.getPort()), t += this.getDirectory(), t += this.getFile();
                var e = this.getQuery();
                e && (t += "?" + e);
                var n = this.getAnchor();
                return n && (t += "#" + n), t + this.getAnchor();
              }
            }, o.util = {
              ns: function ns(t) {
                return o.config.ns + t;
              },
              nsAll: function nsAll(t) {
                var e = new Object();

                for (param in t) {
                  t.hasOwnProperty(param) && (e[o.config.ns + param] = t[param]);
                }

                return e;
              },
              getScript: function getScript(t, e) {
                jQuery.getScript(e + t);
              },
              makeUrl: function makeUrl(t, e, n) {
                return jQuery.sprintf(t, e, jQuery.param(o.util.nsAll(n)));
              },
              createCookie: function createCookie(t, e, n, r) {
                if (n) {
                  var o = new Date();
                  o.setTime(o.getTime() + 24 * n * 60 * 60 * 1e3);
                  var i = "; expires=" + o.toGMTString();
                } else i = "";

                document.cookie = t + "=" + e + i + "; path=/";
              },
              setCookie: function setCookie(t, e, n, r, o, i) {
                var s = new Date();
                s.setTime(s.getTime() + 24 * n * 60 * 60 * 1e3), document.cookie = t + "=" + escape(e) + (n ? "; expires=" + s.toGMTString() : "") + (r ? "; path=" + r : "") + (o ? "; domain=" + o : "") + (i ? "; secure" : "");
              },
              readAllCookies: function readAllCookies() {
                o.debug("Reading all cookies...");
                var t = {},
                    e = document.cookie.split(";");

                if (e) {
                  o.debug(document.cookie);

                  for (var n = 0; n < e.length; n++) {
                    var r = o.util.trim(e[n]),
                        i = o.util.strpos(r, "="),
                        s = r.substring(0, i),
                        a = r.substring(i + 1, r.length);
                    t.hasOwnProperty(s) || (t[s] = []), t[s].push(a);
                  }

                  return o.debug(JSON.stringify(t)), t;
                }
              },
              readCookie: function readCookie(t) {
                o.debug("Attempting to read cookie: %s", t);
                var e = o.util.readAllCookies();
                if (e) return e.hasOwnProperty(t) ? e[t] : "";
              },
              eraseCookie: function eraseCookie(t, e) {
                if (o.debug(document.cookie), e || (e = o.getSetting("cookie_domain") || document.domain), o.debug("erasing cookie: " + t + " in domain: " + e), this.setCookie(t, "", -1, "/", e), o.util.readCookie(t)) {
                  var n = e.substr(0, 1);

                  if (o.debug("period: " + n), "." === n) {
                    var r = e.substr(1);
                    o.debug("erasing " + t + " in domain2: " + r), this.setCookie(t, "", -2, "/", r);
                  } else o.debug("erasing " + t + " in domain3: " + e), this.setCookie(t, "", -2, "/", e);
                }
              },
              eraseMultipleCookies: function eraseMultipleCookies(t, e) {
                for (var n = 0; n < t.length; n++) {
                  this.eraseCookie(t[n], e);
                }
              },
              loadScript: function loadScript(t, e) {
                var n = document.createElement("script");
                n.type = "text/javascript", n.readyState ? n.onreadystatechange = function () {
                  "loaded" != n.readyState && "complete" != n.readyState || (n.onreadystatechange = null, e());
                } : n.onload = function () {
                  e();
                }, n.src = t, document.getElementsByTagName("head")[0].appendChild(n);
              },
              loadCss: function loadCss(t, e) {
                var n = document.createElement("link");
                n.rel = "stylesheet", n.type = "text/css", n.href = t, document.getElementsByTagName("HEAD")[0].appendChild(n);
              },
              parseCookieString: function parseCookieString(t) {
                var e = new Array(),
                    n = unescape(t).split("|||");

                for (var r in n) {
                  if (n.hasOwnProperty(r)) {
                    var o = n[r].split("=>");
                    e[o[0]] = o[1];
                  }
                }

                return e;
              },
              parseCookieStringToJson: function parseCookieStringToJson(t) {
                var e = new Object(),
                    n = unescape(t).split("|||");

                for (var r in n) {
                  if (n.hasOwnProperty(r)) {
                    var o = n[r].split("=>");
                    e[o[0]] = o[1];
                  }
                }

                return e;
              },
              nsParams: function nsParams(t) {
                var e = new Object();

                for (param in t) {
                  t.hasOwnProperty(param) && (e[o.getSetting("ns") + param] = t[param]);
                }

                return e;
              },
              urlEncode: function urlEncode(t) {
                return t = (t + "").toString(), encodeURIComponent(t).replace(/!/g, "%21").replace(/'/g, "%27").replace(/\(/g, "%28").replace(/\)/g, "%29").replace(/\*/g, "%2A").replace(/%20/g, "+");
              },
              urldecode: function urldecode(t) {
                return decodeURIComponent(t.replace(/\+/g, "%20"));
              },
              parseUrlParams: function parseUrlParams(t) {
                for (var e, n, r, o, i, s, a = {}, u = location.href.split(/[?&]/), c = u.length, l = 1; l < c; l++) {
                  if ((r = u[l].match(/(.*?)(\..*?|\[.*?\])?=([^#]*)/)) && 4 == r.length) {
                    if (o = decodeURI(r[1]).toLowerCase(), i = a, s = decodeURI(r[3]), r[2]) for (n = decodeURI(r[2]).replace(/\[\s*\]/g, "[-1]").split(/[\.\[\]]/), e = 0; e < n.length; e++) {
                      i = i[o] ? i[o] : i[o] = parseInt(n[e]) == n[e] ? [] : {}, o = n[e].replace(/^["\'](.*)["\']$/, "$1");
                    }
                    "-1" != o ? i[o] = s : i[i.length] = s;
                  }
                }

                return a;
              },
              strpos: function strpos(t, e, n) {
                var r = (t + "").indexOf(e, n || 0);
                return -1 !== r && r;
              },
              strCountOccurances: function strCountOccurances(t, e) {
                return t.split(e).length - 1;
              },
              implode: function implode(t, e) {
                var r = "",
                    o = "",
                    i = "";

                if (1 === arguments.length && (e = t, t = ""), "object" === n(e)) {
                  if (e instanceof Array) return e.join(t);

                  for (r in e) {
                    o += i + e[r], i = t;
                  }

                  return o;
                }

                return e;
              },
              checkForState: function checkForState(t) {
                return o.checkForState(t);
              },
              setState: function setState(t, e, n, r, i, s) {
                return o.setState(t, e, n, r, i, s);
              },
              replaceState: function replaceState(t, e, n, r, i) {
                return o.replaceState(t, e, n, r, i);
              },
              getRawState: function getRawState(t) {
                return o.getStateFromCookie(t);
              },
              getState: function getState(t, e) {
                return o.getState(t, e);
              },
              clearState: function clearState(t, e) {
                return o.clearState(t, e);
              },
              getCookieValueFormat: function getCookieValueFormat(t) {
                return "{" === t.substr(0, 1) ? "json" : "assoc";
              },
              decodeCookieValue: function decodeCookieValue(t) {
                var e,
                    n = o.util.getCookieValueFormat(t);
                return e = "json" === n ? JSON.parse(t) : o.util.jsonFromAssocString(t), o.debug("decodeCookieValue - string: %s, format: %s, value: %s", t, n, JSON.stringify(e)), e;
              },
              encodeJsonForCookie: function encodeJsonForCookie(t, e) {
                return "json" === (e = e || "assoc") ? JSON.stringify(t) : o.util.assocStringFromJson(t);
              },
              getCookieDomainHash: function getCookieDomainHash(t) {
                return o.util.dechex(o.util.crc32(t));
              },
              loadStateJson: function loadStateJson(t) {
                var e = unescape(o.util.readCookie(o.getSetting("ns") + t));
                e && (state = JSON.parse(e)), o.state[t] = state, o.debug("state store %s: %s", t, JSON.stringify(state));
              },
              is_array: function is_array(t) {
                return "object" == n(t) && t instanceof Array;
              },
              str_pad: function str_pad(t, e, n, r) {
                var o,
                    i = "",
                    s = function s(t, e) {
                  for (var n = ""; n.length < e;) {
                    n += t;
                  }

                  return n.substr(0, e);
                };

                return n = void 0 !== n ? n : " ", "STR_PAD_LEFT" != r && "STR_PAD_RIGHT" != r && "STR_PAD_BOTH" != r && (r = "STR_PAD_RIGHT"), (o = e - (t += "").length) > 0 && ("STR_PAD_LEFT" == r ? t = s(n, o) + t : "STR_PAD_RIGHT" == r ? t += s(n, o) : "STR_PAD_BOTH" == r && (t = (t = (i = s(n, Math.ceil(o / 2))) + t + i).substr(0, e))), t;
              },
              zeroFill: function zeroFill(t, e) {
                return o.util.str_pad(t, e, "0", "STR_PAD_LEFT");
              },
              is_object: function is_object(t) {
                return !(t instanceof Array) && null !== t && "object" == n(t);
              },
              countObjectProperties: function countObjectProperties(t) {
                var e,
                    n = 0;

                for (e in t) {
                  t.hasOwnProperty(e) && n++;
                }

                return n;
              },
              jsonFromAssocString: function jsonFromAssocString(t, e, n) {
                if (e = e || "=>", n = n || "|||", t) {
                  if (!this.strpos(t, e)) return t;

                  for (var r = {}, o = t.split(n), i = 0, s = o.length; i < s; i++) {
                    var a = o[i].split(e);
                    r[a[0]] = a[1];
                  }

                  return r;
                }
              },
              assocStringFromJson: function assocStringFromJson(t) {
                var e = "",
                    n = 0,
                    r = o.util.countObjectProperties(t);

                for (var i in t) {
                  n++, e += i + "=>" + t[i], n < r && (e += "|||");
                }

                return e;
              },
              getDomainFromUrl: function getDomainFromUrl(t, e) {
                var n = t.split(/\/+/g)[1];
                return !0 === e ? o.util.stripWwwFromDomain(n) : n;
              },
              stripWwwFromDomain: function stripWwwFromDomain(t) {
                return "www" === t.split(".")[0] ? t.substring(4) : t;
              },
              getCurrentUnixTimestamp: function getCurrentUnixTimestamp() {
                return Math.round(new Date().getTime() / 1e3);
              },
              generateHash: function generateHash(t) {
                return this.crc32(t);
              },
              generateRandomGuid: function generateRandomGuid() {
                return this.getCurrentUnixTimestamp() + "" + o.util.zeroFill(this.rand(0, 999999) + "", 6) + o.util.zeroFill(this.rand(0, 999) + "", 3);
              },
              crc32: function crc32(t) {
                var e = 0,
                    n = 0;
                e ^= -1;

                for (var r = 0, o = (t = this.utf8_encode(t)).length; r < o; r++) {
                  n = 255 & (e ^ t.charCodeAt(r)), e = e >>> 8 ^ "0x" + "00000000 77073096 EE0E612C 990951BA 076DC419 706AF48F E963A535 9E6495A3 0EDB8832 79DCB8A4 E0D5E91E 97D2D988 09B64C2B 7EB17CBD E7B82D07 90BF1D91 1DB71064 6AB020F2 F3B97148 84BE41DE 1ADAD47D 6DDDE4EB F4D4B551 83D385C7 136C9856 646BA8C0 FD62F97A 8A65C9EC 14015C4F 63066CD9 FA0F3D63 8D080DF5 3B6E20C8 4C69105E D56041E4 A2677172 3C03E4D1 4B04D447 D20D85FD A50AB56B 35B5A8FA 42B2986C DBBBC9D6 ACBCF940 32D86CE3 45DF5C75 DCD60DCF ABD13D59 26D930AC 51DE003A C8D75180 BFD06116 21B4F4B5 56B3C423 CFBA9599 B8BDA50F 2802B89E 5F058808 C60CD9B2 B10BE924 2F6F7C87 58684C11 C1611DAB B6662D3D 76DC4190 01DB7106 98D220BC EFD5102A 71B18589 06B6B51F 9FBFE4A5 E8B8D433 7807C9A2 0F00F934 9609A88E E10E9818 7F6A0DBB 086D3D2D 91646C97 E6635C01 6B6B51F4 1C6C6162 856530D8 F262004E 6C0695ED 1B01A57B 8208F4C1 F50FC457 65B0D9C6 12B7E950 8BBEB8EA FCB9887C 62DD1DDF 15DA2D49 8CD37CF3 FBD44C65 4DB26158 3AB551CE A3BC0074 D4BB30E2 4ADFA541 3DD895D7 A4D1C46D D3D6F4FB 4369E96A 346ED9FC AD678846 DA60B8D0 44042D73 33031DE5 AA0A4C5F DD0D7CC9 5005713C 270241AA BE0B1010 C90C2086 5768B525 206F85B3 B966D409 CE61E49F 5EDEF90E 29D9C998 B0D09822 C7D7A8B4 59B33D17 2EB40D81 B7BD5C3B C0BA6CAD EDB88320 9ABFB3B6 03B6E20C 74B1D29A EAD54739 9DD277AF 04DB2615 73DC1683 E3630B12 94643B84 0D6D6A3E 7A6A5AA8 E40ECF0B 9309FF9D 0A00AE27 7D079EB1 F00F9344 8708A3D2 1E01F268 6906C2FE F762575D 806567CB 196C3671 6E6B06E7 FED41B76 89D32BE0 10DA7A5A 67DD4ACC F9B9DF6F 8EBEEFF9 17B7BE43 60B08ED5 D6D6A3E8 A1D1937E 38D8C2C4 4FDFF252 D1BB67F1 A6BC5767 3FB506DD 48B2364B D80D2BDA AF0A1B4C 36034AF6 41047A60 DF60EFC3 A867DF55 316E8EEF 4669BE79 CB61B38C BC66831A 256FD2A0 5268E236 CC0C7795 BB0B4703 220216B9 5505262F C5BA3BBE B2BD0B28 2BB45A92 5CB36A04 C2D7FFA7 B5D0CF31 2CD99E8B 5BDEAE1D 9B64C2B0 EC63F226 756AA39C 026D930A 9C0906A9 EB0E363F 72076785 05005713 95BF4A82 E2B87A14 7BB12BAE 0CB61B38 92D28E9B E5D5BE0D 7CDCEFB7 0BDBDF21 86D3D2D4 F1D4E242 68DDB3F8 1FDA836E 81BE16CD F6B9265B 6FB077E1 18B74777 88085AE6 FF0F6A70 66063BCA 11010B5C 8F659EFF F862AE69 616BFFD3 166CCF45 A00AE278 D70DD2EE 4E048354 3903B3C2 A7672661 D06016F7 4969474D 3E6E77DB AED16A4A D9D65ADC 40DF0B66 37D83BF0 A9BCAE53 DEBB9EC5 47B2CF7F 30B5FFE9 BDBDF21C CABAC28A 53B39330 24B4A3A6 BAD03605 CDD70693 54DE5729 23D967BF B3667A2E C4614AB8 5D681B02 2A6F2B94 B40BBE37 C30C8EA1 5A05DF1B 2D02EF8D".substr(9 * n, 8);
                }

                return -1 ^ e;
              },
              utf8_encode: function utf8_encode(t) {
                var e,
                    n,
                    r,
                    o = t + "",
                    i = "";
                e = n = 0, r = o.length;

                for (var s = 0; s < r; s++) {
                  var a = o.charCodeAt(s),
                      u = null;
                  a < 128 ? n++ : u = a > 127 && a < 2048 ? String.fromCharCode(a >> 6 | 192) + String.fromCharCode(63 & a | 128) : String.fromCharCode(a >> 12 | 224) + String.fromCharCode(a >> 6 & 63 | 128) + String.fromCharCode(63 & a | 128), null !== u && (n > e && (i += o.substring(e, n)), i += u, e = n = s + 1);
                }

                return n > e && (i += o.substring(e, o.length)), i;
              },
              utf8_decode: function utf8_decode(t) {
                var e = [],
                    n = 0,
                    r = 0,
                    o = 0,
                    i = 0,
                    s = 0;

                for (t += ""; n < t.length;) {
                  (o = t.charCodeAt(n)) < 128 ? (e[r++] = String.fromCharCode(o), n++) : o > 191 && o < 224 ? (i = t.charCodeAt(n + 1), e[r++] = String.fromCharCode((31 & o) << 6 | 63 & i), n += 2) : (i = t.charCodeAt(n + 1), s = t.charCodeAt(n + 2), e[r++] = String.fromCharCode((15 & o) << 12 | (63 & i) << 6 | 63 & s), n += 3);
                }

                return e.join("");
              },
              trim: function trim(t, e) {
                var n,
                    r = 0,
                    o = 0;

                for (t += "", n = e ? (e += "").replace(/([\[\]\(\)\.\?\/\*\{\}\+\$\^\:])/g, "$1") : " \n\r\t\f\x0B\xA0\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200A\u200B\u2028\u2029\u3000", r = t.length, o = 0; o < r; o++) {
                  if (-1 === n.indexOf(t.charAt(o))) {
                    t = t.substring(o);
                    break;
                  }
                }

                for (o = (r = t.length) - 1; o >= 0; o--) {
                  if (-1 === n.indexOf(t.charAt(o))) {
                    t = t.substring(0, o + 1);
                    break;
                  }
                }

                return -1 === n.indexOf(t.charAt(0)) ? t : "";
              },
              rand: function rand(t, e) {
                var n = arguments.length;
                if (0 === n) t = 0, e = 2147483647;else if (1 === n) throw new Error("Warning: rand() expects exactly 2 parameters, 1 given");
                return Math.floor(Math.random() * (e - t + 1)) + t;
              },
              base64_encode: function base64_encode(t) {
                var e,
                    n,
                    r,
                    o,
                    i,
                    s = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
                    a = 0,
                    u = 0,
                    c = "",
                    l = [];
                if (!t) return t;
                t = this.utf8_encode(t + "");

                do {
                  e = (i = t.charCodeAt(a++) << 16 | t.charCodeAt(a++) << 8 | t.charCodeAt(a++)) >> 18 & 63, n = i >> 12 & 63, r = i >> 6 & 63, o = 63 & i, l[u++] = s.charAt(e) + s.charAt(n) + s.charAt(r) + s.charAt(o);
                } while (a < t.length);

                switch (c = l.join(""), t.length % 3) {
                  case 1:
                    c = c.slice(0, -2) + "==";
                    break;

                  case 2:
                    c = c.slice(0, -1) + "=";
                }

                return c;
              },
              base64_decode: function base64_decode(t) {
                var e,
                    n,
                    r,
                    o,
                    i,
                    s,
                    a,
                    u = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
                    c = 0,
                    l = 0,
                    h = [];
                if (!t) return t;
                t += "";

                do {
                  e = (s = u.indexOf(t.charAt(c++)) << 18 | u.indexOf(t.charAt(c++)) << 12 | (o = u.indexOf(t.charAt(c++))) << 6 | (i = u.indexOf(t.charAt(c++)))) >> 16 & 255, n = s >> 8 & 255, r = 255 & s, h[l++] = 64 == o ? String.fromCharCode(e) : 64 == i ? String.fromCharCode(e, n) : String.fromCharCode(e, n, r);
                } while (c < t.length);

                return a = h.join(""), this.utf8_decode(a);
              },
              sprintf: function sprintf() {
                var t = /%%|%(\d+\$)?([-+\'#0 ]*)(\*\d+\$|\*|\d+)?(\.(\*\d+\$|\*|\d+))?([scboxXuidfegEG])/g,
                    e = arguments,
                    n = 0,
                    r = e[n++],
                    o = function o(t, e, n, r) {
                  n || (n = " ");
                  var o = t.length >= e ? "" : Array(1 + e - t.length >>> 0).join(n);
                  return r ? t + o : o + t;
                },
                    i = function i(t, e, n, r, _i3, s) {
                  var a = r - t.length;
                  return a > 0 && (t = n || !_i3 ? o(t, r, s, n) : t.slice(0, e.length) + o("", a, "0", !0) + t.slice(e.length)), t;
                },
                    s = function s(t, e, n, r, _s3, a, u) {
                  var c = t >>> 0;
                  return t = (n = n && c && {
                    2: "0b",
                    8: "0",
                    16: "0x"
                  }[e] || "") + o(c.toString(e), a || 0, "0", !1), i(t, n, r, _s3, u);
                },
                    a = function a(t, e, n, r, o, s) {
                  return null != r && (t = t.slice(0, r)), i(t, "", e, n, o, s);
                },
                    u = function u(t, r, _u3, c, l, h, g) {
                  var f, d, p, m, C;
                  if ("%%" == t) return "%";

                  for (var A = !1, y = "", D = !1, v = !1, S = " ", b = _u3.length, E = 0; _u3 && E < b; E++) {
                    switch (_u3.charAt(E)) {
                      case " ":
                        y = " ";
                        break;

                      case "+":
                        y = "+";
                        break;

                      case "-":
                        A = !0;
                        break;

                      case "'":
                        S = _u3.charAt(E + 1);
                        break;

                      case "0":
                        D = !0;
                        break;

                      case "#":
                        v = !0;
                    }
                  }

                  if ((c = c ? "*" == c ? +e[n++] : "*" == c.charAt(0) ? +e[c.slice(1, -1)] : +c : 0) < 0 && (c = -c, A = !0), !isFinite(c)) throw new Error("sprintf: (minimum-)width must be finite");

                  switch (h = h ? "*" == h ? +e[n++] : "*" == h.charAt(0) ? +e[h.slice(1, -1)] : +h : "fFeE".indexOf(g) > -1 ? 6 : "d" == g ? 0 : void 0, C = r ? e[r.slice(0, -1)] : e[n++], g) {
                    case "s":
                      return a(String(C), A, c, h, D, S);

                    case "c":
                      return a(String.fromCharCode(+C), A, c, h, D);

                    case "b":
                      return s(C, 2, v, A, c, h, D);

                    case "o":
                      return s(C, 8, v, A, c, h, D);

                    case "x":
                      return s(C, 16, v, A, c, h, D);

                    case "X":
                      return s(C, 16, v, A, c, h, D).toUpperCase();

                    case "u":
                      return s(C, 10, v, A, c, h, D);

                    case "i":
                    case "d":
                      return C = (d = (f = parseInt(+C, 10)) < 0 ? "-" : y) + o(String(Math.abs(f)), h, "0", !1), i(C, d, A, c, D);

                    case "e":
                    case "E":
                    case "f":
                    case "F":
                    case "g":
                    case "G":
                      return d = (f = +C) < 0 ? "-" : y, p = ["toExponential", "toFixed", "toPrecision"]["efg".indexOf(g.toLowerCase())], m = ["toString", "toUpperCase"]["eEfFgG".indexOf(g) % 2], C = d + Math.abs(f)[p](h), i(C, d, A, c, D)[m]();

                    default:
                      return t;
                  }
                };

                return r.replace(t, u);
              },
              clone: function clone(t) {
                var e = t instanceof Array ? [] : {};

                for (var r in t) {
                  t[r] && "object" == n(t[r]) ? e[r] = o.util.clone(t[r]) : e[r] = t[r];
                }

                return e;
              },
              strtolower: function strtolower(t) {
                return (t + "").toLowerCase();
              },
              in_array: function in_array(t, e, n) {
                var r = "";

                if (n) {
                  for (r in e) {
                    if (e[r] === t) return !0;
                  }
                } else for (r in e) {
                  if (e[r] == t) return !0;
                }

                return !1;
              },
              dechex: function dechex(t) {
                return t < 0 && (t = 4294967295 + t + 1), parseInt(t, 10).toString(16);
              },
              explode: function explode(t, e, r) {
                var o = {
                  0: ""
                };
                if (arguments.length < 2 || void 0 === arguments[0] || void 0 === arguments[1]) return null;
                if ("" === t || !1 === t || null === t) return !1;
                if ("function" == typeof t || "object" == n(t) || "function" == typeof e || "object" == n(e)) return o;

                if (!0 === t && (t = "1"), r) {
                  var i = e.toString().split(t.toString()),
                      s = i.splice(0, r - 1),
                      a = i.join(t.toString());
                  return s.push(a), s;
                }

                return e.toString().split(t.toString());
              },
              isIE: function isIE() {
                if (/MSIE (\d+\.\d+);/.test(navigator.userAgent)) return !0;
              },
              getInternetExplorerVersion: function getInternetExplorerVersion() {
                var t = -1;

                if ("Microsoft Internet Explorer" == navigator.appName) {
                  var e = navigator.userAgent;
                  null != new RegExp("MSIE ([0-9]{1,}[.0-9]{0,})").exec(e) && (t = parseFloat(RegExp.$1));
                }

                return t;
              },
              isBrowserTrackable: function isBrowserTrackable() {
                for (var t = ["doNotTrack", "msDoNotTrack"], e = 0, n = t.length; e < n; e++) {
                  if (navigator[t[e]] && "1" == navigator[t[e]]) return !1;
                }

                return !0;
              }
            };
          },
          1: function _(t, e, n) {
            n(0), n(2), n(11), t.exports = n(16);
          },
          11: function _(t, e) {},
          16: function _(t, e) {},
          2: function _(t, e) {
            function n(t) {
              return (n = "function" == typeof Symbol && "symbol" == r(Symbol.iterator) ? function (t) {
                return r(t);
              } : function (t) {
                return t && "function" == typeof Symbol && t.constructor === Symbol && t !== Symbol.prototype ? "symbol" : r(t);
              })(t);
            }

            !function (t) {
              var e = {};

              function r(n) {
                if (e[n]) return e[n].exports;
                var o = e[n] = {
                  i: n,
                  l: !1,
                  exports: {}
                };
                return t[n].call(o.exports, o, o.exports, r), o.l = !0, o.exports;
              }

              r.m = t, r.c = e, r.d = function (t, e, n) {
                r.o(t, e) || Object.defineProperty(t, e, {
                  enumerable: !0,
                  get: n
                });
              }, r.r = function (t) {
                "undefined" != typeof Symbol && Symbol.toStringTag && Object.defineProperty(t, Symbol.toStringTag, {
                  value: "Module"
                }), Object.defineProperty(t, "__esModule", {
                  value: !0
                });
              }, r.t = function (t, e) {
                if (1 & e && (t = r(t)), 8 & e) return t;
                if (4 & e && "object" == n(t) && t && t.__esModule) return t;
                var o = Object.create(null);
                if (r.r(o), Object.defineProperty(o, "default", {
                  enumerable: !0,
                  value: t
                }), 2 & e && "string" != typeof t) for (var i in t) {
                  r.d(o, i, function (e) {
                    return t[e];
                  }.bind(null, i));
                }
                return o;
              }, r.n = function (t) {
                var e = t && t.__esModule ? function () {
                  return t["default"];
                } : function () {
                  return t;
                };
                return r.d(e, "a", e), e;
              }, r.o = function (t, e) {
                return Object.prototype.hasOwnProperty.call(t, e);
              }, r.p = "/", r(r.s = 1);
            }([function (t, e) {
              function r(t) {
                return (r = "function" == typeof Symbol && "symbol" == n(Symbol.iterator) ? function (t) {
                  return n(t);
                } : function (t) {
                  return t && "function" == typeof Symbol && t.constructor === Symbol && t !== Symbol.prototype ? "symbol" : n(t);
                })(t);
              }

              var o = {
                items: {},
                hooks: {
                  actions: {},
                  filters: {}
                },
                loadedJsLibs: {},
                overlay: "",
                config: {
                  ns: "owa_",
                  baseUrl: "",
                  hashCookiesToDomain: !0,
                  debug: !1
                },
                state: {},
                overlayActive: !1,
                setSetting: function setSetting(t, e) {
                  return this.setOption(t, e);
                },
                getSetting: function getSetting(t) {
                  return this.getOption(t);
                },
                setOption: function setOption(t, e) {
                  this.config[t] = e;
                },
                getOption: function getOption(t) {
                  return this.config[t];
                },
                l: function l(t) {
                  return t;
                },
                requireJs: function requireJs(t, e, n) {
                  this.isJsLoaded(t) || o.util.loadScript(e, n), this.loadedJsLibs[t] = e;
                },
                isJsLoaded: function isJsLoaded(t) {
                  if (this.loadedJsLibs.hasOwnProperty(t)) return !0;
                },
                initializeStateManager: function initializeStateManager() {
                  this.state.hasOwnProperty("init") || (o.debug("initializing state manager..."), this.state = new o.stateManager());
                },
                registerStateStore: function registerStateStore(t, e, n, r) {
                  return this.initializeStateManager(), this.state.registerStore(t, e, n, r);
                },
                checkForState: function checkForState(t) {
                  return this.initializeStateManager(), this.state.isPresent(t);
                },
                setState: function setState(t, e, n, r, o, i) {
                  return this.initializeStateManager(), this.state.set(t, e, n, r, o, i);
                },
                replaceState: function replaceState(t, e, n, r, o) {
                  return this.initializeStateManager(), this.state.replaceStore(t, e, n, r, o);
                },
                getStateFromCookie: function getStateFromCookie(t) {
                  return this.initializeStateManager(), this.state.getStateFromCookie(t);
                },
                getState: function getState(t, e) {
                  return this.initializeStateManager(), this.state.get(t, e);
                },
                clearState: function clearState(t, e) {
                  return this.initializeStateManager(), this.state.clear(t, e);
                },
                getStateStoreFormat: function getStateStoreFormat(t) {
                  return this.initializeStateManager(), this.state.getStoreFormat(t);
                },
                setStateStoreFormat: function setStateStoreFormat(t, e) {
                  return this.initializeStateManager(), this.state.setStoreFormat(t, e);
                },
                debug: function debug() {
                  var t = o.getSetting("debug") || !1;
                  t && window.console && console.log.apply && (window.console.firebug ? console.log.apply(this, arguments) : console.log.apply(console, arguments));
                },
                setApiEndpoint: function setApiEndpoint(t) {
                  this.config.rest_api_endpoint = t;
                },
                getApiEndpoint: function getApiEndpoint() {
                  return this.config.rest_api_endpoint || this.getSetting("baseUrl") + "api/";
                },
                loadHeatmap: function loadHeatmap(t) {
                  var e = this;
                  o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/includes/jquery/jquery-1.6.4.min.js", function () {}), o.util.loadCss(o.getSetting("baseUrl") + "/modules/base/css/owa.overlay.css", function () {}), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/owa.heatmap.js", function () {
                    e.overlay = new o.heatmap(), e.overlay.options.liveMode = !0, e.overlay.generate();
                  });
                },
                loadPlayer: function loadPlayer() {
                  var t = this;
                  o.debug("Loading Domstream Player"), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/includes/jquery/jquery-1.6.4.min.js", function () {}), o.util.loadCss(o.getSetting("baseUrl") + "/modules/base/css/owa.overlay.css", function () {}), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/owa.player.js", function () {
                    t.overlay = new o.player();
                  });
                },
                startOverlaySession: function startOverlaySession(t) {
                  o.overlayActive = !0, t.hasOwnProperty("api_url") && o.setApiEndpoint(t.api_url);
                  var e = t;
                  "loadHeatmap" === e.action ? this.loadHeatmap(t) : "loadPlayer" === e.action && this.loadPlayer(t);
                },
                endOverlaySession: function endOverlaySession() {
                  o.util.eraseCookie(o.getSetting("ns") + "overlay", document.domain), o.overlayActive = !1;
                },
                addFilter: function addFilter(t, e, n) {
                  void 0 === n && (n = 10), this.hooks.filters[t] = this.hooks.filters[t] || [], this.hooks.filters[t].push({
                    priority: n,
                    callback: e
                  });
                },
                addAction: function addAction(t, e, n) {
                  o.debug("Adding Action callback for: " + t), void 0 === n && (n = 10), this.hooks.actions[t] = this.hooks.actions[t] || [], this.hooks.actions[t].push({
                    priority: n,
                    callback: e
                  });
                },
                applyFilters: function applyFilters(t, e, n) {
                  o.debug("Filtering " + t + " with value:"), o.debug(e);
                  var r = [];
                  return void 0 !== this.hooks.filters[t] && this.hooks.filters[t].length > 0 && (o.debug("Applying filters for " + t), this.hooks.filters[t].forEach(function (t) {
                    r[t.priority] = r[t.priority] || [], r[t.priority].push(t.callback);
                  }), r.forEach(function (t) {
                    t.forEach(function (t) {
                      e = t(e, n), o.debug("Filter returned value: "), o.debug(e);
                    });
                  })), e;
                },
                doAction: function doAction(t, e) {
                  o.debug("Doing Action: " + t);
                  var n = [];
                  void 0 !== this.hooks.actions[t] && this.hooks.actions[t].length > 0 && (o.debug(this.hooks.actions[t]), this.hooks.actions[t].forEach(function (t) {
                    n[t.priority] = n[t.priority] || [], n[t.priority].push(t.callback);
                  }), n.forEach(function (n) {
                    o.debug("Executing Action callabck for: " + t), n.forEach(function (t) {
                      t(e);
                    });
                  }));
                },
                removeAction: function removeAction(t, e) {
                  this.hooks.actions[t] = this.hooks.actions[t] || [], this.hooks.actions[t].forEach(function (n, r) {
                    n.callback === e && this.hooks.actions[t].splice(r, 1);
                  });
                },
                removeFilter: function removeFilter(t, e) {
                  this.hooks.filters[t] = this.hooks.filters[t] || [], this.hooks.filters[t].forEach(function (e, n) {
                    e.callback === callback && this.hooks.filters[t].splice(n, 1);
                  });
                },
                stateManager: function stateManager() {
                  this.cookies = o.util.readAllCookies(), this.init = !0;
                }
              };
              o.stateManager.prototype = {
                init: !1,
                cookies: "",
                stores: {},
                storeFormats: {},
                storeMeta: {},
                registerStore: function registerStore(t, e, n, r) {
                  this.storeMeta[t] = {
                    expiration: e,
                    length: n,
                    format: r
                  };
                },
                getExpirationDays: function getExpirationDays(t) {
                  if (this.storeMeta.hasOwnProperty(t)) return this.storeMeta[t].expiration;
                },
                getFormat: function getFormat(t) {
                  if (this.storeMeta.hasOwnProperty(t)) return this.storeMeta[t].format;
                },
                isPresent: function isPresent(t) {
                  if (this.stores.hasOwnProperty(t)) return !0;
                },
                set: function set(t, e, n, r, i, s) {
                  var a;
                  this.isPresent(t) || this.load(t), this.isPresent(t) || (o.debug("Creating state store (%s)", t), this.stores[t] = {}, o.getSetting("hashCookiesToDomain") && (this.stores[t].cdh = o.util.getCookieDomainHash(o.getSetting("cookie_domain")))), e ? this.stores[t][e] = n : this.stores[t] = n, (i = this.getFormat(t)) || this.storeFormats.hasOwnProperty(t) && (i = this.storeFormats[t]), a = "json" === i ? JSON.stringify(this.stores[t]) : o.util.assocStringFromJson(this.stores[t]), (s = this.getExpirationDays(t)) || r && (s = 364), o.debug("Populating state store (%s) with value: %s", t, a);
                  var u = o.getSetting("cookie_domain") || document.domain;
                  o.util.setCookie(o.getSetting("ns") + t, a, s, "/", u);
                },
                replaceStore: function replaceStore(t, e, n, r, i) {
                  if (o.debug("replace state format: %s, value: %s", r, JSON.stringify(e)), t) {
                    e && (r = this.getFormat(t), this.stores[t] = e, this.storeFormats[t] = r, cookie_value = "json" === r ? JSON.stringify(e) : o.util.assocStringFromJson(e));
                    var s = o.getSetting("cookie_domain") || document.domain;
                    i = this.getExpirationDays(t), o.debug("About to replace state store (%s) with: %s", t, cookie_value), o.util.setCookie(o.getSetting("ns") + t, cookie_value, i, "/", s);
                  }
                },
                getStateFromCookie: function getStateFromCookie(t) {
                  var e = unescape(o.util.readCookie(o.getSetting("ns") + t));
                  if (e) return e;
                },
                get: function get(t, e) {
                  return this.isPresent(t) || this.load(t), this.isPresent(t) ? e ? this.stores[t].hasOwnProperty(e) ? this.stores[t][e] : void 0 : this.stores[t] : (o.debug("No state store (%s) was found", t), "");
                },
                getCookieValues: function getCookieValues(t) {
                  if (this.cookies.hasOwnProperty(t)) return this.cookies[t];
                },
                load: function load(t) {
                  var e = "",
                      n = this.getCookieValues(o.getSetting("ns") + t);
                  if (n) for (var r = 0; r < n.length; r++) {
                    var i = unescape(n[r]),
                        s = o.util.decodeCookieValue(i),
                        a = o.util.getCookieValueFormat(i);

                    if (o.getSetting("hashCookiesToDomain")) {
                      var u = o.getSetting("cookie_domain"),
                          c = o.util.getCookieDomainHash(u);

                      if (s.hasOwnProperty("cdh")) {
                        if (o.debug("Cookie value cdh: %s, domain hash: %s", s.cdh, c), s.cdh == c) {
                          o.debug("Cookie: %s, index: %s domain hash matches current cookie domain. Loading...", t, r), e = s;
                          break;
                        }

                        o.debug("Cookie: %s, index: %s domain hash does not match current cookie domain. Not loading.", t, r);
                      } else o.debug("Cookie: %s, index: %s has no domain hash. Not going to Load it.", t, r);
                    } else r === n.length - 1 && (e = s);
                  }
                  e ? (this.stores[t] = e, this.storeFormats[t] = a, o.debug("Loaded state store: %s with: %s", t, JSON.stringify(e))) : o.debug("No state for store: %s was found. Nothing to Load.", t);
                },
                clear: function clear(t, e) {
                  if (e) {
                    var n = this.get(t);
                    n && n.hasOwnProperty(e) && (delete n.key, this.replaceStore(t, n, !0, this.getFormat(t), this.getExpirationDays(t)));
                  } else delete this.stores[t], o.util.eraseCookie(o.getSetting("ns") + t), this.cookies = o.util.readAllCookies();
                },
                getStoreFormat: function getStoreFormat(t) {
                  return this.getFormat(t);
                },
                setStoreFormat: function setStoreFormat(t, e) {
                  this.storeFormats[t] = e;
                }
              }, o.uri = function (t) {
                this.components = {}, this.dirty = !1, this.options = {
                  strictMode: !1,
                  key: ["source", "protocol", "authority", "userInfo", "user", "password", "host", "port", "relative", "path", "directory", "file", "query", "anchor"],
                  q: {
                    name: "queryKey",
                    parser: /(?:^|&)([^&=]*)=?([^&]*)/g
                  },
                  parser: {
                    strict: /^(?:([^:\/?#]+):)?(?:\/\/((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?))?((((?:[^?#\/]*\/)*)([^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
                    loose: /^(?:(?![^:@]+:[^:@\/]*@)([^:\/?#.]+):)?(?:\/\/)?((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?)(((\/(?:[^?#](?![^?#\/]*\.[^?#\/.]+(?:[?#]|$)))*\/?)?([^?#\/]*))(?:\?([^#]*))?(?:#(.*))?)/
                  }
                }, t && (this.components = this.parseUri(t));
              }, o.uri.prototype = {
                parseUri: function parseUri(t) {
                  for (var e = this.options, n = e.parser[e.strictMode ? "strict" : "loose"].exec(t), r = {}, o = 14; o--;) {
                    r[e.key[o]] = n[o] || "";
                  }

                  return r[e.q.name] = {}, r[e.key[12]].replace(e.q.parser, function (t, n, o) {
                    n && (r[e.q.name][n] = o);
                  }), r;
                },
                getHost: function getHost() {
                  if (this.components.hasOwnProperty("host")) return this.components.host;
                },
                getQueryParam: function getQueryParam(t) {
                  if (this.components.hasOwnProperty("queryKey") && this.components.queryKey.hasOwnProperty(t)) return o.util.urldecode(this.components.queryKey[t]);
                },
                isQueryParam: function isQueryParam(t) {
                  return !(!this.components.hasOwnProperty("queryKey") || !this.components.queryKey.hasOwnProperty(t));
                },
                getComponent: function getComponent(t) {
                  if (this.components.hasOwnProperty(t)) return this.components[t];
                },
                getProtocol: function getProtocol() {
                  return this.getComponent("protocol");
                },
                getAnchor: function getAnchor() {
                  return this.getComponent("anchor");
                },
                getQuery: function getQuery() {
                  return this.getComponent("query");
                },
                getFile: function getFile() {
                  return this.getComponent("file");
                },
                getRelative: function getRelative() {
                  return this.getComponent("relative");
                },
                getDirectory: function getDirectory() {
                  return this.getComponent("directory");
                },
                getPath: function getPath() {
                  return this.getComponent("path");
                },
                getPort: function getPort() {
                  return this.getComponent("port");
                },
                getPassword: function getPassword() {
                  return this.getComponent("password");
                },
                getUser: function getUser() {
                  return this.getComponent("user");
                },
                getUserInfo: function getUserInfo() {
                  return this.getComponent("userInfo");
                },
                getQueryParams: function getQueryParams() {
                  return this.getComponent("queryKey");
                },
                getSource: function getSource() {
                  return this.getComponent("source");
                },
                setQueryParam: function setQueryParam(t, e) {
                  this.components.hasOwnProperty("queryKey") || (this.components.queryKey = {}), this.components.queryKey[t] = o.util.urlEncode(e), this.resetQuery();
                },
                removeQueryParam: function removeQueryParam(t) {
                  this.components.hasOwnProperty("queryKey") && this.components.queryKey.hasOwnProperty(t) && (delete this.components.queryKey[t], this.resetQuery());
                },
                resetSource: function resetSource() {
                  this.components.source = this.assembleUrl();
                },
                resetQuery: function resetQuery() {
                  var t = this.getQueryParams();

                  if (t) {
                    var e = "",
                        n = o.util.countObjectProperties(t);

                    for (var r in t) {
                      e += r + "=" + t[r], 1 < n && (e += "&");
                    }

                    this.components.query = e, this.resetSource();
                  }
                },
                isDirty: function isDirty() {
                  return this.dirty;
                },
                setPath: function setPath(t) {},
                assembleUrl: function assembleUrl() {
                  var t = "";
                  t += this.getProtocol(), t += "://", this.getUser() && (t += this.getUser()), this.getUser() && this.getPassword() && (t += ":" + this.password()), t += this.getHost(), this.getPort() && (t += ":" + this.getPort()), t += this.getDirectory(), t += this.getFile();
                  var e = this.getQuery();
                  e && (t += "?" + e);
                  var n = this.getAnchor();
                  return n && (t += "#" + n), t + this.getAnchor();
                }
              }, o.util = {
                ns: function ns(t) {
                  return o.config.ns + t;
                },
                nsAll: function nsAll(t) {
                  var e = new Object();

                  for (param in t) {
                    t.hasOwnProperty(param) && (e[o.config.ns + param] = t[param]);
                  }

                  return e;
                },
                getScript: function getScript(t, e) {
                  jQuery.getScript(e + t);
                },
                makeUrl: function makeUrl(t, e, n) {
                  return jQuery.sprintf(t, e, jQuery.param(o.util.nsAll(n)));
                },
                createCookie: function createCookie(t, e, n, r) {
                  if (n) {
                    var o = new Date();
                    o.setTime(o.getTime() + 24 * n * 60 * 60 * 1e3);
                    var i = "; expires=" + o.toGMTString();
                  } else i = "";

                  document.cookie = t + "=" + e + i + "; path=/";
                },
                setCookie: function setCookie(t, e, n, r, o, i) {
                  var s = new Date();
                  s.setTime(s.getTime() + 24 * n * 60 * 60 * 1e3), document.cookie = t + "=" + escape(e) + (n ? "; expires=" + s.toGMTString() : "") + (r ? "; path=" + r : "") + (o ? "; domain=" + o : "") + (i ? "; secure" : "");
                },
                readAllCookies: function readAllCookies() {
                  o.debug("Reading all cookies...");
                  var t = {},
                      e = document.cookie.split(";");

                  if (e) {
                    o.debug(document.cookie);

                    for (var n = 0; n < e.length; n++) {
                      var r = o.util.trim(e[n]),
                          i = o.util.strpos(r, "="),
                          s = r.substring(0, i),
                          a = r.substring(i + 1, r.length);
                      t.hasOwnProperty(s) || (t[s] = []), t[s].push(a);
                    }

                    return o.debug(JSON.stringify(t)), t;
                  }
                },
                readCookie: function readCookie(t) {
                  o.debug("Attempting to read cookie: %s", t);
                  var e = o.util.readAllCookies();
                  if (e) return e.hasOwnProperty(t) ? e[t] : "";
                },
                eraseCookie: function eraseCookie(t, e) {
                  if (o.debug(document.cookie), e || (e = o.getSetting("cookie_domain") || document.domain), o.debug("erasing cookie: " + t + " in domain: " + e), this.setCookie(t, "", -1, "/", e), o.util.readCookie(t)) {
                    var n = e.substr(0, 1);

                    if (o.debug("period: " + n), "." === n) {
                      var r = e.substr(1);
                      o.debug("erasing " + t + " in domain2: " + r), this.setCookie(t, "", -2, "/", r);
                    } else o.debug("erasing " + t + " in domain3: " + e), this.setCookie(t, "", -2, "/", e);
                  }
                },
                eraseMultipleCookies: function eraseMultipleCookies(t, e) {
                  for (var n = 0; n < t.length; n++) {
                    this.eraseCookie(t[n], e);
                  }
                },
                loadScript: function loadScript(t, e) {
                  var n = document.createElement("script");
                  n.type = "text/javascript", n.readyState ? n.onreadystatechange = function () {
                    "loaded" != n.readyState && "complete" != n.readyState || (n.onreadystatechange = null, e());
                  } : n.onload = function () {
                    e();
                  }, n.src = t, document.getElementsByTagName("head")[0].appendChild(n);
                },
                loadCss: function loadCss(t, e) {
                  var n = document.createElement("link");
                  n.rel = "stylesheet", n.type = "text/css", n.href = t, document.getElementsByTagName("HEAD")[0].appendChild(n);
                },
                parseCookieString: function parseCookieString(t) {
                  var e = new Array(),
                      n = unescape(t).split("|||");

                  for (var r in n) {
                    if (n.hasOwnProperty(r)) {
                      var o = n[r].split("=>");
                      e[o[0]] = o[1];
                    }
                  }

                  return e;
                },
                parseCookieStringToJson: function parseCookieStringToJson(t) {
                  var e = new Object(),
                      n = unescape(t).split("|||");

                  for (var r in n) {
                    if (n.hasOwnProperty(r)) {
                      var o = n[r].split("=>");
                      e[o[0]] = o[1];
                    }
                  }

                  return e;
                },
                nsParams: function nsParams(t) {
                  var e = new Object();

                  for (param in t) {
                    t.hasOwnProperty(param) && (e[o.getSetting("ns") + param] = t[param]);
                  }

                  return e;
                },
                urlEncode: function urlEncode(t) {
                  return t = (t + "").toString(), encodeURIComponent(t).replace(/!/g, "%21").replace(/'/g, "%27").replace(/\(/g, "%28").replace(/\)/g, "%29").replace(/\*/g, "%2A").replace(/%20/g, "+");
                },
                urldecode: function urldecode(t) {
                  return decodeURIComponent(t.replace(/\+/g, "%20"));
                },
                parseUrlParams: function parseUrlParams(t) {
                  for (var e, n, r, o, i, s, a = {}, u = location.href.split(/[?&]/), c = u.length, l = 1; l < c; l++) {
                    if ((r = u[l].match(/(.*?)(\..*?|\[.*?\])?=([^#]*)/)) && 4 == r.length) {
                      if (o = decodeURI(r[1]).toLowerCase(), i = a, s = decodeURI(r[3]), r[2]) for (n = decodeURI(r[2]).replace(/\[\s*\]/g, "[-1]").split(/[\.\[\]]/), e = 0; e < n.length; e++) {
                        i = i[o] ? i[o] : i[o] = parseInt(n[e]) == n[e] ? [] : {}, o = n[e].replace(/^["\'](.*)["\']$/, "$1");
                      }
                      "-1" != o ? i[o] = s : i[i.length] = s;
                    }
                  }

                  return a;
                },
                strpos: function strpos(t, e, n) {
                  var r = (t + "").indexOf(e, n || 0);
                  return -1 !== r && r;
                },
                strCountOccurances: function strCountOccurances(t, e) {
                  return t.split(e).length - 1;
                },
                implode: function implode(t, e) {
                  var n = "",
                      o = "",
                      i = "";

                  if (1 === arguments.length && (e = t, t = ""), "object" === r(e)) {
                    if (e instanceof Array) return e.join(t);

                    for (n in e) {
                      o += i + e[n], i = t;
                    }

                    return o;
                  }

                  return e;
                },
                checkForState: function checkForState(t) {
                  return o.checkForState(t);
                },
                setState: function setState(t, e, n, r, i, s) {
                  return o.setState(t, e, n, r, i, s);
                },
                replaceState: function replaceState(t, e, n, r, i) {
                  return o.replaceState(t, e, n, r, i);
                },
                getRawState: function getRawState(t) {
                  return o.getStateFromCookie(t);
                },
                getState: function getState(t, e) {
                  return o.getState(t, e);
                },
                clearState: function clearState(t, e) {
                  return o.clearState(t, e);
                },
                getCookieValueFormat: function getCookieValueFormat(t) {
                  return "{" === t.substr(0, 1) ? "json" : "assoc";
                },
                decodeCookieValue: function decodeCookieValue(t) {
                  var e,
                      n = o.util.getCookieValueFormat(t);
                  return e = "json" === n ? JSON.parse(t) : o.util.jsonFromAssocString(t), o.debug("decodeCookieValue - string: %s, format: %s, value: %s", t, n, JSON.stringify(e)), e;
                },
                encodeJsonForCookie: function encodeJsonForCookie(t, e) {
                  return "json" === (e = e || "assoc") ? JSON.stringify(t) : o.util.assocStringFromJson(t);
                },
                getCookieDomainHash: function getCookieDomainHash(t) {
                  return o.util.dechex(o.util.crc32(t));
                },
                loadStateJson: function loadStateJson(t) {
                  var e = unescape(o.util.readCookie(o.getSetting("ns") + t));
                  e && (state = JSON.parse(e)), o.state[t] = state, o.debug("state store %s: %s", t, JSON.stringify(state));
                },
                is_array: function is_array(t) {
                  return "object" == r(t) && t instanceof Array;
                },
                str_pad: function str_pad(t, e, n, r) {
                  var o,
                      i = "",
                      s = function s(t, e) {
                    for (var n = ""; n.length < e;) {
                      n += t;
                    }

                    return n.substr(0, e);
                  };

                  return n = void 0 !== n ? n : " ", "STR_PAD_LEFT" != r && "STR_PAD_RIGHT" != r && "STR_PAD_BOTH" != r && (r = "STR_PAD_RIGHT"), (o = e - (t += "").length) > 0 && ("STR_PAD_LEFT" == r ? t = s(n, o) + t : "STR_PAD_RIGHT" == r ? t += s(n, o) : "STR_PAD_BOTH" == r && (t = (t = (i = s(n, Math.ceil(o / 2))) + t + i).substr(0, e))), t;
                },
                zeroFill: function zeroFill(t, e) {
                  return o.util.str_pad(t, e, "0", "STR_PAD_LEFT");
                },
                is_object: function is_object(t) {
                  return !(t instanceof Array) && null !== t && "object" == r(t);
                },
                countObjectProperties: function countObjectProperties(t) {
                  var e,
                      n = 0;

                  for (e in t) {
                    t.hasOwnProperty(e) && n++;
                  }

                  return n;
                },
                jsonFromAssocString: function jsonFromAssocString(t, e, n) {
                  if (e = e || "=>", n = n || "|||", t) {
                    if (!this.strpos(t, e)) return t;

                    for (var r = {}, o = t.split(n), i = 0, s = o.length; i < s; i++) {
                      var a = o[i].split(e);
                      r[a[0]] = a[1];
                    }

                    return r;
                  }
                },
                assocStringFromJson: function assocStringFromJson(t) {
                  var e = "",
                      n = 0,
                      r = o.util.countObjectProperties(t);

                  for (var i in t) {
                    n++, e += i + "=>" + t[i], n < r && (e += "|||");
                  }

                  return e;
                },
                getDomainFromUrl: function getDomainFromUrl(t, e) {
                  var n = t.split(/\/+/g)[1];
                  return !0 === e ? o.util.stripWwwFromDomain(n) : n;
                },
                stripWwwFromDomain: function stripWwwFromDomain(t) {
                  return "www" === t.split(".")[0] ? t.substring(4) : t;
                },
                getCurrentUnixTimestamp: function getCurrentUnixTimestamp() {
                  return Math.round(new Date().getTime() / 1e3);
                },
                generateHash: function generateHash(t) {
                  return this.crc32(t);
                },
                generateRandomGuid: function generateRandomGuid() {
                  return this.getCurrentUnixTimestamp() + "" + o.util.zeroFill(this.rand(0, 999999) + "", 6) + o.util.zeroFill(this.rand(0, 999) + "", 3);
                },
                crc32: function crc32(t) {
                  var e = 0,
                      n = 0;
                  e ^= -1;

                  for (var r = 0, o = (t = this.utf8_encode(t)).length; r < o; r++) {
                    n = 255 & (e ^ t.charCodeAt(r)), e = e >>> 8 ^ "0x" + "00000000 77073096 EE0E612C 990951BA 076DC419 706AF48F E963A535 9E6495A3 0EDB8832 79DCB8A4 E0D5E91E 97D2D988 09B64C2B 7EB17CBD E7B82D07 90BF1D91 1DB71064 6AB020F2 F3B97148 84BE41DE 1ADAD47D 6DDDE4EB F4D4B551 83D385C7 136C9856 646BA8C0 FD62F97A 8A65C9EC 14015C4F 63066CD9 FA0F3D63 8D080DF5 3B6E20C8 4C69105E D56041E4 A2677172 3C03E4D1 4B04D447 D20D85FD A50AB56B 35B5A8FA 42B2986C DBBBC9D6 ACBCF940 32D86CE3 45DF5C75 DCD60DCF ABD13D59 26D930AC 51DE003A C8D75180 BFD06116 21B4F4B5 56B3C423 CFBA9599 B8BDA50F 2802B89E 5F058808 C60CD9B2 B10BE924 2F6F7C87 58684C11 C1611DAB B6662D3D 76DC4190 01DB7106 98D220BC EFD5102A 71B18589 06B6B51F 9FBFE4A5 E8B8D433 7807C9A2 0F00F934 9609A88E E10E9818 7F6A0DBB 086D3D2D 91646C97 E6635C01 6B6B51F4 1C6C6162 856530D8 F262004E 6C0695ED 1B01A57B 8208F4C1 F50FC457 65B0D9C6 12B7E950 8BBEB8EA FCB9887C 62DD1DDF 15DA2D49 8CD37CF3 FBD44C65 4DB26158 3AB551CE A3BC0074 D4BB30E2 4ADFA541 3DD895D7 A4D1C46D D3D6F4FB 4369E96A 346ED9FC AD678846 DA60B8D0 44042D73 33031DE5 AA0A4C5F DD0D7CC9 5005713C 270241AA BE0B1010 C90C2086 5768B525 206F85B3 B966D409 CE61E49F 5EDEF90E 29D9C998 B0D09822 C7D7A8B4 59B33D17 2EB40D81 B7BD5C3B C0BA6CAD EDB88320 9ABFB3B6 03B6E20C 74B1D29A EAD54739 9DD277AF 04DB2615 73DC1683 E3630B12 94643B84 0D6D6A3E 7A6A5AA8 E40ECF0B 9309FF9D 0A00AE27 7D079EB1 F00F9344 8708A3D2 1E01F268 6906C2FE F762575D 806567CB 196C3671 6E6B06E7 FED41B76 89D32BE0 10DA7A5A 67DD4ACC F9B9DF6F 8EBEEFF9 17B7BE43 60B08ED5 D6D6A3E8 A1D1937E 38D8C2C4 4FDFF252 D1BB67F1 A6BC5767 3FB506DD 48B2364B D80D2BDA AF0A1B4C 36034AF6 41047A60 DF60EFC3 A867DF55 316E8EEF 4669BE79 CB61B38C BC66831A 256FD2A0 5268E236 CC0C7795 BB0B4703 220216B9 5505262F C5BA3BBE B2BD0B28 2BB45A92 5CB36A04 C2D7FFA7 B5D0CF31 2CD99E8B 5BDEAE1D 9B64C2B0 EC63F226 756AA39C 026D930A 9C0906A9 EB0E363F 72076785 05005713 95BF4A82 E2B87A14 7BB12BAE 0CB61B38 92D28E9B E5D5BE0D 7CDCEFB7 0BDBDF21 86D3D2D4 F1D4E242 68DDB3F8 1FDA836E 81BE16CD F6B9265B 6FB077E1 18B74777 88085AE6 FF0F6A70 66063BCA 11010B5C 8F659EFF F862AE69 616BFFD3 166CCF45 A00AE278 D70DD2EE 4E048354 3903B3C2 A7672661 D06016F7 4969474D 3E6E77DB AED16A4A D9D65ADC 40DF0B66 37D83BF0 A9BCAE53 DEBB9EC5 47B2CF7F 30B5FFE9 BDBDF21C CABAC28A 53B39330 24B4A3A6 BAD03605 CDD70693 54DE5729 23D967BF B3667A2E C4614AB8 5D681B02 2A6F2B94 B40BBE37 C30C8EA1 5A05DF1B 2D02EF8D".substr(9 * n, 8);
                  }

                  return -1 ^ e;
                },
                utf8_encode: function utf8_encode(t) {
                  var e,
                      n,
                      r,
                      o = t + "",
                      i = "";
                  e = n = 0, r = o.length;

                  for (var s = 0; s < r; s++) {
                    var a = o.charCodeAt(s),
                        u = null;
                    a < 128 ? n++ : u = a > 127 && a < 2048 ? String.fromCharCode(a >> 6 | 192) + String.fromCharCode(63 & a | 128) : String.fromCharCode(a >> 12 | 224) + String.fromCharCode(a >> 6 & 63 | 128) + String.fromCharCode(63 & a | 128), null !== u && (n > e && (i += o.substring(e, n)), i += u, e = n = s + 1);
                  }

                  return n > e && (i += o.substring(e, o.length)), i;
                },
                utf8_decode: function utf8_decode(t) {
                  var e = [],
                      n = 0,
                      r = 0,
                      o = 0,
                      i = 0,
                      s = 0;

                  for (t += ""; n < t.length;) {
                    (o = t.charCodeAt(n)) < 128 ? (e[r++] = String.fromCharCode(o), n++) : o > 191 && o < 224 ? (i = t.charCodeAt(n + 1), e[r++] = String.fromCharCode((31 & o) << 6 | 63 & i), n += 2) : (i = t.charCodeAt(n + 1), s = t.charCodeAt(n + 2), e[r++] = String.fromCharCode((15 & o) << 12 | (63 & i) << 6 | 63 & s), n += 3);
                  }

                  return e.join("");
                },
                trim: function trim(t, e) {
                  var n,
                      r = 0,
                      o = 0;

                  for (t += "", n = e ? (e += "").replace(/([\[\]\(\)\.\?\/\*\{\}\+\$\^\:])/g, "$1") : " \n\r\t\f\x0B\xA0\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200A\u200B\u2028\u2029\u3000", r = t.length, o = 0; o < r; o++) {
                    if (-1 === n.indexOf(t.charAt(o))) {
                      t = t.substring(o);
                      break;
                    }
                  }

                  for (o = (r = t.length) - 1; o >= 0; o--) {
                    if (-1 === n.indexOf(t.charAt(o))) {
                      t = t.substring(0, o + 1);
                      break;
                    }
                  }

                  return -1 === n.indexOf(t.charAt(0)) ? t : "";
                },
                rand: function rand(t, e) {
                  var n = arguments.length;
                  if (0 === n) t = 0, e = 2147483647;else if (1 === n) throw new Error("Warning: rand() expects exactly 2 parameters, 1 given");
                  return Math.floor(Math.random() * (e - t + 1)) + t;
                },
                base64_encode: function base64_encode(t) {
                  var e,
                      n,
                      r,
                      o,
                      i,
                      s = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
                      a = 0,
                      u = 0,
                      c = "",
                      l = [];
                  if (!t) return t;
                  t = this.utf8_encode(t + "");

                  do {
                    e = (i = t.charCodeAt(a++) << 16 | t.charCodeAt(a++) << 8 | t.charCodeAt(a++)) >> 18 & 63, n = i >> 12 & 63, r = i >> 6 & 63, o = 63 & i, l[u++] = s.charAt(e) + s.charAt(n) + s.charAt(r) + s.charAt(o);
                  } while (a < t.length);

                  switch (c = l.join(""), t.length % 3) {
                    case 1:
                      c = c.slice(0, -2) + "==";
                      break;

                    case 2:
                      c = c.slice(0, -1) + "=";
                  }

                  return c;
                },
                base64_decode: function base64_decode(t) {
                  var e,
                      n,
                      r,
                      o,
                      i,
                      s,
                      a,
                      u = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
                      c = 0,
                      l = 0,
                      h = [];
                  if (!t) return t;
                  t += "";

                  do {
                    e = (s = u.indexOf(t.charAt(c++)) << 18 | u.indexOf(t.charAt(c++)) << 12 | (o = u.indexOf(t.charAt(c++))) << 6 | (i = u.indexOf(t.charAt(c++)))) >> 16 & 255, n = s >> 8 & 255, r = 255 & s, h[l++] = 64 == o ? String.fromCharCode(e) : 64 == i ? String.fromCharCode(e, n) : String.fromCharCode(e, n, r);
                  } while (c < t.length);

                  return a = h.join(""), this.utf8_decode(a);
                },
                sprintf: function sprintf() {
                  var t = /%%|%(\d+\$)?([-+\'#0 ]*)(\*\d+\$|\*|\d+)?(\.(\*\d+\$|\*|\d+))?([scboxXuidfegEG])/g,
                      e = arguments,
                      n = 0,
                      r = e[n++],
                      o = function o(t, e, n, r) {
                    n || (n = " ");
                    var o = t.length >= e ? "" : Array(1 + e - t.length >>> 0).join(n);
                    return r ? t + o : o + t;
                  },
                      i = function i(t, e, n, r, _i4, s) {
                    var a = r - t.length;
                    return a > 0 && (t = n || !_i4 ? o(t, r, s, n) : t.slice(0, e.length) + o("", a, "0", !0) + t.slice(e.length)), t;
                  },
                      s = function s(t, e, n, r, _s4, a, u) {
                    var c = t >>> 0;
                    return t = (n = n && c && {
                      2: "0b",
                      8: "0",
                      16: "0x"
                    }[e] || "") + o(c.toString(e), a || 0, "0", !1), i(t, n, r, _s4, u);
                  },
                      a = function a(t, e, n, r, o, s) {
                    return null != r && (t = t.slice(0, r)), i(t, "", e, n, o, s);
                  },
                      u = function u(t, r, _u4, c, l, h, g) {
                    var f, d, p, m, C;
                    if ("%%" == t) return "%";

                    for (var A = !1, y = "", D = !1, v = !1, S = " ", b = _u4.length, E = 0; _u4 && E < b; E++) {
                      switch (_u4.charAt(E)) {
                        case " ":
                          y = " ";
                          break;

                        case "+":
                          y = "+";
                          break;

                        case "-":
                          A = !0;
                          break;

                        case "'":
                          S = _u4.charAt(E + 1);
                          break;

                        case "0":
                          D = !0;
                          break;

                        case "#":
                          v = !0;
                      }
                    }

                    if ((c = c ? "*" == c ? +e[n++] : "*" == c.charAt(0) ? +e[c.slice(1, -1)] : +c : 0) < 0 && (c = -c, A = !0), !isFinite(c)) throw new Error("sprintf: (minimum-)width must be finite");

                    switch (h = h ? "*" == h ? +e[n++] : "*" == h.charAt(0) ? +e[h.slice(1, -1)] : +h : "fFeE".indexOf(g) > -1 ? 6 : "d" == g ? 0 : void 0, C = r ? e[r.slice(0, -1)] : e[n++], g) {
                      case "s":
                        return a(String(C), A, c, h, D, S);

                      case "c":
                        return a(String.fromCharCode(+C), A, c, h, D);

                      case "b":
                        return s(C, 2, v, A, c, h, D);

                      case "o":
                        return s(C, 8, v, A, c, h, D);

                      case "x":
                        return s(C, 16, v, A, c, h, D);

                      case "X":
                        return s(C, 16, v, A, c, h, D).toUpperCase();

                      case "u":
                        return s(C, 10, v, A, c, h, D);

                      case "i":
                      case "d":
                        return C = (d = (f = parseInt(+C, 10)) < 0 ? "-" : y) + o(String(Math.abs(f)), h, "0", !1), i(C, d, A, c, D);

                      case "e":
                      case "E":
                      case "f":
                      case "F":
                      case "g":
                      case "G":
                        return d = (f = +C) < 0 ? "-" : y, p = ["toExponential", "toFixed", "toPrecision"]["efg".indexOf(g.toLowerCase())], m = ["toString", "toUpperCase"]["eEfFgG".indexOf(g) % 2], C = d + Math.abs(f)[p](h), i(C, d, A, c, D)[m]();

                      default:
                        return t;
                    }
                  };

                  return r.replace(t, u);
                },
                clone: function clone(t) {
                  var e = t instanceof Array ? [] : {};

                  for (var n in t) {
                    t[n] && "object" == r(t[n]) ? e[n] = o.util.clone(t[n]) : e[n] = t[n];
                  }

                  return e;
                },
                strtolower: function strtolower(t) {
                  return (t + "").toLowerCase();
                },
                in_array: function in_array(t, e, n) {
                  var r = "";

                  if (n) {
                    for (r in e) {
                      if (e[r] === t) return !0;
                    }
                  } else for (r in e) {
                    if (e[r] == t) return !0;
                  }

                  return !1;
                },
                dechex: function dechex(t) {
                  return t < 0 && (t = 4294967295 + t + 1), parseInt(t, 10).toString(16);
                },
                explode: function explode(t, e, n) {
                  var o = {
                    0: ""
                  };
                  if (arguments.length < 2 || void 0 === arguments[0] || void 0 === arguments[1]) return null;
                  if ("" === t || !1 === t || null === t) return !1;
                  if ("function" == typeof t || "object" == r(t) || "function" == typeof e || "object" == r(e)) return o;

                  if (!0 === t && (t = "1"), n) {
                    var i = e.toString().split(t.toString()),
                        s = i.splice(0, n - 1),
                        a = i.join(t.toString());
                    return s.push(a), s;
                  }

                  return e.toString().split(t.toString());
                },
                isIE: function isIE() {
                  if (/MSIE (\d+\.\d+);/.test(navigator.userAgent)) return !0;
                },
                getInternetExplorerVersion: function getInternetExplorerVersion() {
                  var t = -1;

                  if ("Microsoft Internet Explorer" == navigator.appName) {
                    var e = navigator.userAgent;
                    null != new RegExp("MSIE ([0-9]{1,}[.0-9]{0,})").exec(e) && (t = parseFloat(RegExp.$1));
                  }

                  return t;
                },
                isBrowserTrackable: function isBrowserTrackable() {
                  for (var t = ["doNotTrack", "msDoNotTrack"], e = 0, n = t.length; e < n; e++) {
                    if (navigator[t[e]] && "1" == navigator[t[e]]) return !1;
                  }

                  return !0;
                }
              };
            }, function (t, e, n) {
              n(0), n(2), t.exports = n(11);
            }, function (t, e) {
              function r(t) {
                return (r = "function" == typeof Symbol && "symbol" == n(Symbol.iterator) ? function (t) {
                  return n(t);
                } : function (t) {
                  return t && "function" == typeof Symbol && t.constructor === Symbol && t !== Symbol.prototype ? "symbol" : n(t);
                })(t);
              }

              !function (t) {
                var e = {};

                function n(r) {
                  if (e[r]) return e[r].exports;
                  var o = e[r] = {
                    i: r,
                    l: !1,
                    exports: {}
                  };
                  return t[r].call(o.exports, o, o.exports, n), o.l = !0, o.exports;
                }

                n.m = t, n.c = e, n.d = function (t, e, r) {
                  n.o(t, e) || Object.defineProperty(t, e, {
                    enumerable: !0,
                    get: r
                  });
                }, n.r = function (t) {
                  "undefined" != typeof Symbol && Symbol.toStringTag && Object.defineProperty(t, Symbol.toStringTag, {
                    value: "Module"
                  }), Object.defineProperty(t, "__esModule", {
                    value: !0
                  });
                }, n.t = function (t, e) {
                  if (1 & e && (t = n(t)), 8 & e) return t;
                  if (4 & e && "object" == r(t) && t && t.__esModule) return t;
                  var o = Object.create(null);
                  if (n.r(o), Object.defineProperty(o, "default", {
                    enumerable: !0,
                    value: t
                  }), 2 & e && "string" != typeof t) for (var i in t) {
                    n.d(o, i, function (e) {
                      return t[e];
                    }.bind(null, i));
                  }
                  return o;
                }, n.n = function (t) {
                  var e = t && t.__esModule ? function () {
                    return t["default"];
                  } : function () {
                    return t;
                  };
                  return n.d(e, "a", e), e;
                }, n.o = function (t, e) {
                  return Object.prototype.hasOwnProperty.call(t, e);
                }, n.p = "/", n(n.s = 1);
              }([function (t, e) {
                function n(t) {
                  return (n = "function" == typeof Symbol && "symbol" == r(Symbol.iterator) ? function (t) {
                    return r(t);
                  } : function (t) {
                    return t && "function" == typeof Symbol && t.constructor === Symbol && t !== Symbol.prototype ? "symbol" : r(t);
                  })(t);
                }

                var o = {
                  items: {},
                  hooks: {
                    actions: {},
                    filters: {}
                  },
                  loadedJsLibs: {},
                  overlay: "",
                  config: {
                    ns: "owa_",
                    baseUrl: "",
                    hashCookiesToDomain: !0,
                    debug: !1
                  },
                  state: {},
                  overlayActive: !1,
                  setSetting: function setSetting(t, e) {
                    return this.setOption(t, e);
                  },
                  getSetting: function getSetting(t) {
                    return this.getOption(t);
                  },
                  setOption: function setOption(t, e) {
                    this.config[t] = e;
                  },
                  getOption: function getOption(t) {
                    return this.config[t];
                  },
                  l: function l(t) {
                    return t;
                  },
                  requireJs: function requireJs(t, e, n) {
                    this.isJsLoaded(t) || o.util.loadScript(e, n), this.loadedJsLibs[t] = e;
                  },
                  isJsLoaded: function isJsLoaded(t) {
                    if (this.loadedJsLibs.hasOwnProperty(t)) return !0;
                  },
                  initializeStateManager: function initializeStateManager() {
                    this.state.hasOwnProperty("init") || (o.debug("initializing state manager..."), this.state = new o.stateManager());
                  },
                  registerStateStore: function registerStateStore(t, e, n, r) {
                    return this.initializeStateManager(), this.state.registerStore(t, e, n, r);
                  },
                  checkForState: function checkForState(t) {
                    return this.initializeStateManager(), this.state.isPresent(t);
                  },
                  setState: function setState(t, e, n, r, o, i) {
                    return this.initializeStateManager(), this.state.set(t, e, n, r, o, i);
                  },
                  replaceState: function replaceState(t, e, n, r, o) {
                    return this.initializeStateManager(), this.state.replaceStore(t, e, n, r, o);
                  },
                  getStateFromCookie: function getStateFromCookie(t) {
                    return this.initializeStateManager(), this.state.getStateFromCookie(t);
                  },
                  getState: function getState(t, e) {
                    return this.initializeStateManager(), this.state.get(t, e);
                  },
                  clearState: function clearState(t, e) {
                    return this.initializeStateManager(), this.state.clear(t, e);
                  },
                  getStateStoreFormat: function getStateStoreFormat(t) {
                    return this.initializeStateManager(), this.state.getStoreFormat(t);
                  },
                  setStateStoreFormat: function setStateStoreFormat(t, e) {
                    return this.initializeStateManager(), this.state.setStoreFormat(t, e);
                  },
                  debug: function debug() {
                    var t = o.getSetting("debug") || !1;
                    t && window.console && console.log.apply && (window.console.firebug ? console.log.apply(this, arguments) : console.log.apply(console, arguments));
                  },
                  setApiEndpoint: function setApiEndpoint(t) {
                    this.config.rest_api_endpoint = t;
                  },
                  getApiEndpoint: function getApiEndpoint() {
                    return this.config.rest_api_endpoint || this.getSetting("baseUrl") + "api/";
                  },
                  loadHeatmap: function loadHeatmap(t) {
                    var e = this;
                    o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/includes/jquery/jquery-1.6.4.min.js", function () {}), o.util.loadCss(o.getSetting("baseUrl") + "/modules/base/css/owa.overlay.css", function () {}), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/owa.heatmap.js", function () {
                      e.overlay = new o.heatmap(), e.overlay.options.liveMode = !0, e.overlay.generate();
                    });
                  },
                  loadPlayer: function loadPlayer() {
                    var t = this;
                    o.debug("Loading Domstream Player"), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/includes/jquery/jquery-1.6.4.min.js", function () {}), o.util.loadCss(o.getSetting("baseUrl") + "/modules/base/css/owa.overlay.css", function () {}), o.util.loadScript(o.getSetting("baseUrl") + "/modules/base/js/owa.player.js", function () {
                      t.overlay = new o.player();
                    });
                  },
                  startOverlaySession: function startOverlaySession(t) {
                    o.overlayActive = !0, t.hasOwnProperty("api_url") && o.setApiEndpoint(t.api_url);
                    var e = t;
                    "loadHeatmap" === e.action ? this.loadHeatmap(t) : "loadPlayer" === e.action && this.loadPlayer(t);
                  },
                  endOverlaySession: function endOverlaySession() {
                    o.util.eraseCookie(o.getSetting("ns") + "overlay", document.domain), o.overlayActive = !1;
                  },
                  addFilter: function addFilter(t, e, n) {
                    void 0 === n && (n = 10), this.hooks.filters[t] = this.hooks.filters[t] || [], this.hooks.filters[t].push({
                      priority: n,
                      callback: e
                    });
                  },
                  addAction: function addAction(t, e, n) {
                    o.debug("Adding Action callback for: " + t), void 0 === n && (n = 10), this.hooks.actions[t] = this.hooks.actions[t] || [], this.hooks.actions[t].push({
                      priority: n,
                      callback: e
                    });
                  },
                  applyFilters: function applyFilters(t, e, n) {
                    o.debug("Filtering " + t + " with value:"), o.debug(e);
                    var r = [];
                    return void 0 !== this.hooks.filters[t] && this.hooks.filters[t].length > 0 && (o.debug("Applying filters for " + t), this.hooks.filters[t].forEach(function (t) {
                      r[t.priority] = r[t.priority] || [], r[t.priority].push(t.callback);
                    }), r.forEach(function (t) {
                      t.forEach(function (t) {
                        e = t(e, n), o.debug("Filter returned value: "), o.debug(e);
                      });
                    })), e;
                  },
                  doAction: function doAction(t, e) {
                    o.debug("Doing Action: " + t);
                    var n = [];
                    void 0 !== this.hooks.actions[t] && this.hooks.actions[t].length > 0 && (o.debug(this.hooks.actions[t]), this.hooks.actions[t].forEach(function (t) {
                      n[t.priority] = n[t.priority] || [], n[t.priority].push(t.callback);
                    }), n.forEach(function (n) {
                      o.debug("Executing Action callabck for: " + t), n.forEach(function (t) {
                        t(e);
                      });
                    }));
                  },
                  removeAction: function removeAction(t, e) {
                    this.hooks.actions[t] = this.hooks.actions[t] || [], this.hooks.actions[t].forEach(function (n, r) {
                      n.callback === e && this.hooks.actions[t].splice(r, 1);
                    });
                  },
                  removeFilter: function removeFilter(t, e) {
                    this.hooks.filters[t] = this.hooks.filters[t] || [], this.hooks.filters[t].forEach(function (e, n) {
                      e.callback === callback && this.hooks.filters[t].splice(n, 1);
                    });
                  },
                  stateManager: function stateManager() {
                    this.cookies = o.util.readAllCookies(), this.init = !0;
                  }
                };
                o.stateManager.prototype = {
                  init: !1,
                  cookies: "",
                  stores: {},
                  storeFormats: {},
                  storeMeta: {},
                  registerStore: function registerStore(t, e, n, r) {
                    this.storeMeta[t] = {
                      expiration: e,
                      length: n,
                      format: r
                    };
                  },
                  getExpirationDays: function getExpirationDays(t) {
                    if (this.storeMeta.hasOwnProperty(t)) return this.storeMeta[t].expiration;
                  },
                  getFormat: function getFormat(t) {
                    if (this.storeMeta.hasOwnProperty(t)) return this.storeMeta[t].format;
                  },
                  isPresent: function isPresent(t) {
                    if (this.stores.hasOwnProperty(t)) return !0;
                  },
                  set: function set(t, e, n, r, i, s) {
                    var a;
                    this.isPresent(t) || this.load(t), this.isPresent(t) || (o.debug("Creating state store (%s)", t), this.stores[t] = {}, o.getSetting("hashCookiesToDomain") && (this.stores[t].cdh = o.util.getCookieDomainHash(o.getSetting("cookie_domain")))), e ? this.stores[t][e] = n : this.stores[t] = n, (i = this.getFormat(t)) || this.storeFormats.hasOwnProperty(t) && (i = this.storeFormats[t]), a = "json" === i ? JSON.stringify(this.stores[t]) : o.util.assocStringFromJson(this.stores[t]), (s = this.getExpirationDays(t)) || r && (s = 364), o.debug("Populating state store (%s) with value: %s", t, a);
                    var u = o.getSetting("cookie_domain") || document.domain;
                    o.util.setCookie(o.getSetting("ns") + t, a, s, "/", u);
                  },
                  replaceStore: function replaceStore(t, e, n, r, i) {
                    if (o.debug("replace state format: %s, value: %s", r, JSON.stringify(e)), t) {
                      e && (r = this.getFormat(t), this.stores[t] = e, this.storeFormats[t] = r, cookie_value = "json" === r ? JSON.stringify(e) : o.util.assocStringFromJson(e));
                      var s = o.getSetting("cookie_domain") || document.domain;
                      i = this.getExpirationDays(t), o.debug("About to replace state store (%s) with: %s", t, cookie_value), o.util.setCookie(o.getSetting("ns") + t, cookie_value, i, "/", s);
                    }
                  },
                  getStateFromCookie: function getStateFromCookie(t) {
                    var e = unescape(o.util.readCookie(o.getSetting("ns") + t));
                    if (e) return e;
                  },
                  get: function get(t, e) {
                    return this.isPresent(t) || this.load(t), this.isPresent(t) ? e ? this.stores[t].hasOwnProperty(e) ? this.stores[t][e] : void 0 : this.stores[t] : (o.debug("No state store (%s) was found", t), "");
                  },
                  getCookieValues: function getCookieValues(t) {
                    if (this.cookies.hasOwnProperty(t)) return this.cookies[t];
                  },
                  load: function load(t) {
                    var e = "",
                        n = this.getCookieValues(o.getSetting("ns") + t);
                    if (n) for (var r = 0; r < n.length; r++) {
                      var i = unescape(n[r]),
                          s = o.util.decodeCookieValue(i),
                          a = o.util.getCookieValueFormat(i);

                      if (o.getSetting("hashCookiesToDomain")) {
                        var u = o.getSetting("cookie_domain"),
                            c = o.util.getCookieDomainHash(u);

                        if (s.hasOwnProperty("cdh")) {
                          if (o.debug("Cookie value cdh: %s, domain hash: %s", s.cdh, c), s.cdh == c) {
                            o.debug("Cookie: %s, index: %s domain hash matches current cookie domain. Loading...", t, r), e = s;
                            break;
                          }

                          o.debug("Cookie: %s, index: %s domain hash does not match current cookie domain. Not loading.", t, r);
                        } else o.debug("Cookie: %s, index: %s has no domain hash. Not going to Load it.", t, r);
                      } else r === n.length - 1 && (e = s);
                    }
                    e ? (this.stores[t] = e, this.storeFormats[t] = a, o.debug("Loaded state store: %s with: %s", t, JSON.stringify(e))) : o.debug("No state for store: %s was found. Nothing to Load.", t);
                  },
                  clear: function clear(t, e) {
                    if (e) {
                      var n = this.get(t);
                      n && n.hasOwnProperty(e) && (delete n.key, this.replaceStore(t, n, !0, this.getFormat(t), this.getExpirationDays(t)));
                    } else delete this.stores[t], o.util.eraseCookie(o.getSetting("ns") + t), this.cookies = o.util.readAllCookies();
                  },
                  getStoreFormat: function getStoreFormat(t) {
                    return this.getFormat(t);
                  },
                  setStoreFormat: function setStoreFormat(t, e) {
                    this.storeFormats[t] = e;
                  }
                }, o.uri = function (t) {
                  this.components = {}, this.dirty = !1, this.options = {
                    strictMode: !1,
                    key: ["source", "protocol", "authority", "userInfo", "user", "password", "host", "port", "relative", "path", "directory", "file", "query", "anchor"],
                    q: {
                      name: "queryKey",
                      parser: /(?:^|&)([^&=]*)=?([^&]*)/g
                    },
                    parser: {
                      strict: /^(?:([^:\/?#]+):)?(?:\/\/((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?))?((((?:[^?#\/]*\/)*)([^?#]*))(?:\?([^#]*))?(?:#(.*))?)/,
                      loose: /^(?:(?![^:@]+:[^:@\/]*@)([^:\/?#.]+):)?(?:\/\/)?((?:(([^:@]*)(?::([^:@]*))?)?@)?([^:\/?#]*)(?::(\d*))?)(((\/(?:[^?#](?![^?#\/]*\.[^?#\/.]+(?:[?#]|$)))*\/?)?([^?#\/]*))(?:\?([^#]*))?(?:#(.*))?)/
                    }
                  }, t && (this.components = this.parseUri(t));
                }, o.uri.prototype = {
                  parseUri: function parseUri(t) {
                    for (var e = this.options, n = e.parser[e.strictMode ? "strict" : "loose"].exec(t), r = {}, o = 14; o--;) {
                      r[e.key[o]] = n[o] || "";
                    }

                    return r[e.q.name] = {}, r[e.key[12]].replace(e.q.parser, function (t, n, o) {
                      n && (r[e.q.name][n] = o);
                    }), r;
                  },
                  getHost: function getHost() {
                    if (this.components.hasOwnProperty("host")) return this.components.host;
                  },
                  getQueryParam: function getQueryParam(t) {
                    if (this.components.hasOwnProperty("queryKey") && this.components.queryKey.hasOwnProperty(t)) return o.util.urldecode(this.components.queryKey[t]);
                  },
                  isQueryParam: function isQueryParam(t) {
                    return !(!this.components.hasOwnProperty("queryKey") || !this.components.queryKey.hasOwnProperty(t));
                  },
                  getComponent: function getComponent(t) {
                    if (this.components.hasOwnProperty(t)) return this.components[t];
                  },
                  getProtocol: function getProtocol() {
                    return this.getComponent("protocol");
                  },
                  getAnchor: function getAnchor() {
                    return this.getComponent("anchor");
                  },
                  getQuery: function getQuery() {
                    return this.getComponent("query");
                  },
                  getFile: function getFile() {
                    return this.getComponent("file");
                  },
                  getRelative: function getRelative() {
                    return this.getComponent("relative");
                  },
                  getDirectory: function getDirectory() {
                    return this.getComponent("directory");
                  },
                  getPath: function getPath() {
                    return this.getComponent("path");
                  },
                  getPort: function getPort() {
                    return this.getComponent("port");
                  },
                  getPassword: function getPassword() {
                    return this.getComponent("password");
                  },
                  getUser: function getUser() {
                    return this.getComponent("user");
                  },
                  getUserInfo: function getUserInfo() {
                    return this.getComponent("userInfo");
                  },
                  getQueryParams: function getQueryParams() {
                    return this.getComponent("queryKey");
                  },
                  getSource: function getSource() {
                    return this.getComponent("source");
                  },
                  setQueryParam: function setQueryParam(t, e) {
                    this.components.hasOwnProperty("queryKey") || (this.components.queryKey = {}), this.components.queryKey[t] = o.util.urlEncode(e), this.resetQuery();
                  },
                  removeQueryParam: function removeQueryParam(t) {
                    this.components.hasOwnProperty("queryKey") && this.components.queryKey.hasOwnProperty(t) && (delete this.components.queryKey[t], this.resetQuery());
                  },
                  resetSource: function resetSource() {
                    this.components.source = this.assembleUrl();
                  },
                  resetQuery: function resetQuery() {
                    var t = this.getQueryParams();

                    if (t) {
                      var e = "",
                          n = o.util.countObjectProperties(t);

                      for (var r in t) {
                        e += r + "=" + t[r], 1 < n && (e += "&");
                      }

                      this.components.query = e, this.resetSource();
                    }
                  },
                  isDirty: function isDirty() {
                    return this.dirty;
                  },
                  setPath: function setPath(t) {},
                  assembleUrl: function assembleUrl() {
                    var t = "";
                    t += this.getProtocol(), t += "://", this.getUser() && (t += this.getUser()), this.getUser() && this.getPassword() && (t += ":" + this.password()), t += this.getHost(), this.getPort() && (t += ":" + this.getPort()), t += this.getDirectory(), t += this.getFile();
                    var e = this.getQuery();
                    e && (t += "?" + e);
                    var n = this.getAnchor();
                    return n && (t += "#" + n), t + this.getAnchor();
                  }
                }, o.util = {
                  ns: function ns(t) {
                    return o.config.ns + t;
                  },
                  nsAll: function nsAll(t) {
                    var e = new Object();

                    for (param in t) {
                      t.hasOwnProperty(param) && (e[o.config.ns + param] = t[param]);
                    }

                    return e;
                  },
                  getScript: function getScript(t, e) {
                    jQuery.getScript(e + t);
                  },
                  makeUrl: function makeUrl(t, e, n) {
                    return jQuery.sprintf(t, e, jQuery.param(o.util.nsAll(n)));
                  },
                  createCookie: function createCookie(t, e, n, r) {
                    if (n) {
                      var o = new Date();
                      o.setTime(o.getTime() + 24 * n * 60 * 60 * 1e3);
                      var i = "; expires=" + o.toGMTString();
                    } else i = "";

                    document.cookie = t + "=" + e + i + "; path=/";
                  },
                  setCookie: function setCookie(t, e, n, r, o, i) {
                    var s = new Date();
                    s.setTime(s.getTime() + 24 * n * 60 * 60 * 1e3), document.cookie = t + "=" + escape(e) + (n ? "; expires=" + s.toGMTString() : "") + (r ? "; path=" + r : "") + (o ? "; domain=" + o : "") + (i ? "; secure" : "");
                  },
                  readAllCookies: function readAllCookies() {
                    o.debug("Reading all cookies...");
                    var t = {},
                        e = document.cookie.split(";");

                    if (e) {
                      o.debug(document.cookie);

                      for (var n = 0; n < e.length; n++) {
                        var r = o.util.trim(e[n]),
                            i = o.util.strpos(r, "="),
                            s = r.substring(0, i),
                            a = r.substring(i + 1, r.length);
                        t.hasOwnProperty(s) || (t[s] = []), t[s].push(a);
                      }

                      return o.debug(JSON.stringify(t)), t;
                    }
                  },
                  readCookie: function readCookie(t) {
                    o.debug("Attempting to read cookie: %s", t);
                    var e = o.util.readAllCookies();
                    if (e) return e.hasOwnProperty(t) ? e[t] : "";
                  },
                  eraseCookie: function eraseCookie(t, e) {
                    if (o.debug(document.cookie), e || (e = o.getSetting("cookie_domain") || document.domain), o.debug("erasing cookie: " + t + " in domain: " + e), this.setCookie(t, "", -1, "/", e), o.util.readCookie(t)) {
                      var n = e.substr(0, 1);

                      if (o.debug("period: " + n), "." === n) {
                        var r = e.substr(1);
                        o.debug("erasing " + t + " in domain2: " + r), this.setCookie(t, "", -2, "/", r);
                      } else o.debug("erasing " + t + " in domain3: " + e), this.setCookie(t, "", -2, "/", e);
                    }
                  },
                  eraseMultipleCookies: function eraseMultipleCookies(t, e) {
                    for (var n = 0; n < t.length; n++) {
                      this.eraseCookie(t[n], e);
                    }
                  },
                  loadScript: function loadScript(t, e) {
                    var n = document.createElement("script");
                    n.type = "text/javascript", n.readyState ? n.onreadystatechange = function () {
                      "loaded" != n.readyState && "complete" != n.readyState || (n.onreadystatechange = null, e());
                    } : n.onload = function () {
                      e();
                    }, n.src = t, document.getElementsByTagName("head")[0].appendChild(n);
                  },
                  loadCss: function loadCss(t, e) {
                    var n = document.createElement("link");
                    n.rel = "stylesheet", n.type = "text/css", n.href = t, document.getElementsByTagName("HEAD")[0].appendChild(n);
                  },
                  parseCookieString: function parseCookieString(t) {
                    var e = new Array(),
                        n = unescape(t).split("|||");

                    for (var r in n) {
                      if (n.hasOwnProperty(r)) {
                        var o = n[r].split("=>");
                        e[o[0]] = o[1];
                      }
                    }

                    return e;
                  },
                  parseCookieStringToJson: function parseCookieStringToJson(t) {
                    var e = new Object(),
                        n = unescape(t).split("|||");

                    for (var r in n) {
                      if (n.hasOwnProperty(r)) {
                        var o = n[r].split("=>");
                        e[o[0]] = o[1];
                      }
                    }

                    return e;
                  },
                  nsParams: function nsParams(t) {
                    var e = new Object();

                    for (param in t) {
                      t.hasOwnProperty(param) && (e[o.getSetting("ns") + param] = t[param]);
                    }

                    return e;
                  },
                  urlEncode: function urlEncode(t) {
                    return t = (t + "").toString(), encodeURIComponent(t).replace(/!/g, "%21").replace(/'/g, "%27").replace(/\(/g, "%28").replace(/\)/g, "%29").replace(/\*/g, "%2A").replace(/%20/g, "+");
                  },
                  urldecode: function urldecode(t) {
                    return decodeURIComponent(t.replace(/\+/g, "%20"));
                  },
                  parseUrlParams: function parseUrlParams(t) {
                    for (var e, n, r, o, i, s, a = {}, u = location.href.split(/[?&]/), c = u.length, l = 1; l < c; l++) {
                      if ((r = u[l].match(/(.*?)(\..*?|\[.*?\])?=([^#]*)/)) && 4 == r.length) {
                        if (o = decodeURI(r[1]).toLowerCase(), i = a, s = decodeURI(r[3]), r[2]) for (n = decodeURI(r[2]).replace(/\[\s*\]/g, "[-1]").split(/[\.\[\]]/), e = 0; e < n.length; e++) {
                          i = i[o] ? i[o] : i[o] = parseInt(n[e]) == n[e] ? [] : {}, o = n[e].replace(/^["\'](.*)["\']$/, "$1");
                        }
                        "-1" != o ? i[o] = s : i[i.length] = s;
                      }
                    }

                    return a;
                  },
                  strpos: function strpos(t, e, n) {
                    var r = (t + "").indexOf(e, n || 0);
                    return -1 !== r && r;
                  },
                  strCountOccurances: function strCountOccurances(t, e) {
                    return t.split(e).length - 1;
                  },
                  implode: function implode(t, e) {
                    var r = "",
                        o = "",
                        i = "";

                    if (1 === arguments.length && (e = t, t = ""), "object" === n(e)) {
                      if (e instanceof Array) return e.join(t);

                      for (r in e) {
                        o += i + e[r], i = t;
                      }

                      return o;
                    }

                    return e;
                  },
                  checkForState: function checkForState(t) {
                    return o.checkForState(t);
                  },
                  setState: function setState(t, e, n, r, i, s) {
                    return o.setState(t, e, n, r, i, s);
                  },
                  replaceState: function replaceState(t, e, n, r, i) {
                    return o.replaceState(t, e, n, r, i);
                  },
                  getRawState: function getRawState(t) {
                    return o.getStateFromCookie(t);
                  },
                  getState: function getState(t, e) {
                    return o.getState(t, e);
                  },
                  clearState: function clearState(t, e) {
                    return o.clearState(t, e);
                  },
                  getCookieValueFormat: function getCookieValueFormat(t) {
                    return "{" === t.substr(0, 1) ? "json" : "assoc";
                  },
                  decodeCookieValue: function decodeCookieValue(t) {
                    var e,
                        n = o.util.getCookieValueFormat(t);
                    return e = "json" === n ? JSON.parse(t) : o.util.jsonFromAssocString(t), o.debug("decodeCookieValue - string: %s, format: %s, value: %s", t, n, JSON.stringify(e)), e;
                  },
                  encodeJsonForCookie: function encodeJsonForCookie(t, e) {
                    return "json" === (e = e || "assoc") ? JSON.stringify(t) : o.util.assocStringFromJson(t);
                  },
                  getCookieDomainHash: function getCookieDomainHash(t) {
                    return o.util.dechex(o.util.crc32(t));
                  },
                  loadStateJson: function loadStateJson(t) {
                    var e = unescape(o.util.readCookie(o.getSetting("ns") + t));
                    e && (state = JSON.parse(e)), o.state[t] = state, o.debug("state store %s: %s", t, JSON.stringify(state));
                  },
                  is_array: function is_array(t) {
                    return "object" == n(t) && t instanceof Array;
                  },
                  str_pad: function str_pad(t, e, n, r) {
                    var o,
                        i = "",
                        s = function s(t, e) {
                      for (var n = ""; n.length < e;) {
                        n += t;
                      }

                      return n.substr(0, e);
                    };

                    return n = void 0 !== n ? n : " ", "STR_PAD_LEFT" != r && "STR_PAD_RIGHT" != r && "STR_PAD_BOTH" != r && (r = "STR_PAD_RIGHT"), (o = e - (t += "").length) > 0 && ("STR_PAD_LEFT" == r ? t = s(n, o) + t : "STR_PAD_RIGHT" == r ? t += s(n, o) : "STR_PAD_BOTH" == r && (t = (t = (i = s(n, Math.ceil(o / 2))) + t + i).substr(0, e))), t;
                  },
                  zeroFill: function zeroFill(t, e) {
                    return o.util.str_pad(t, e, "0", "STR_PAD_LEFT");
                  },
                  is_object: function is_object(t) {
                    return !(t instanceof Array) && null !== t && "object" == n(t);
                  },
                  countObjectProperties: function countObjectProperties(t) {
                    var e,
                        n = 0;

                    for (e in t) {
                      t.hasOwnProperty(e) && n++;
                    }

                    return n;
                  },
                  jsonFromAssocString: function jsonFromAssocString(t, e, n) {
                    if (e = e || "=>", n = n || "|||", t) {
                      if (!this.strpos(t, e)) return t;

                      for (var r = {}, o = t.split(n), i = 0, s = o.length; i < s; i++) {
                        var a = o[i].split(e);
                        r[a[0]] = a[1];
                      }

                      return r;
                    }
                  },
                  assocStringFromJson: function assocStringFromJson(t) {
                    var e = "",
                        n = 0,
                        r = o.util.countObjectProperties(t);

                    for (var i in t) {
                      n++, e += i + "=>" + t[i], n < r && (e += "|||");
                    }

                    return e;
                  },
                  getDomainFromUrl: function getDomainFromUrl(t, e) {
                    var n = t.split(/\/+/g)[1];
                    return !0 === e ? o.util.stripWwwFromDomain(n) : n;
                  },
                  stripWwwFromDomain: function stripWwwFromDomain(t) {
                    return "www" === t.split(".")[0] ? t.substring(4) : t;
                  },
                  getCurrentUnixTimestamp: function getCurrentUnixTimestamp() {
                    return Math.round(new Date().getTime() / 1e3);
                  },
                  generateHash: function generateHash(t) {
                    return this.crc32(t);
                  },
                  generateRandomGuid: function generateRandomGuid() {
                    return this.getCurrentUnixTimestamp() + "" + o.util.zeroFill(this.rand(0, 999999) + "", 6) + o.util.zeroFill(this.rand(0, 999) + "", 3);
                  },
                  crc32: function crc32(t) {
                    var e = 0,
                        n = 0;
                    e ^= -1;

                    for (var r = 0, o = (t = this.utf8_encode(t)).length; r < o; r++) {
                      n = 255 & (e ^ t.charCodeAt(r)), e = e >>> 8 ^ "0x" + "00000000 77073096 EE0E612C 990951BA 076DC419 706AF48F E963A535 9E6495A3 0EDB8832 79DCB8A4 E0D5E91E 97D2D988 09B64C2B 7EB17CBD E7B82D07 90BF1D91 1DB71064 6AB020F2 F3B97148 84BE41DE 1ADAD47D 6DDDE4EB F4D4B551 83D385C7 136C9856 646BA8C0 FD62F97A 8A65C9EC 14015C4F 63066CD9 FA0F3D63 8D080DF5 3B6E20C8 4C69105E D56041E4 A2677172 3C03E4D1 4B04D447 D20D85FD A50AB56B 35B5A8FA 42B2986C DBBBC9D6 ACBCF940 32D86CE3 45DF5C75 DCD60DCF ABD13D59 26D930AC 51DE003A C8D75180 BFD06116 21B4F4B5 56B3C423 CFBA9599 B8BDA50F 2802B89E 5F058808 C60CD9B2 B10BE924 2F6F7C87 58684C11 C1611DAB B6662D3D 76DC4190 01DB7106 98D220BC EFD5102A 71B18589 06B6B51F 9FBFE4A5 E8B8D433 7807C9A2 0F00F934 9609A88E E10E9818 7F6A0DBB 086D3D2D 91646C97 E6635C01 6B6B51F4 1C6C6162 856530D8 F262004E 6C0695ED 1B01A57B 8208F4C1 F50FC457 65B0D9C6 12B7E950 8BBEB8EA FCB9887C 62DD1DDF 15DA2D49 8CD37CF3 FBD44C65 4DB26158 3AB551CE A3BC0074 D4BB30E2 4ADFA541 3DD895D7 A4D1C46D D3D6F4FB 4369E96A 346ED9FC AD678846 DA60B8D0 44042D73 33031DE5 AA0A4C5F DD0D7CC9 5005713C 270241AA BE0B1010 C90C2086 5768B525 206F85B3 B966D409 CE61E49F 5EDEF90E 29D9C998 B0D09822 C7D7A8B4 59B33D17 2EB40D81 B7BD5C3B C0BA6CAD EDB88320 9ABFB3B6 03B6E20C 74B1D29A EAD54739 9DD277AF 04DB2615 73DC1683 E3630B12 94643B84 0D6D6A3E 7A6A5AA8 E40ECF0B 9309FF9D 0A00AE27 7D079EB1 F00F9344 8708A3D2 1E01F268 6906C2FE F762575D 806567CB 196C3671 6E6B06E7 FED41B76 89D32BE0 10DA7A5A 67DD4ACC F9B9DF6F 8EBEEFF9 17B7BE43 60B08ED5 D6D6A3E8 A1D1937E 38D8C2C4 4FDFF252 D1BB67F1 A6BC5767 3FB506DD 48B2364B D80D2BDA AF0A1B4C 36034AF6 41047A60 DF60EFC3 A867DF55 316E8EEF 4669BE79 CB61B38C BC66831A 256FD2A0 5268E236 CC0C7795 BB0B4703 220216B9 5505262F C5BA3BBE B2BD0B28 2BB45A92 5CB36A04 C2D7FFA7 B5D0CF31 2CD99E8B 5BDEAE1D 9B64C2B0 EC63F226 756AA39C 026D930A 9C0906A9 EB0E363F 72076785 05005713 95BF4A82 E2B87A14 7BB12BAE 0CB61B38 92D28E9B E5D5BE0D 7CDCEFB7 0BDBDF21 86D3D2D4 F1D4E242 68DDB3F8 1FDA836E 81BE16CD F6B9265B 6FB077E1 18B74777 88085AE6 FF0F6A70 66063BCA 11010B5C 8F659EFF F862AE69 616BFFD3 166CCF45 A00AE278 D70DD2EE 4E048354 3903B3C2 A7672661 D06016F7 4969474D 3E6E77DB AED16A4A D9D65ADC 40DF0B66 37D83BF0 A9BCAE53 DEBB9EC5 47B2CF7F 30B5FFE9 BDBDF21C CABAC28A 53B39330 24B4A3A6 BAD03605 CDD70693 54DE5729 23D967BF B3667A2E C4614AB8 5D681B02 2A6F2B94 B40BBE37 C30C8EA1 5A05DF1B 2D02EF8D".substr(9 * n, 8);
                    }

                    return -1 ^ e;
                  },
                  utf8_encode: function utf8_encode(t) {
                    var e,
                        n,
                        r,
                        o = t + "",
                        i = "";
                    e = n = 0, r = o.length;

                    for (var s = 0; s < r; s++) {
                      var a = o.charCodeAt(s),
                          u = null;
                      a < 128 ? n++ : u = a > 127 && a < 2048 ? String.fromCharCode(a >> 6 | 192) + String.fromCharCode(63 & a | 128) : String.fromCharCode(a >> 12 | 224) + String.fromCharCode(a >> 6 & 63 | 128) + String.fromCharCode(63 & a | 128), null !== u && (n > e && (i += o.substring(e, n)), i += u, e = n = s + 1);
                    }

                    return n > e && (i += o.substring(e, o.length)), i;
                  },
                  utf8_decode: function utf8_decode(t) {
                    var e = [],
                        n = 0,
                        r = 0,
                        o = 0,
                        i = 0,
                        s = 0;

                    for (t += ""; n < t.length;) {
                      (o = t.charCodeAt(n)) < 128 ? (e[r++] = String.fromCharCode(o), n++) : o > 191 && o < 224 ? (i = t.charCodeAt(n + 1), e[r++] = String.fromCharCode((31 & o) << 6 | 63 & i), n += 2) : (i = t.charCodeAt(n + 1), s = t.charCodeAt(n + 2), e[r++] = String.fromCharCode((15 & o) << 12 | (63 & i) << 6 | 63 & s), n += 3);
                    }

                    return e.join("");
                  },
                  trim: function trim(t, e) {
                    var n,
                        r = 0,
                        o = 0;

                    for (t += "", n = e ? (e += "").replace(/([\[\]\(\)\.\?\/\*\{\}\+\$\^\:])/g, "$1") : " \n\r\t\f\x0B\xA0\u2000\u2001\u2002\u2003\u2004\u2005\u2006\u2007\u2008\u2009\u200A\u200B\u2028\u2029\u3000", r = t.length, o = 0; o < r; o++) {
                      if (-1 === n.indexOf(t.charAt(o))) {
                        t = t.substring(o);
                        break;
                      }
                    }

                    for (o = (r = t.length) - 1; o >= 0; o--) {
                      if (-1 === n.indexOf(t.charAt(o))) {
                        t = t.substring(0, o + 1);
                        break;
                      }
                    }

                    return -1 === n.indexOf(t.charAt(0)) ? t : "";
                  },
                  rand: function rand(t, e) {
                    var n = arguments.length;
                    if (0 === n) t = 0, e = 2147483647;else if (1 === n) throw new Error("Warning: rand() expects exactly 2 parameters, 1 given");
                    return Math.floor(Math.random() * (e - t + 1)) + t;
                  },
                  base64_encode: function base64_encode(t) {
                    var e,
                        n,
                        r,
                        o,
                        i,
                        s = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
                        a = 0,
                        u = 0,
                        c = "",
                        l = [];
                    if (!t) return t;
                    t = this.utf8_encode(t + "");

                    do {
                      e = (i = t.charCodeAt(a++) << 16 | t.charCodeAt(a++) << 8 | t.charCodeAt(a++)) >> 18 & 63, n = i >> 12 & 63, r = i >> 6 & 63, o = 63 & i, l[u++] = s.charAt(e) + s.charAt(n) + s.charAt(r) + s.charAt(o);
                    } while (a < t.length);

                    switch (c = l.join(""), t.length % 3) {
                      case 1:
                        c = c.slice(0, -2) + "==";
                        break;

                      case 2:
                        c = c.slice(0, -1) + "=";
                    }

                    return c;
                  },
                  base64_decode: function base64_decode(t) {
                    var e,
                        n,
                        r,
                        o,
                        i,
                        s,
                        a,
                        u = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=",
                        c = 0,
                        l = 0,
                        h = [];
                    if (!t) return t;
                    t += "";

                    do {
                      e = (s = u.indexOf(t.charAt(c++)) << 18 | u.indexOf(t.charAt(c++)) << 12 | (o = u.indexOf(t.charAt(c++))) << 6 | (i = u.indexOf(t.charAt(c++)))) >> 16 & 255, n = s >> 8 & 255, r = 255 & s, h[l++] = 64 == o ? String.fromCharCode(e) : 64 == i ? String.fromCharCode(e, n) : String.fromCharCode(e, n, r);
                    } while (c < t.length);

                    return a = h.join(""), this.utf8_decode(a);
                  },
                  sprintf: function sprintf() {
                    var t = /%%|%(\d+\$)?([-+\'#0 ]*)(\*\d+\$|\*|\d+)?(\.(\*\d+\$|\*|\d+))?([scboxXuidfegEG])/g,
                        e = arguments,
                        n = 0,
                        r = e[n++],
                        o = function o(t, e, n, r) {
                      n || (n = " ");
                      var o = t.length >= e ? "" : Array(1 + e - t.length >>> 0).join(n);
                      return r ? t + o : o + t;
                    },
                        i = function i(t, e, n, r, _i5, s) {
                      var a = r - t.length;
                      return a > 0 && (t = n || !_i5 ? o(t, r, s, n) : t.slice(0, e.length) + o("", a, "0", !0) + t.slice(e.length)), t;
                    },
                        s = function s(t, e, n, r, _s5, a, u) {
                      var c = t >>> 0;
                      return t = (n = n && c && {
                        2: "0b",
                        8: "0",
                        16: "0x"
                      }[e] || "") + o(c.toString(e), a || 0, "0", !1), i(t, n, r, _s5, u);
                    },
                        a = function a(t, e, n, r, o, s) {
                      return null != r && (t = t.slice(0, r)), i(t, "", e, n, o, s);
                    },
                        u = function u(t, r, _u5, c, l, h, g) {
                      var f, d, p, m, C;
                      if ("%%" == t) return "%";

                      for (var A = !1, y = "", D = !1, v = !1, S = " ", b = _u5.length, E = 0; _u5 && E < b; E++) {
                        switch (_u5.charAt(E)) {
                          case " ":
                            y = " ";
                            break;

                          case "+":
                            y = "+";
                            break;

                          case "-":
                            A = !0;
                            break;

                          case "'":
                            S = _u5.charAt(E + 1);
                            break;

                          case "0":
                            D = !0;
                            break;

                          case "#":
                            v = !0;
                        }
                      }

                      if ((c = c ? "*" == c ? +e[n++] : "*" == c.charAt(0) ? +e[c.slice(1, -1)] : +c : 0) < 0 && (c = -c, A = !0), !isFinite(c)) throw new Error("sprintf: (minimum-)width must be finite");

                      switch (h = h ? "*" == h ? +e[n++] : "*" == h.charAt(0) ? +e[h.slice(1, -1)] : +h : "fFeE".indexOf(g) > -1 ? 6 : "d" == g ? 0 : void 0, C = r ? e[r.slice(0, -1)] : e[n++], g) {
                        case "s":
                          return a(String(C), A, c, h, D, S);

                        case "c":
                          return a(String.fromCharCode(+C), A, c, h, D);

                        case "b":
                          return s(C, 2, v, A, c, h, D);

                        case "o":
                          return s(C, 8, v, A, c, h, D);

                        case "x":
                          return s(C, 16, v, A, c, h, D);

                        case "X":
                          return s(C, 16, v, A, c, h, D).toUpperCase();

                        case "u":
                          return s(C, 10, v, A, c, h, D);

                        case "i":
                        case "d":
                          return C = (d = (f = parseInt(+C, 10)) < 0 ? "-" : y) + o(String(Math.abs(f)), h, "0", !1), i(C, d, A, c, D);

                        case "e":
                        case "E":
                        case "f":
                        case "F":
                        case "g":
                        case "G":
                          return d = (f = +C) < 0 ? "-" : y, p = ["toExponential", "toFixed", "toPrecision"]["efg".indexOf(g.toLowerCase())], m = ["toString", "toUpperCase"]["eEfFgG".indexOf(g) % 2], C = d + Math.abs(f)[p](h), i(C, d, A, c, D)[m]();

                        default:
                          return t;
                      }
                    };

                    return r.replace(t, u);
                  },
                  clone: function clone(t) {
                    var e = t instanceof Array ? [] : {};

                    for (var r in t) {
                      t[r] && "object" == n(t[r]) ? e[r] = o.util.clone(t[r]) : e[r] = t[r];
                    }

                    return e;
                  },
                  strtolower: function strtolower(t) {
                    return (t + "").toLowerCase();
                  },
                  in_array: function in_array(t, e, n) {
                    var r = "";

                    if (n) {
                      for (r in e) {
                        if (e[r] === t) return !0;
                      }
                    } else for (r in e) {
                      if (e[r] == t) return !0;
                    }

                    return !1;
                  },
                  dechex: function dechex(t) {
                    return t < 0 && (t = 4294967295 + t + 1), parseInt(t, 10).toString(16);
                  },
                  explode: function explode(t, e, r) {
                    var o = {
                      0: ""
                    };
                    if (arguments.length < 2 || void 0 === arguments[0] || void 0 === arguments[1]) return null;
                    if ("" === t || !1 === t || null === t) return !1;
                    if ("function" == typeof t || "object" == n(t) || "function" == typeof e || "object" == n(e)) return o;

                    if (!0 === t && (t = "1"), r) {
                      var i = e.toString().split(t.toString()),
                          s = i.splice(0, r - 1),
                          a = i.join(t.toString());
                      return s.push(a), s;
                    }

                    return e.toString().split(t.toString());
                  },
                  isIE: function isIE() {
                    if (/MSIE (\d+\.\d+);/.test(navigator.userAgent)) return !0;
                  },
                  getInternetExplorerVersion: function getInternetExplorerVersion() {
                    var t = -1;

                    if ("Microsoft Internet Explorer" == navigator.appName) {
                      var e = navigator.userAgent;
                      null != new RegExp("MSIE ([0-9]{1,}[.0-9]{0,})").exec(e) && (t = parseFloat(RegExp.$1));
                    }

                    return t;
                  },
                  isBrowserTrackable: function isBrowserTrackable() {
                    for (var t = ["doNotTrack", "msDoNotTrack"], e = 0, n = t.length; e < n; e++) {
                      if (navigator[t[e]] && "1" == navigator[t[e]]) return !1;
                    }

                    return !0;
                  }
                };
              }, function (t, e, n) {
                n(0), n(2), t.exports = n(11);
              }, function (t, e) {
                OWA.event = function () {
                  this.properties = {}, this.id = "", this.siteId = "", this.set("timestamp", OWA.util.getCurrentUnixTimestamp());
                }, OWA.event.prototype = {
                  get: function get(t) {
                    if (this.properties.hasOwnProperty(t)) return this.properties[t];
                  },
                  set: function set(t, e) {
                    this.properties[t] = e;
                  },
                  setEventType: function setEventType(t) {
                    this.set("event_type", t);
                  },
                  getProperties: function getProperties() {
                    return this.properties;
                  },
                  merge: function merge(t) {
                    for (param in t) {
                      t.hasOwnProperty(param) && this.set(param, t[param]);
                    }
                  },
                  isSet: function isSet(t) {
                    if (this.properties.hasOwnProperty(t)) return !0;
                  }
                }, OWA.commandQueue = function () {
                  OWA.debug("Command Queue object created");
                }, OWA.commandQueue.prototype = {
                  push: function push(t, e) {
                    var n = Array.prototype.slice.call(t, 1),
                        r = "",
                        o = "";

                    if (OWA.util.strpos(t[0], ".")) {
                      var i = t[0].split(".");
                      r = i[0], o = i[1];
                    } else r = "OWATracker", o = t[0];

                    OWA.debug("cmd queue object name %s", r), OWA.debug("cmd queue object method name %s", o), "pause-owa" === o && this.pause(), this.is_paused || (void 0 === window[r] && (OWA.debug("making global object named: %s", r), window[r] = new OWA.tracker({
                      globalObjectName: r
                    })), window[r][o].apply(window[r], n)), "unpause-owa" === o && this.unpause(), e && "function" == typeof e && e();
                  },
                  loadCmds: function loadCmds(t) {
                    this.asyncCmds = t;
                  },
                  process: function process() {
                    var t = this;
                    this.push(this.asyncCmds.shift(), function () {
                      t.asyncCmds.length > 0 && t.process();
                    });
                  },
                  pause: function pause() {
                    this.is_paused = !0, OWA.debug("Pausing Command Queue");
                  },
                  unpause: function unpause() {
                    this.is_paused = !1, OWA.debug("Un-pausing Command Queue");
                  }
                }, OWA.tracker = function (t) {
                  this.startTime = this.getTimestamp(), OWA.registerStateStore("v", 364, "", "assoc"), OWA.registerStateStore("s", 364, "", "assoc"), OWA.registerStateStore("c", 60, "", "json"), OWA.registerStateStore("b", "", "", "json"), this.options = OWA.applyFilters("tracker.default_options", {
                    logClicks: !0,
                    logPage: !0,
                    logMovement: !1,
                    encodeProperties: !1,
                    movementInterval: 100,
                    logDomStreamPercentage: 100,
                    domstreamLoggingInterval: 3e3,
                    domstreamEventThreshold: 10,
                    maxPriorCampaigns: 5,
                    campaignAttributionWindow: 60,
                    trafficAttributionMode: "direct",
                    sessionLength: 1800,
                    thirdParty: !1,
                    cookie_domain: !1,
                    campaignKeys: [{
                      "public": "owa_medium",
                      "private": "md",
                      full: "medium"
                    }, {
                      "public": "owa_campaign",
                      "private": "cn",
                      full: "campaign"
                    }, {
                      "public": "owa_source",
                      "private": "sr",
                      full: "source"
                    }, {
                      "public": "owa_search_terms",
                      "private": "tr",
                      full: "search_terms"
                    }, {
                      "public": "owa_ad",
                      "private": "ad",
                      full: "ad"
                    }, {
                      "public": "owa_ad_type",
                      "private": "at",
                      full: "ad_type"
                    }],
                    logger_endpoint: "",
                    api_endpoint: "",
                    maxCustomVars: 5,
                    getRequestCharacterLimit: 2e3
                  });
                  var e = window.owa_baseUrl || OWA.config.baseUrl;
                  if (e ? this.setEndpoint(e) : OWA.debug("no global endpoint url found."), this.endpoint = OWA.config.baseUrl, this.active = !0, t) for (var n in t) {
                    this.options[n] = t[n];
                  }
                  this.ecommerce_transaction = "", this.isClickTrackingEnabled = !1, this.domstream_guid = "", this.checkForOverlaySession(), OWA.doAction("tracker.init");
                }, OWA.tracker.prototype = {
                  id: "",
                  siteId: "",
                  init: 0,
                  stateInit: !1,
                  globalEventProperties: {},
                  sharableStateStores: ["v", "s", "c", "b"],
                  startTime: null,
                  endTime: null,
                  campaignState: [],
                  isNewCampaign: !1,
                  isNewSessionFlag: !1,
                  isTrafficAttributed: !1,
                  linkedStateSet: !1,
                  hashCookiesToDomain: !0,
                  organicSearchEngines: [{
                    d: "google",
                    q: "q"
                  }, {
                    d: "yahoo",
                    q: "p"
                  }, {
                    d: "yahoo",
                    q: "q"
                  }, {
                    d: "msn",
                    q: "q"
                  }, {
                    d: "bing",
                    q: "q"
                  }, {
                    d: "images.google",
                    q: "q"
                  }, {
                    d: "images.search.yahoo.com",
                    q: "p"
                  }, {
                    d: "aol",
                    q: "query"
                  }, {
                    d: "aol",
                    q: "encquery"
                  }, {
                    d: "aol",
                    q: "q"
                  }, {
                    d: "lycos",
                    q: "query"
                  }, {
                    d: "ask",
                    q: "q"
                  }, {
                    d: "altavista",
                    q: "q"
                  }, {
                    d: "netscape",
                    q: "query"
                  }, {
                    d: "cnn",
                    q: "query"
                  }, {
                    d: "about",
                    q: "terms"
                  }, {
                    d: "mamma",
                    q: "q"
                  }, {
                    d: "daum",
                    q: "q"
                  }, {
                    d: "eniro",
                    q: "search_word"
                  }, {
                    d: "naver",
                    q: "query"
                  }, {
                    d: "pchome",
                    q: "q"
                  }, {
                    d: "alltheweb",
                    q: "q"
                  }, {
                    d: "voila",
                    q: "rdata"
                  }, {
                    d: "virgilio",
                    q: "qs"
                  }, {
                    d: "live",
                    q: "q"
                  }, {
                    d: "baidu",
                    q: "wd"
                  }, {
                    d: "alice",
                    q: "qs"
                  }, {
                    d: "yandex",
                    q: "text"
                  }, {
                    d: "najdi",
                    q: "q"
                  }, {
                    d: "mama",
                    q: "query"
                  }, {
                    d: "seznam",
                    q: "q"
                  }, {
                    d: "search",
                    q: "q"
                  }, {
                    d: "wp",
                    q: "szukaj"
                  }, {
                    d: "onet",
                    q: "qt"
                  }, {
                    d: "szukacz",
                    q: "q"
                  }, {
                    d: "yam",
                    q: "k"
                  }, {
                    d: "kvasir",
                    q: "q"
                  }, {
                    d: "sesam",
                    q: "q"
                  }, {
                    d: "ozu",
                    q: "q"
                  }, {
                    d: "terra",
                    q: "query"
                  }, {
                    d: "mynet",
                    q: "q"
                  }, {
                    d: "ekolay",
                    q: "q"
                  }, {
                    d: "rambler",
                    q: "query"
                  }, {
                    d: "rambler",
                    q: "words"
                  }, {
                    d: "duckduckgo",
                    q: "q"
                  }],
                  urlParams: {},
                  streamBindings: ["bindMovementEvents", "bindScrollEvents", "bindKeypressEvents", "bindClickEvents"],
                  click: "",
                  domstream: "",
                  movement: "",
                  keystroke: "",
                  hover: "",
                  last_event: "",
                  last_movement: "",
                  event_queue: [],
                  player: "",
                  overlay: "",
                  setDebug: function setDebug(t) {
                    OWA.setSetting("debug", t);
                  },
                  checkForLinkedState: function checkForLinkedState() {
                    if (1 != this.linkedStateSet) {
                      var t = this.getUrlParam(OWA.getSetting("ns") + "state");

                      if (t || (t = this.getAnchorParam(OWA.getSetting("ns") + "state")), t) {
                        OWA.debug("Shared OWA state detected..."), t = OWA.util.base64_decode(OWA.util.urldecode(t)), OWA.debug("linked state: %s", t);
                        var e = t.split(".");
                        if (OWA.debug("linked state: %s", JSON.stringify(e)), e) for (var n = 0; e.length > n; n++) {
                          var r = e[n].split("=");
                          OWA.debug("pair: %s", r);
                          var o = OWA.util.urldecode(r[1]);
                          OWA.debug("pair: %s", o), decodedvalue = OWA.util.decodeCookieValue(o);
                          var i = OWA.util.getCookieValueFormat(o);
                          decodedvalue.cdh = OWA.util.getCookieDomainHash(this.getCookieDomain()), OWA.replaceState(r[0], decodedvalue, !0, i);
                        }
                      }

                      this.linkedStateSet = !0;
                    }
                  },
                  shareStateByLink: function shareStateByLink(t) {
                    if (OWA.debug("href of link: " + t), t) {
                      var e = this.createSharedStateValue();
                      this.getUrlAnchorValue() || (OWA.debug("shared state: %s", e), document.location.href = t + "#" + OWA.getSetting("ns") + "state." + e);
                    }
                  },
                  createSharedStateValue: function createSharedStateValue() {
                    for (var t = "", e = 0; this.sharableStateStores.length > e; e++) {
                      var n = OWA.getState(this.sharableStateStores[e]);
                      (n = OWA.util.encodeJsonForCookie(n, OWA.getStateStoreFormat(this.sharableStateStores[e]))) && (t += OWA.util.sprintf("%s=%s", this.sharableStateStores[e], OWA.util.urlEncode(n)), this.sharableStateStores.length != e + 1 && (t += "."));
                    }

                    if (t) return OWA.debug("linked state to send: %s", t), t = OWA.util.base64_encode(t), OWA.util.urlEncode(t);
                  },
                  shareStateByPost: function shareStateByPost(t) {
                    var e = this.createSharedStateValue();
                    t.action += "#" + OWA.getSetting("ns") + "state." + e, t.submit();
                  },
                  getCookieDomain: function getCookieDomain() {
                    return this.getOption("cookie_domain") || OWA.getSetting("cookie_domain") || document.domain;
                  },
                  setCookieDomain: function setCookieDomain(t) {
                    var e = !1;
                    t || (t = document.domain, e = !0), "." === t.substr(0, 1) && (t = t.substr(1)), "www." === t.substr(0, 4) && e && (t = t.substr(4)), document.domain, t = "." + t, this.setOption("cookie_domain", t), this.setOption("cookie_domain_set", !0), OWA.setSetting("cookie_domain", t), OWA.debug("Cookie domain is: %s", t);
                  },
                  getCookieDomainHash: function getCookieDomainHash(t) {
                    return OWA.util.crc32(t);
                  },
                  setCookieDomainHashing: function setCookieDomainHashing(t) {
                    this.hashCookiesToDomain = t, OWA.setSetting("hashCookiesToDomain", t);
                  },
                  checkForOverlaySession: function checkForOverlaySession() {
                    var t = this.getAnchorParam(OWA.getSetting("ns") + "overlay");
                    t && (t = OWA.util.base64_decode(OWA.util.urldecode(t)), t = OWA.util.urldecode(t), OWA.debug("overlay anchor value: " + t), OWA.util.setCookie(OWA.getSetting("ns") + "overlay", t, "", "/", document.domain), this.pause(), OWA.startOverlaySession(OWA.util.decodeCookieValue(t)));
                  },
                  getUrlAnchorValue: function getUrlAnchorValue() {
                    var t = self.document.location.hash.substring(1);
                    return OWA.debug("anchor value: " + t), t;
                  },
                  getAnchorParam: function getAnchorParam(t) {
                    var e = this.getUrlAnchorValue();

                    if (e) {
                      OWA.debug("anchor is: %s", e);
                      var n = e.split(",");

                      if (OWA.debug("anchor pairs: %s", JSON.stringify(n)), n.length > 0) {
                        for (var r = {}, o = 0; n.length > o; o++) {
                          var i = n[o].split(".");
                          OWA.debug("anchor pieces: %s", JSON.stringify(i)), r[i[0]] = i[1];
                        }

                        if (OWA.debug("anchor values: %s", JSON.stringify(r)), r.hasOwnProperty(t)) return r[t];
                      }
                    }
                  },
                  getUrlParam: function getUrlParam(t) {
                    return this.urlParams = this.urlParams || OWA.util.parseUrlParams(), !!this.urlParams.hasOwnProperty(t) && this.urlParams[t];
                  },
                  dynamicFunc: function dynamicFunc(t) {
                    var e = Array.prototype.slice.call(t, 1);
                    this[t[0]].apply(this, e);
                  },
                  setPageTitle: function setPageTitle(t) {
                    this.setGlobalEventProperty("page_title", OWA.util.trim(t));
                  },
                  setPageType: function setPageType(t) {
                    this.setGlobalEventProperty("page_type", OWA.util.trim(t));
                  },
                  setUserName: function setUserName(t) {
                    this.setGlobalEventProperty("user_name", OWA.util.trim(t));
                  },
                  setSiteId: function setSiteId(t) {
                    this.siteId = t;
                  },
                  getSiteId: function getSiteId() {
                    return this.siteId;
                  },
                  setEndpoint: function setEndpoint(t) {
                    t = "https:" == document.location.protocol ? window.owa_baseSecUrl || t.replace(/http:/, "https:") : t, this.setOption("baseUrl", t), OWA.config.baseUrl = t;
                  },
                  setLoggerEndpoint: function setLoggerEndpoint(t) {
                    this.setOption("logger_endpoint", this.forceUrlProtocol(t));
                  },
                  getLoggerEndpoint: function getLoggerEndpoint() {
                    return (this.getOption("logger_endpoint") || this.getEndpoint() || OWA.getSetting("baseUrl")) + "log.php";
                  },
                  setApiEndpoint: function setApiEndpoint(t) {
                    this.setOption("api_endpoint", this.forceUrlProtocol(t)), OWA.setApiEndpoint(t);
                  },
                  getApiEndpoint: function getApiEndpoint() {
                    return this.getOption("api_endpoint") || this.getEndpoint() + "api.php";
                  },
                  forceUrlProtocol: function forceUrlProtocol(t) {
                    return "https:" == document.location.protocol ? t.replace(/http:/, "https:") : t;
                  },
                  getEndpoint: function getEndpoint() {
                    return this.getOption("baseUrl");
                  },
                  getCurrentUrl: function getCurrentUrl() {
                    return document.URL;
                  },
                  bindClickEvents: function bindClickEvents() {
                    if (!this.isClickTrackingEnabled) {
                      var t = this;
                      window.addEventListener ? window.addEventListener("click", function (e) {
                        t.clickEventHandler(e);
                      }, !1) : window.attachEvent && document.attachEvent("onclick", function (e) {
                        t.clickEventHandler(e);
                      }), this.isClickTrackingEnabled = !0;
                    }
                  },
                  setDomstreamSampleRate: function setDomstreamSampleRate(t) {
                    this.setOption("logDomStreamPercentage", t);
                  },
                  startDomstreamTimer: function startDomstreamTimer() {
                    var t = this.getOption("domstreamLoggingInterval"),
                        e = this;
                    setInterval(function () {
                      e.logDomStream();
                    }, t);
                  },
                  log: function log() {
                    var t = new OWA.event();
                    return t.setEventType("base.page_request"), this.logEvent(t);
                  },
                  isObjectType: function isObjectType(t, e) {
                    return !!(t && e && e.prototype && t.constructor == e.prototype.constructor);
                  },
                  logEvent: function logEvent(t, e, n) {
                    if (this.active) {
                      t = OWA.applyFilters("tracker.log_event_properties", t);

                      var r = this._assembleRequestUrl(t),
                          o = this.getOption("getRequestCharacterLimit");

                      if (r.length > o) {
                        var i = this.prepareRequestData(t);
                        this.cdPost(i);
                      } else {
                        OWA.debug("url : %s", r);
                        var s = new Image(1, 1);
                        s.onLoad = function () {}, s.src = r, OWA.debug("Inserted web bug for %s", t.event_type);
                      }

                      n && "function" == typeof n && n();
                    }
                  },
                  _assembleRequestUrl: function _assembleRequestUrl(t) {
                    var e = this.prepareRequestDataForGet(t),
                        n = this.getLoggerEndpoint();
                    return -1 === n.indexOf("?") ? n += "?" : n += "&", n + e;
                  },
                  prepareRequestData: function prepareRequestData(t) {
                    var e = {};

                    for (var n in t) {
                      if (t.hasOwnProperty(n)) if (OWA.util.is_array(t[n])) for (var r = t[n].length, o = 0; o < r; o++) {
                        if (OWA.util.is_object(t[n][o])) for (var i in t[n][o]) {
                          e[OWA.util.sprintf(OWA.getSetting("ns") + "%s[%s][%s]", n, o, i)] = OWA.util.urlEncode(t[n][o][i]);
                        } else e[OWA.util.sprintf(OWA.getSetting("ns") + "%s[%s]", n, o)] = OWA.util.urlEncode(t[n][o]);
                      } else e[OWA.util.sprintf(OWA.getSetting("ns") + "%s", n)] = OWA.util.urlEncode(t[n]);
                    }

                    return e;
                  },
                  prepareRequestDataForGet: function prepareRequestDataForGet(t) {
                    t = this.prepareRequestData(t);
                    var e = "";

                    for (var n in t) {
                      t.hasOwnProperty(n) && (e += OWA.util.sprintf("%s=%s&", n, t[n]));
                    }

                    return e;
                  },
                  cdPost: function cdPost(t) {
                    var e = "owa-tracker-post-container",
                        n = (this.getLoggerEndpoint(), document.getElementById(e));

                    if (!n) {
                      var r = document.createElement("div");
                      r.setAttribute("id", e), r.setAttribute("height", "0px"), r.setAttribute("width", "0px"), r.setAttribute("style", "border: none; overflow-x: hidden; overflow-y: hidden; display: none;"), document.body.appendChild(r), n = document.getElementById(e);
                    }

                    this.generateHiddenIframe(n, t);
                  },
                  generateHiddenIframe: function generateHiddenIframe(t, e) {
                    var n = "owa-tracker-post-iframe";
                    if (OWA.util.isIE() && OWA.util.getInternetExplorerVersion() < 9) var r = document.createElement('<iframe name="' + n + '" scr="about:blank" width="1" height="1"></iframe>');else (r = document.createElement("iframe")).setAttribute("name", n), r.setAttribute("src", "about:blank"), r.setAttribute("width", 1), r.setAttribute("height", 1);
                    r.setAttribute("class", n), r.setAttribute("style", "border: none; overflow: hidden; "), r.setAttribute("scrolling", "no");
                    var o = this;
                    null == t && (t = document.body), t.appendChild(r);
                    var i = setInterval(function () {
                      o.getIframeDocument(r) && (clearInterval(i), o.postFromIframe(r, e));
                    }, 1),
                        s = setInterval(function () {
                      t.removeChild(r), clearInterval(s);
                    }, 1e3);
                  },
                  postFromIframe: function postFromIframe(t, e) {
                    var n = this.getLoggerEndpoint(),
                        r = this.getIframeDocument(t),
                        o = "post_form" + Math.random();
                    if (OWA.util.isIE() && OWA.util.getInternetExplorerVersion() < 9) var i = r.createElement('<form name="' + o + '"></form>');else (i = r.createElement("form")).setAttribute("name", o);

                    for (var s in i.setAttribute("id", o), i.setAttribute("action", n), i.setAttribute("method", "POST"), e) {
                      if (e.hasOwnProperty(s)) {
                        if (OWA.util.isIE() && OWA.util.getInternetExplorerVersion() < 9) var a = r.createElement("<input type='hidden' name='" + s + "' />");else (a = document.createElement("input")).setAttribute("name", s), a.setAttribute("type", "hidden");
                        a.setAttribute("value", e[s]), i.appendChild(a);
                      }
                    }

                    r.body.appendChild(i), r.forms[o].submit(), r.body.removeChild(i);
                  },
                  createPostForm: function createPostForm() {
                    var t = this.getLoggerEndpoint(),
                        e = "post_form" + Math.random();
                    if (OWA.util.isIE() && OWA.util.getInternetExplorerVersion() < 9) var n = doc.createElement('<form name="' + e + '"></form>');else (n = doc.createElement("form")).setAttribute("name", e);
                    return n.setAttribute("id", e), n.setAttribute("action", t), n.setAttribute("method", "POST"), n;
                  },
                  getIframeDocument: function getIframeDocument(t) {
                    var e = null;
                    return t.contentDocument ? e = t.contentDocument : t.contentWindow && t.contentWindow.document ? e = t.contentWindow.document : t.document && (e = t.document), null == e && OWA.debug("Document not found, append the parent element to the DOM before creating the IFrame"), e.open(), e.close(), e;
                  },
                  getViewportDimensions: function getViewportDimensions() {
                    var t = new Object();
                    return t.width = window.innerWidth ? window.innerWidth : document.body.offsetWidth, t.height = window.innerHeight ? window.innerHeight : document.body.offsetHeight, t;
                  },
                  findPosX: function findPosX(t) {
                    var e = 0;
                    if (t.offsetParent) for (; t.offsetParent;) {
                      e += t.offsetLeft, t = t.offsetParent;
                    } else t.x && (e += t.x);
                    return e;
                  },
                  findPosY: function findPosY(t) {
                    var e = 0;
                    if (t.offsetParent) for (; t.offsetParent;) {
                      e += t.offsetTop, t = t.offsetParent;
                    } else t.y && (e += t.y);
                    return e;
                  },
                  _getTarget: function _getTarget(t) {
                    var e = t.target || t.srcElement;
                    return void 0 === e || null == e ? null : (3 == e.nodeType && (e = target.parentNode), e);
                  },
                  getCoords: function getCoords(t) {
                    var e = new Object();
                    return "number" == typeof t.pageX ? (e.x = t.pageX + "", e.y = t.pageY + "") : (e.x = t.clientX + "", e.y = t.clientY + ""), e;
                  },
                  getDomElementProperties: function getDomElementProperties(t) {
                    var e = new Object();
                    return e.dom_element_tag = t.tagName, "A" == t.tagName ? (null != t.textContent ? e.dom_element_text = t.textContent : e.dom_element_text = t.innerText, e.target_url = t.href) : "INPUT" == t.tagName ? e.dom_element_text = t.value : "IMG" == t.tagName ? (e.target_url = t.parentNode.href, e.dom_element_text = t.alt) : (t.textContent, e.html_element_text = ""), e;
                  },
                  clickEventHandler: function clickEventHandler(t) {
                    t = t || window.event;
                    var e = new OWA.event();
                    e.setEventType("dom.click");

                    var n = this._getTarget(t),
                        r = "(not set)";

                    n.hasAttribute("name") && null != n.name && n.name.length > 0 && (r = n.name), e.set("dom_element_name", r);
                    var o = "(not set)";
                    n.hasAttribute("value") && n.value.length > 0 && (o = n.value), e.set("dom_element_value", o);
                    var i = "(not set)";
                    n.id && n.id.length > 0 && (i = n.id), e.set("dom_element_id", i);
                    var s = "(not set)";
                    n.className && n.className.length > 0 && (s = n.className), e.set("dom_element_class", s), e.set("dom_element_tag", OWA.util.strtolower(n.tagName)), e.set("page_url", window.location.href);
                    var a = this.getViewportDimensions();
                    e.set("page_width", a.width), e.set("page_height", a.height);
                    var u = this.getDomElementProperties(n);
                    e.merge(this.filterDomProperties(u)), e.set("dom_element_x", this.findPosX(n) + ""), e.set("dom_element_y", this.findPosY(n) + "");
                    var c = this.getCoords(t);
                    e.set("click_x", c.x), e.set("click_y", c.y), this.getOption("trackDomStream") && this.addToEventQueue(e);
                    var l = OWA.util.clone(e);
                    this.getOption("logClicksAsTheyHappen") && this.trackEvent(l), this.click = l;
                  },
                  filterDomProperties: function filterDomProperties(t) {
                    return t;
                  },
                  callMethod: function callMethod(t, e) {
                    return this[t](e);
                  },
                  addDomStreamEventBinding: function addDomStreamEventBinding(t) {
                    this.streamBindings.push(t);
                  },
                  bindMovementEvents: function bindMovementEvents() {
                    var t = this;

                    document.onmousemove = function (e) {
                      t.movementEventHandler(e);
                    };
                  },
                  movementEventHandler: function movementEventHandler(t) {
                    t = t || window.event;
                    var e = this.getTime();

                    if (e > this.last_movement + this.getOption("movementInterval")) {
                      this.movement = new OWA.event(), this.movement.setEventType("dom.movement");
                      var n = this.getCoords(t);
                      this.movement.set("cursor_x", n.x), this.movement.set("cursor_y", n.y), this.addToEventQueue(this.movement), this.last_movement = e;
                    }
                  },
                  bindScrollEvents: function bindScrollEvents() {
                    var t = this;

                    window.onscroll = function (e) {
                      t.scrollEventHandler(e);
                    };
                  },
                  scrollEventHandler: function scrollEventHandler(t) {
                    t = t || window.event;
                    var e = this.getTimestamp(),
                        n = new OWA.event();
                    n.setEventType("dom.scroll");
                    var r = this.getScrollingPosition();
                    n.set("x", r.x), n.set("y", r.y), this.addToEventQueue(n), this.last_scroll = e;
                  },
                  getScrollingPosition: function getScrollingPosition() {
                    var t = [0, 0];
                    return void 0 !== window.pageYOffset ? t = {
                      x: window.pageXOffset,
                      y: window.pageYOffset
                    } : void 0 !== document.documentElement.scrollTop && document.documentElement.scrollTop > 0 ? t = {
                      x: document.documentElement.scrollLeft,
                      y: document.documentElement.scrollTop
                    } : void 0 !== document.body.scrollTop && (t = {
                      x: document.body.scrollLeft,
                      y: document.body.scrollTop
                    }), t;
                  },
                  bindHoverEvents: function bindHoverEvents() {},
                  bindFocusEvents: function bindFocusEvents() {},
                  bindKeypressEvents: function bindKeypressEvents() {
                    var t = this;

                    document.onkeypress = function (e) {
                      t.keypressEventHandler(e);
                    };
                  },
                  keypressEventHandler: function keypressEventHandler(t) {
                    t = t || window.event;

                    var e = this._getTarget(t);

                    if ("INPUT" !== e.tagName || "password" !== e.type) {
                      var n = t.keyCode ? t.keyCode : t.charCode,
                          r = String.fromCharCode(n),
                          o = new OWA.event();
                      o.setEventType("dom.keypress"), o.set("key_value", r), o.set("key_code", n), o.set("dom_element_name", e.name), o.set("dom_element_value", e.value), o.set("dom_element_id", e.id), o.set("dom_element_tag", e.tagName), this.addToEventQueue(o);
                    }
                  },
                  getTimestamp: function getTimestamp() {
                    return OWA.util.getCurrentUnixTimestamp();
                  },
                  getTime: function getTime() {
                    return Math.round(new Date().getTime());
                  },
                  getElapsedTime: function getElapsedTime() {
                    return this.getTimestamp() - this.startTime;
                  },
                  getOption: function getOption(t) {
                    if (this.options.hasOwnProperty(t)) return this.options[t];
                  },
                  setOption: function setOption(t, e) {
                    this.options[t] = e;
                  },
                  setLastEvent: function setLastEvent(t) {},
                  addToEventQueue: function addToEventQueue(t) {
                    if (this.active && !this.isPausedBySibling()) {
                      var e = this.getTimestamp();
                      null != t ? (this.event_queue.push(t.getProperties()), OWA.debug("Now logging %s for: %d", t.get("event_type"), e)) : OWA.debug("No event properties to log");
                    }
                  },
                  isPausedBySibling: function isPausedBySibling() {
                    return OWA.getSetting("loggerPause");
                  },
                  sleep: function sleep(t) {
                    for (var e = new Date().getTime(); new Date().getTime() < e + t;) {
                      ;
                    }
                  },
                  pause: function pause() {
                    this.active = !1;
                  },
                  restart: function restart() {
                    this.active = !0;
                  },
                  makeEvent: function makeEvent() {
                    return new OWA.event();
                  },
                  addStreamEventBinding: function addStreamEventBinding(t) {
                    this.streamBindings.push(t);
                  },
                  getCampaignProperties: function getCampaignProperties() {
                    !this.urlParams.length > 0 && (this.urlParams = OWA.util.parseUrlParams(document.URL), OWA.debug("GET: " + JSON.stringify(this.urlParams)));

                    for (var t = this.getOption("campaignKeys"), e = {}, n = 0, r = t.length; n < r; n++) {
                      this.urlParams.hasOwnProperty(t[n]["public"]) && (e[t[n]["private"]] = this.urlParams[t[n]["public"]], this.isNewCampaign = !0);
                    }

                    return e.at && !e.ad && (e.ad = "(not set)"), e.ad && !e.at && (e.at = "(not set)"), e;
                  },
                  setCampaignSessionState: function setCampaignSessionState(t) {
                    for (var e = this.getOption("campaignKeys"), n = 0, r = e.length; n < r; n++) {
                      t.hasOwnProperty(e[n]["private"]) && OWA.setState("s", e[n].full, t[e[n]["private"]]);
                    }
                  },
                  setCampaignRelatedProperties: function setCampaignRelatedProperties(t) {
                    var e = this.getCampaignProperties();
                    OWA.debug("campaign properties: %s", JSON.stringify(e));

                    for (var n = this.getOption("campaignKeys"), r = 0, o = n.length; r < o; r++) {
                      e.hasOwnProperty(n[r]["private"]) && this.setGlobalEventProperty(n[r].full, e[n[r]["private"]]);
                    }
                  },
                  directAttributionModel: function directAttributionModel(t) {
                    if (this.isNewCampaign) return OWA.debug("campaign state length: %s", this.campaignState.length), this.campaignState.push(t), this.campaignState.length > this.options.maxPriorCampaigns && (this.campaignState.splice(0, 1), OWA.debug("Too many prior campaigns in state store. Dropping oldest to make room.")), this.setCampaignCookie(this.campaignState), this.isTrafficAttributed = !0, this.setCampaignSessionState(t), t;
                  },
                  originalAttributionModel: function originalAttributionModel(t) {
                    return this.campaignState.length > 0 ? (OWA.debug("Original attribution detected."), t = this.campaignState[0], this.isTrafficAttributed = !0) : (OWA.debug("Setting Original Campaign touch."), this.isNewCampaign && (this.campaignState.push(t), this.setCampaignCookie(this.campaignState), this.isTrafficAttributed = !0)), this.setCampaignSessionState(t), t;
                  },
                  setCampaignMediumKey: function setCampaignMediumKey(t) {
                    this.options.campaignKeys[0]["public"] = t;
                  },
                  setCampaignNameKey: function setCampaignNameKey(t) {
                    this.options.campaignKeys[1]["public"] = t;
                  },
                  setCampaignSourceKey: function setCampaignSourceKey(t) {
                    this.options.campaignKeys[2]["public"] = t;
                  },
                  setCampaignSearchTermsKey: function setCampaignSearchTermsKey(t) {
                    this.options.campaignKeys[3]["public"] = t;
                  },
                  setCampaignAdKey: function setCampaignAdKey(t) {
                    this.options.campaignKeys[4]["public"] = t;
                  },
                  setCampaignAdTypeKey: function setCampaignAdTypeKey(t) {
                    this.options.campaignKeys[5]["public"] = t;
                  },
                  setTrafficAttribution: function setTrafficAttribution(t, e) {
                    var n = OWA.getState("c", "attribs");
                    n && (this.campaignState = n);
                    var r = this.getCampaignProperties();

                    switch (this.options.trafficAttributionMode) {
                      case "direct":
                        OWA.debug('Applying "Direct" Traffic Attribution Model'), r = this.directAttributionModel(r);
                        break;

                      case "original":
                        OWA.debug('Applying "Original" Traffic Attribution Model'), r = this.originalAttributionModel(r);
                        break;

                      default:
                        OWA.debug("Applying Default (Direct) Traffic Attribution Model"), this.directAttributionModel(r);
                    }

                    this.isTrafficAttributed ? OWA.debug("Attributed Traffic to: %s", JSON.stringify(r)) : !0 === this.isNewSessionFlag && (OWA.debug("Infering traffic attribution."), this.inferTrafficAttribution());

                    for (var o = this.getOption("campaignKeys"), i = 0, s = o.length; i < s; i++) {
                      var a = OWA.getState("s", o[i].full);
                      a && this.setGlobalEventProperty(o[i].full, a);
                    }

                    var u = OWA.getState("s", "referer");
                    u && this.setGlobalEventProperty("session_referer", u), this.campaignState.length > 0 && this.setGlobalEventProperty("attribs", JSON.stringify(this.campaignState)), e && "function" == typeof e && e(t);
                  },
                  inferTrafficAttribution: function inferTrafficAttribution() {
                    var t = document.referrer,
                        e = "direct",
                        n = "(none)",
                        r = "(none)",
                        o = "(none)";

                    if (t) {
                      var i = new OWA.uri(t);

                      if (document.domain != i.getHost()) {
                        e = "referral", o = t, n = OWA.util.stripWwwFromDomain(i.getHost());
                        var s = this.isRefererSearchEngine(i);
                        s && (e = "organic-search", r = s.t || "(not provided)");
                      }
                    }

                    OWA.setState("s", "referer", o), OWA.setState("s", "medium", e), OWA.setState("s", "source", n), OWA.setState("s", "search_terms", r);
                  },
                  setCampaignCookie: function setCampaignCookie(t) {
                    OWA.setState("c", "attribs", t, "", "json", this.options.campaignAttributionWindow);
                  },
                  isRefererSearchEngine: function isRefererSearchEngine(t) {
                    for (var e = 0, n = this.organicSearchEngines.length; e < n; e++) {
                      var r = this.organicSearchEngines[e].d,
                          o = this.organicSearchEngines[e].q,
                          i = t.getHost(),
                          s = t.getQueryParam(o);
                      if (OWA.util.strpos(i, r)) return OWA.debug("Found search engine: %s with query param %s:, query term: %s", r, o, s), {
                        d: r,
                        q: o,
                        t: s
                      };
                    }
                  },
                  addOrganicSearchEngine: function addOrganicSearchEngine(t, e, n) {
                    var r = {
                      d: t,
                      q: e
                    };
                    n ? this.organicSearchEngines.unshift(r) : this.organicSearchEngines.push(r);
                  },
                  addTransaction: function addTransaction(t, e, n, r, o, i, s, a, u) {
                    this.ecommerce_transaction = new OWA.event(), this.ecommerce_transaction.setEventType("ecommerce.transaction"), this.ecommerce_transaction.set("ct_order_id", t), this.ecommerce_transaction.set("ct_order_source", e), this.ecommerce_transaction.set("ct_total", n), this.ecommerce_transaction.set("ct_tax", r), this.ecommerce_transaction.set("ct_shipping", o), this.ecommerce_transaction.set("ct_gateway", i), this.ecommerce_transaction.set("page_url", this.getCurrentUrl()), this.ecommerce_transaction.set("city", s), this.ecommerce_transaction.set("state", a), this.ecommerce_transaction.set("country", u), OWA.debug("setting up ecommerce transaction"), this.ecommerce_transaction.set("ct_line_items", []), OWA.debug("completed setting up ecommerce transaction");
                  },
                  addTransactionLineItem: function addTransactionLineItem(t, e, n, r, o, i) {
                    this.ecommerce_transaction || this.addTransaction("none set");
                    var s = {};
                    s.li_order_id = t, s.li_sku = e, s.li_product_name = n, s.li_category = r, s.li_unit_price = o, s.li_quantity = i;
                    var a = this.ecommerce_transaction.get("ct_line_items");
                    a.push(s), this.ecommerce_transaction.set("ct_line_items", a);
                  },
                  trackTransaction: function trackTransaction() {
                    this.ecommerce_transaction && (this.trackEvent(this.ecommerce_transaction), this.ecommerce_transaction = "");
                  },
                  setNumberPriorSessions: function setNumberPriorSessions(t, e) {
                    OWA.debug("setting number of prior sessions");
                    var n = OWA.getState("v", "nps");
                    this.isNewSessionFlag && (n ? (n *= 1, n++) : n = "0", OWA.setState("v", "nps", n, !0)), this.setGlobalEventProperty("nps", n), e && "function" == typeof e && e(t);
                  },
                  setDaysSinceLastSession: function setDaysSinceLastSession(t, e) {
                    OWA.debug("setting days since last session.");
                    var n = "";

                    if (this.getGlobalEventProperty("is_new_session")) {
                      OWA.debug("timestamp: %s", t.get("timestamp"));
                      var r = this.getGlobalEventProperty("last_req") || t.get("timestamp");
                      OWA.debug("last_req: %s", r), n = Math.round((t.get("timestamp") - r) / 86400), OWA.setState("s", "dsps", n);
                    }

                    n || (n = OWA.getState("s", "dsps") || 0), this.setGlobalEventProperty("dsps", n), e && "function" == typeof e && e(t);
                  },
                  setVisitorId: function setVisitorId(t, e) {
                    var n = OWA.getState("v", "vid");

                    if (!n) {
                      var r = OWA.getState("v");
                      OWA.util.is_object(r) || (n = r, OWA.clearState("v"), OWA.setState("v", "vid", n, !0));
                    }

                    n || (n = OWA.util.generateRandomGuid(this.siteId), this.globalEventProperties.is_new_visitor = !0, OWA.debug("Creating new visitor id")), OWA.setState("v", "vid", n, !0), this.setGlobalEventProperty("visitor_id", n), e && "function" == typeof e && e(t);
                  },
                  setFirstSessionTimestamp: function setFirstSessionTimestamp(t, e) {
                    var n = OWA.getState("v", "fsts");
                    n || (n = t.get("timestamp"), OWA.debug("setting fsts value: %s", n), OWA.setState("v", "fsts", n, !0)), this.setGlobalEventProperty("fsts", n);
                    var r = Math.round((t.get("timestamp") - n) / 86400);
                    OWA.setState("v", "dsfs", r), this.setGlobalEventProperty("dsfs", r), e && "function" == typeof e && e(t);
                  },
                  setLastRequestTime: function setLastRequestTime(t, e) {
                    var n = OWA.getState("s", "last_req");

                    if (OWA.debug("last_req from cookie: %s", n), !n) {
                      var r = OWA.util.sprintf("%s_%s", "ss", this.siteId);
                      n = OWA.getState(r, "last_req");
                    }

                    OWA.debug("setting last_req global property of %s", n), this.setGlobalEventProperty("last_req", n), OWA.setState("s", "last_req", t.get("timestamp"), !0), e && "function" == typeof e && e(t);
                  },
                  setSessionId: function setSessionId(t, e) {
                    var n = "",
                        r = "";

                    if (this.isNewSession(t.get("timestamp"), this.getGlobalEventProperty("last_req"))) {
                      var o = OWA.getState("s", "sid");
                      o || (r = OWA.util.sprintf("%s_%s", "ss", this.getSiteId()), o = OWA.getState(r, "s")), o && (this.globalEventProperties.prior_session_id = o), this.resetSessionState(), n = OWA.util.generateRandomGuid(this.getSiteId()), this.globalEventProperties.session_id = n, this.globalEventProperties.is_new_session = !0, this.isNewSessionFlag = !0, OWA.setState("s", "sid", n, !0);
                    } else (n = OWA.getState("s", "sid")) || (r = OWA.util.sprintf("%s_%s", "ss", this.getSiteId()), n = OWA.getState(r, "s"), OWA.setState("s", "sid", n, !0)), this.globalEventProperties.session_id = n;

                    this.getGlobalEventProperty("session_id") || (n = OWA.util.generateRandomGuid(this.getSiteId()), this.globalEventProperties.session_id = n, this.globalEventProperties.is_new_session = !0, this.isNewSessionFlag = !0, OWA.setState("s", "sid", n, !0)), e && "function" == typeof e && e(t);
                  },
                  resetSessionState: function resetSessionState() {
                    var t = OWA.getState("s", "last_req");
                    OWA.clearState("s"), OWA.setState("s", "last_req", t);
                  },
                  isNewSession: function isNewSession(t, e) {
                    return t || (t = OWA.util.getCurrentUnixTimestamp()), e || (e = 0), t - e < this.options.sessionLength ? (OWA.debug("This request is part of a active session."), !1) : (OWA.debug("This request is the start of a new session. Prior session expired."), !0);
                  },
                  getGlobalEventProperty: function getGlobalEventProperty(t) {
                    if (this.globalEventProperties.hasOwnProperty(t)) return this.globalEventProperties[t];
                  },
                  setGlobalEventProperty: function setGlobalEventProperty(t, e) {
                    this.globalEventProperties[t] = e;
                  },
                  deleteGlobalEventProperty: function deleteGlobalEventProperty(t) {
                    this.globalEventProperties.hasOwnProperty(t) && delete this.globalEventProperties[t];
                  },
                  setCustomVar: function setCustomVar(t, e, n, r) {
                    var o = "cv" + t,
                        i = e + "=" + n;
                    if (i.length > 65) OWA.debug("Custom variable name + value is too large. Must be less than 64 characters.");else {
                      switch (r) {
                        case "session":
                          OWA.util.setState("b", o, i), OWA.debug("just set custom var on session.");
                          break;

                        case "visitor":
                          OWA.util.setState("v", o, i), OWA.util.clearState("b", o);
                      }

                      this.setGlobalEventProperty(o, i);
                    }
                  },
                  getCustomVar: function getCustomVar(t) {
                    var e = "cv" + t,
                        n = "";
                    return (n = this.getGlobalEventProperty(e)) || (n = OWA.util.getState("b", e)), n || (n = OWA.util.getState("v", e)), n;
                  },
                  deleteCustomVar: function deleteCustomVar(t) {
                    var e = "cv" + t;
                    OWA.util.clearState("b", e), OWA.util.clearState("v", e), this.deleteGlobalEventProperty(e);
                  },
                  addDefaultsToEvent: function addDefaultsToEvent(t, e) {
                    t.set("site_id", this.getSiteId()), t.get("page_url") || this.getGlobalEventProperty("page_url") || t.set("page_url", this.getCurrentUrl()), t.get("HTTP_REFERER") || this.getGlobalEventProperty("HTTP_REFERER") || t.set("HTTP_REFERER", document.referrer), t.get("page_title") || this.getGlobalEventProperty("page_title") || t.set("page_title", OWA.util.trim(document.title)), t.get("timestamp") || t.set("timestamp", this.getTimestamp()), e && "function" == typeof e && e(t);
                  },
                  addGlobalPropertiesToEvent: function addGlobalPropertiesToEvent(t, e) {
                    for (var n = 1; n <= this.getOption("maxCustomVars"); n++) {
                      var r = "cv" + n,
                          o = "";
                      this.globalEventProperties.hasOwnProperty(r) || (o = this.getCustomVar(n)) && this.setGlobalEventProperty(r, o);
                    }

                    for (var i in OWA.debug("Adding global properties to event: %s", JSON.stringify(this.globalEventProperties)), this.globalEventProperties) {
                      this.globalEventProperties.hasOwnProperty(i) && !t.isSet(i) && t.set(i, this.globalEventProperties[i]);
                    }

                    e && "function" == typeof e && e(t);
                  },
                  manageState: function manageState(t, e) {
                    var n = this;
                    this.stateInit || this.setVisitorId(t, function (t) {
                      n.setFirstSessionTimestamp(t, function (t) {
                        n.setLastRequestTime(t, function (t) {
                          n.setSessionId(t, function (t) {
                            n.setNumberPriorSessions(t, function (t) {
                              n.setDaysSinceLastSession(t, function (t) {
                                n.setTrafficAttribution(t, function (t) {
                                  n.stateInit = !0;
                                });
                              });
                            });
                          });
                        });
                      });
                    }), e && "function" == typeof e && e(t);
                  },
                  trackEvent: function trackEvent(t, e) {
                    1 != this.getOption("cookie_domain_set") && this.setCookieDomain();
                    var n = !1;
                    if (this.active) if (e && (n = !0), this.getOption("thirdParty")) this.globalEventProperties.thirdParty = !0, this.setCampaignRelatedProperties(t);else {
                      var r = this;
                      this.manageState(t, function (t) {
                        r.addGlobalPropertiesToEvent(t, function (t) {
                          r.addDefaultsToEvent(t, function (t) {
                            return r.logEvent(t.getProperties(), n);
                          });
                        });
                      });
                    }
                  },
                  trackPageView: function trackPageView(t) {
                    var e = new OWA.event();
                    return t && e.set("page_url", t), e.setEventType("base.page_request"), this.trackEvent(e);
                  },
                  trackAction: function trackAction(t, e, n, r) {
                    var o = new OWA.event();
                    o.setEventType("track.action"), o.set("action_group", t), o.set("action_name", e), o.set("action_label", n), o.set("numeric_value", r), this.trackEvent(o), OWA.debug("Action logged");
                  },
                  trackClicks: function trackClicks(t) {
                    this.setOption("logClicksAsTheyHappen", !0), this.bindClickEvents();
                  },
                  logDomStream: function logDomStream() {
                    var t = new OWA.event();

                    if (this.event_queue.length > this.options.domstreamEventThreshold) {
                      if (!this.domstream_guid) {
                        var e = "domstream" + this.getCurrentUrl() + this.getSiteId();
                        this.domstream_guid = OWA.util.generateRandomGuid(e);
                      }

                      t.setEventType("dom.stream"), t.set("domstream_guid", this.domstream_guid), t.set("duration", this.getElapsedTime()), t.set("stream_events", JSON.stringify(this.event_queue)), t.set("stream_length", this.event_queue.length);
                      var n = this.getViewportDimensions();
                      return t.set("page_width", n.width), t.set("page_height", n.height), this.event_queue = [], this.trackEvent(t);
                    }

                    OWA.debug("Domstream had too few events to log.");
                  },
                  trackDomStream: function trackDomStream() {
                    if (this.active) if (Math.floor(100 * Math.random() + 1) <= this.getOption("logDomStreamPercentage")) {
                      this.setOption("trackDomStream", !0);

                      for (var t = this.streamBindings.length, e = 0; e < t; e++) {
                        this.callMethod(this.streamBindings[e]);
                      }

                      this.startDomstreamTimer();
                    } else OWA.debug("not tracking domstream for this user.");
                  }
                }, function () {
                  if (OWA.util.isBrowserTrackable()) {
                    if ("undefined" == typeof owa_cmds) var t = new OWA.commandQueue();else OWA.util.is_array(owa_cmds) && (t = new OWA.commandQueue()).loadCmds(owa_cmds);
                    window.owa_cmds = t, window.owa_cmds.process();
                  }
                }();
              },,,,,,,,, function (t, e) {}]);
            },,,,,,,,, function (t, e) {}]);
          }
        });
      },,,,,,,, function (t, e) {},,,,, function (t, e) {}]);
    },,,,,,,, function (t, e) {},,,,, function (t, e) {}]);
    /***/
  },

  /***/
  "./modules/base/sass/owa.reporting-combined.scss":
  /*!*******************************************************!*\
    !*** ./modules/base/sass/owa.reporting-combined.scss ***!
    \*******************************************************/

  /*! no static exports found */

  /***/
  function modulesBaseSassOwaReportingCombinedScss(module, exports) {// removed by extract-text-webpack-plugin

    /***/
  },

  /***/
  "./modules/base/sass/owa.scss":
  /*!************************************!*\
    !*** ./modules/base/sass/owa.scss ***!
    \************************************/

  /*! no static exports found */

  /***/
  function modulesBaseSassOwaScss(module, exports) {// removed by extract-text-webpack-plugin

    /***/
  },

  /***/
  0:
  /*!****************************************************************************************************************************************************!*\
    !*** multi ./modules/base/js/owa.js ./modules/base/js/owa.tracker.js ./modules/base/sass/owa.scss ./modules/base/sass/owa.reporting-combined.scss ***!
    \****************************************************************************************************************************************************/

  /*! no static exports found */

  /***/
  function _(module, exports, __webpack_require__) {
    __webpack_require__(
    /*! /var/www/stats.artfulrobot.uk/public/modules/base/js/owa.js */
    "./modules/base/js/owa.js");

    __webpack_require__(
    /*! /var/www/stats.artfulrobot.uk/public/modules/base/js/owa.tracker.js */
    "./modules/base/js/owa.tracker.js");

    __webpack_require__(
    /*! /var/www/stats.artfulrobot.uk/public/modules/base/sass/owa.scss */
    "./modules/base/sass/owa.scss");

    module.exports = __webpack_require__(
    /*! /var/www/stats.artfulrobot.uk/public/modules/base/sass/owa.reporting-combined.scss */
    "./modules/base/sass/owa.reporting-combined.scss");
    /***/
  }
  /******/

});

/***/ }),

/***/ "./modules/base/sass/owa.reporting-combined.scss":
/*!*******************************************************!*\
  !*** ./modules/base/sass/owa.reporting-combined.scss ***!
  \*******************************************************/
/*! no static exports found */
/***/ (function(module, exports) {

// removed by extract-text-webpack-plugin

/***/ }),

/***/ "./modules/base/sass/owa.scss":
/*!************************************!*\
  !*** ./modules/base/sass/owa.scss ***!
  \************************************/
/*! no static exports found */
/***/ (function(module, exports) {

// removed by extract-text-webpack-plugin

/***/ }),

/***/ 0:
/*!****************************************************************************************************************************************************!*\
  !*** multi ./modules/base/js/owa.js ./modules/base/js/owa.tracker.js ./modules/base/sass/owa.scss ./modules/base/sass/owa.reporting-combined.scss ***!
  \****************************************************************************************************************************************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {

__webpack_require__(/*! /var/www/stats.artfulrobot.uk/public/modules/base/js/owa.js */"./modules/base/js/owa.js");
__webpack_require__(/*! /var/www/stats.artfulrobot.uk/public/modules/base/js/owa.tracker.js */"./modules/base/js/owa.tracker.js");
__webpack_require__(/*! /var/www/stats.artfulrobot.uk/public/modules/base/sass/owa.scss */"./modules/base/sass/owa.scss");
module.exports = __webpack_require__(/*! /var/www/stats.artfulrobot.uk/public/modules/base/sass/owa.reporting-combined.scss */"./modules/base/sass/owa.reporting-combined.scss");


/***/ })

/******/ });