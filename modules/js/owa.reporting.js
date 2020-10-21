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
/******/ 	return __webpack_require__(__webpack_require__.s = 1);
/******/ })
/************************************************************************/
/******/ ({

/***/ "./modules/base/js-src/owa.areachart.js":
/*!**********************************************!*\
  !*** ./modules/base/js-src/owa.areachart.js ***!
  \**********************************************/
/*! no static exports found */
/***/ (function(module, exports) {

OWA.areaChart = function (options) {
  // config options
  this.options = {
    series: [],
    height: 125,
    width: '99%',
    // needed for flot resize plugin
    xaxis: {
      mode: 'time'
    },
    timeformat: "%m/%d",
    showGrid: true,
    showLegend: true,
    showDots: true,
    lineWidth: 4,
    autoResizeCharts: true,
    fillColor: "rgba(202,225,255, 0.6)",
    colors: ["#1874CD", "#dba255", "#919733"]
  }; // merge passed options with defaults.

  if (options) {
    for (option in options) {
      if (options.hasOwnProperty(option)) {
        this.options[option] = options[option];
      }
    }
  }

  this.dom_id = '';
  this.domSelector = '';
  this.init = false;
};

OWA.areaChart.prototype = {
  setDomId: function setDomId(dom_id) {
    this.dom_id = dom_id;
    this.domSelector = "#" + dom_id + ' > .owa_areaChart'; // listen for data change events

    var that = this;
    jQuery('#' + that.dom_id).bind('new_result_set', function (event, resultSet) {
      //jQuery( that.domSelector ).remove();
      that.generate(resultSet);
    });
  },
  getOption: function getOption(name) {
    if (this.options.hasOwnProperty(name)) {
      return this.options[name];
    }
  },
  setOption: function setOption(name, value) {
    this.options[name] = value;
  },
  getContainerHeight: function getContainerHeight() {
    var that = this;
    var h = jQuery("#" + that.dom_id).height(); //alert(h);

    return h;
  },
  // move to OWA.util
  formatValue: function formatValue(type, value) {
    switch (type) {
      // convery yyyymmdd to javascript timestamp as  flot requires that
      case 'yyyymmdd':
        //date = jQuery.datepicker.parseDate('yymmdd', value);
        //value = Date.parse(date);
        var year = value.substring(0, 4) * 1;
        var month = value.substring(4, 6) * 1 - 1;
        var day = value.substring(6, 8) * 1;
        var d = Date.UTC(year, month, day, 0, 0, 0, 0);
        value = d;
        OWA.debug('year: %s, month: %s, day: %s, timestamp: %s', year, month, day, d);
        break;

      case 'currency':
        value = value / 100;
    }

    return value;
  },
  timestampFormatter: function timestampFormatter(timestamp) {
    var d = new Date(timestamp * 1);
    var curr_date = d.getUTCDate();
    var curr_month = d.getUTCMonth() + 1;
    var curr_year = d.getUTCFullYear(); //alert(d+' date: '+curr_month);

    var date = curr_month + "/" + curr_date + "/" + curr_year; //var date =  curr_month + "/" + curr_date;

    return date;
  },

  /**
   * Main method for displaying an area chart
   */
  generate: function generate(resultSet, series, dom_id) {
    OWA.debug('generating area chart for ' + dom_id); // set dom_id just in case.

    if (dom_id) {
      this.setDomId(dom_id);
    }

    dom_id = this.dom_id; // set series just in case.

    if (series) {
      this.options.series = series;
    }

    var selector = this.domSelector; // remove in case the chart is already there.
    // this is kind of a hack as it mean that only one area chart can be placed in a dom_id at a time.
    // this is needed so that charts can be over riden when report
    // tabs change.

    jQuery(selector).remove(); // if there is data, plot it.

    if (resultSet.resultsRows.length > 0) {
      // create data array for flot.
      var dataseries = [];
      series = this.options.series;
      var data = [];

      for (var ii = 0; ii <= series.length - 1; ii++) {
        var x_series_name = series[ii].x;
        var y_series_name = series[ii].y; //create data array

        for (var i = 0; i <= resultSet.resultsRows.length - 1; i++) {
          data_type_x = resultSet.resultsRows[i][x_series_name].data_type;
          data_type_y = resultSet.resultsRows[i][y_series_name].data_type;
          var item = [this.formatValue(data_type_x, resultSet.resultsRows[i][x_series_name].value), this.formatValue(data_type_y, resultSet.resultsRows[i][y_series_name].value)];
          data.push(item);
        } //alert(this.resultSet.resultsRows[i][series[ii].x].value);


        var l = resultSet.getMetricLabel(y_series_name);
        dataseries.push({
          label: l,
          data: data
        });
      } //if ( ! this.init ) {


      OWA.debug('ac init not set');
      this.setupAreaChart(series, dom_id); //}

      var num_ticks = data.length; // reduce number of x axis ticks if data set has too many points.

      if (data.length > 10) {
        num_ticks = 10;
      }

      var options = {
        yaxis: {
          tickDecimals: 0
        },
        xaxis: {
          ticks: num_ticks,
          tickDecimals: null
        },
        grid: {
          show: this.options.showGrid,
          hoverable: true,
          autoHilight: true,
          borderWidth: 0,
          borderColor: null
        },
        series: {
          points: {
            show: this.options.showDots,
            fill: this.options.showDots
          },
          lines: {
            show: true,
            fill: true,
            fillColor: this.options.fillColor,
            lineWidth: this.options.lineWidth
          }
        },
        colors: this.options.colors,
        legend: {
          position: 'ne',
          margin: [0, -10],
          show: this.options.showLegend
        }
      };

      if (data_type_x === 'yyyymmdd') {
        options.xaxis.mode = "time"; //options.xaxis.timeformat = "%m/%d/%y";

        options.xaxis.timeformat = this.options.timeformat;
      } //this.options.areaChart.flot = options;


      OWA.debug('Plotting area graph in ' + selector);
      var selector_dom = jQuery(selector);
      jQuery.plot(selector_dom, dataseries, options);
      this.init = true;
    } else {
      jQuery('#' + dom_id).html("No data is available for this time period");
      jQuery('#' + dom_id).css('height', '50px');
    }
  },
  // shows a tool tip for flot charts
  showTooltip: function showTooltip(x, y, contents) {
    jQuery('<div id="tooltip">' + contents + '</div>').css({
      position: 'absolute',
      display: 'none',
      top: y + 5,
      left: x + 5,
      border: '1px solid #cccccc',
      padding: '2px',
      'background-color': '#ffffff',
      opacity: 0.90
    }).appendTo("body").fadeIn(100);
  },
  setupAreaChart: function setupAreaChart(series, dom_id) {
    dom_id = dom_id || this.dom_id;
    var that = this; //var w = this.getContainerWidth();

    var w = jQuery("#" + dom_id).css('width'); //alert(w);

    var h = this.getContainerHeight() || this.getOption('height'); //var h = this.getOption('height');

    jQuery("#" + dom_id).html('<div class="owa_areaChart"></div>');
    jQuery(that.domSelector).css('width', this.getOption('width'));
    jQuery(that.domSelector).css('height', h); // binds a tooltip to plot points

    var previousPoint = null;
    jQuery(that.domSelector).bind("plothover", function (event, pos, item) {
      jQuery("#x").text(pos.x.toFixed(2));
      jQuery("#y").text(pos.y.toFixed(2));

      if (item) {
        if (previousPoint != item.datapoint) {
          previousPoint = item.datapoint;
          jQuery("#tooltip").remove();
          var x = item.datapoint[0].toFixed(0),
              y = item.datapoint[1].toFixed(0);

          if (that.options.xaxis.mode === 'time') {
            x = that.timestampFormatter(x);
          }

          that.showTooltip(item.pageX - 75, item.pageY - 50, x + '<BR><B>' + item.series.label + ":</B> " + y);
        }
      } else {
        jQuery("#tooltip").remove();
        previousPoint = null;
      }
    });
  }
};

/***/ }),

/***/ "./modules/base/js-src/owa.js":
/*!************************************!*\
  !*** ./modules/base/js-src/owa.js ***!
  \************************************/
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

/***/ "./modules/base/js-src/owa.kpibox.js":
/*!*******************************************!*\
  !*** ./modules/base/js-src/owa.kpibox.js ***!
  \*******************************************/
/*! no static exports found */
/***/ (function(module, exports) {

OWA.kpiBox = function (options) {
  // config options
  this.options = {
    template: '#metricInfobox',
    width: ''
  }; // merge passed options with defaults.

  if (options) {
    this.mergeOptions(options);
  }

  this.dom_id = '';
  this.domSelector = '';
};

OWA.kpiBox.prototype = {
  mergeOptions: function mergeOptions(options) {
    for (option in options) {
      if (options.hasOwnProperty(option)) {
        this.options[option] = options[option];
      }
    }
  },
  setDomId: function setDomId(dom_id) {
    this.dom_id = dom_id;
    this.domSelector = this.dom_id + ' > .metricInfoboxesContainer'; // listen for data change events

    var that = this;
    jQuery('#' + that.dom_id).bind('new_result_set', function (event, resultSet) {
      jQuery(that.domSelector).remove();
      that.generate(resultSet);
    });
  },
  getOption: function getOption(name) {
    if (this.options.hasOwnProperty(name)) {
      return this.options[name];
    }
  },
  setOption: function setOption(name, value) {
    this.options[name] = value;
  },
  generate: function generate(resultSet, dom_id, options) {
    OWA.debug('Generating KPI box for: ' + dom_id + ' with options: ' + JSON.stringify(options));

    if (dom_id) {
      this.setDomId(dom_id);
    }

    dom_id = this.dom_id;

    if (options) {
      this.mergeOptions(options);
    }

    var html = '';
    var con_id = 'kpiContainer-' + resultSet.guid;
    jQuery('#' + dom_id).append(OWA.util.sprintf('<div id="%s" class="metricInfoboxesContainer" style="width:auto;"></div><div style="clear:both;"></div>', con_id)); //jQuery('#' + dom_id).append('<div style="clear:both;"></div>');

    for (var i in resultSet.aggregates) {
      if (resultSet.aggregates.hasOwnProperty(i)) {
        var item = resultSet.aggregates[i];
        item.dom_id = dom_id + '-' + resultSet.aggregates[i].name + '-' + resultSet.guid;

        if (this.options.label) {
          item.label = this.options.label;
        }

        if (this.options.width) {
          item.width = this.options.width;
        }

        var selector = '#' + this.domSelector;
        var width = item.width || 'auto';
        var html = OWA.util.sprintf('<div id ="%s" class="owa_metricInfobox" style="min-width:135px;width:%s">', item.dom_id, width);
        html += OWA.util.sprintf('<p class="owa_metricInfoboxLabel">%s</p>', item.label);
        html += OWA.util.sprintf('<p class="owa_metricInfoboxLargeNumber">%s</p>', item.formatted_value);
        html += '</div>';
        jQuery('#' + con_id).append(html);
        var spark_options = {
          metric: resultSet.aggregates[i].name,
          filter: ''
        };

        if (this.options.filter) {
          spark_options.filter = this.options.filter;
        }

        var sl = new OWA.sparkline();
        sl.generate(resultSet, item.dom_id, spark_options);
      }
    }
  }
};

/***/ }),

/***/ "./modules/base/js-src/owa.piechart.js":
/*!*********************************************!*\
  !*** ./modules/base/js-src/owa.piechart.js ***!
  \*********************************************/
/*! no static exports found */
/***/ (function(module, exports) {

OWA.pieChart = function (options) {
  // config options
  this.options = {
    height: 200,
    width: 200,
    metric: '',
    dimension: '',
    metrics: [],
    numSlices: 5,
    showGrid: true,
    showDots: true,
    showLegend: true,
    autoSizeWidth: true
  }; // merge passed options with defaults.

  if (options) {
    this.mergeOptions(options);
  }

  this.dom_id = '';
  this.domSelector = '';
};

OWA.pieChart.prototype = {
  mergeOptions: function mergeOptions(options) {
    for (option in options) {
      if (options.hasOwnProperty(option)) {
        this.options[option] = options[option];
      }
    }
  },
  setDomId: function setDomId(dom_id) {
    this.dom_id = dom_id;
    this.domSelector = "#" + this.dom_id + ' > .owa_pieChart'; // listen for data change events

    var that = this;
    jQuery('#' + that.dom_id).bind('new_result_set', function (event, resultSet) {
      //jQuery( that.domSelector ).remove();
      that.generate(resultSet);
    });
  },
  getOption: function getOption(name) {
    if (this.options.hasOwnProperty(name)) {
      return this.options[name];
    }
  },
  setOption: function setOption(name, value) {
    this.options[name] = value;
  },
  // move to OWA.util
  formatValue: function formatValue(type, value) {
    switch (type) {
      // convery yyyymmdd to javascript timestamp as  flot requires that
      case 'yyyymmdd':
        //date = jQuery.datepicker.parseDate('yymmdd', value);
        //value = Date.parse(date);
        var year = value.substring(0, 4) * 1;
        var month = value.substring(4, 6) * 1 - 1;
        var day = value.substring(6, 8) * 1;
        var d = Date.UTC(year, month, day, 0, 0, 0, 0);
        value = d;
        OWA.debug('year: %s, month: %s, day: %s, timestamp: %s', year, month, day, d);
        break;

      case 'currency':
        value = value / 100;
    }

    return value;
  },
  setupPieChart: function setupPieChart() {
    var that = this;
    var w = this.getContainerWidth(); //alert(w);

    var h = this.getContainerWidth(); //this.getOption('chartHeight');
    //alert(h);

    jQuery("#" + that.dom_id).append('<div class="owa_pieChart"></div>');
    jQuery(that.domSelector).css('width', w);
    jQuery(that.domSelector).css('height', h);
  },
  generate: function generate(resultSet, dom_id, options) {
    OWA.debug('generating pie chart');

    if (dom_id) {
      this.setDomId(dom_id);
    }

    dom_id = this.dom_id;

    if (options) {
      this.mergeOptions(options);
    }

    var selector = this.domSelector;
    var that = this; //create data array

    var data = [];
    var count = 0;

    if (this.options.dimension.length > 0) {
      // plots a dimensional set of data
      if (resultSet.resultsRows.length > 0) {
        var dimension = this.options.dimension;
        var numSlices = this.options.numSlices;
        var metric = this.options.metric; //create data array

        var iterations = 0;

        if (numSlices > resultSet.resultsRows.length) {
          iterations = resultSet.resultsRows.length;
        } else {
          iterations = numSlices;
        }

        for (var i = 0; i <= iterations - 1; i++) {
          var item = {
            label: resultSet.resultsRows[i][dimension].value,
            data: resultSet.resultsRows[i][metric].value * 1
          };
          data.push(item);
          count = count + resultSet.resultsRows[i][metric].value;
        } // if there are extra slices then lump into other bucket.


        if (resultSet.resultsRows.length > iterations) {
          var others = resultSet.aggregates[metric] - count;
          data.push({
            label: 'others',
            data: others
          });
        }
      } else {
        //no results
        jQuery('#' + that.dom_id).append("No data is available for this time period");
        jQuery('#' + that.dom_id).css('height', '50px');
      }
    } else {
      if (!jQuery.isEmptyObject(resultSet.aggregates)) {
        // plots a set of values taken from the aggregrate metrics array
        var metrics = this.options.metrics;

        for (var ii = 0; ii <= metrics.length - 1; ii++) {
          var value = resultSet.aggregates[metrics[ii]].value * 1;
          data.push({
            label: resultSet.getMetricLabel(metrics[ii]),
            data: value
          });
        }
      } else {
        //OWA.setSetting('debug', true);
        //OWA.debug('there was no data');
        //alert('hi');
        jQuery('#' + that.dom_id).append("No data is available for this time period");
        jQuery('#' + that.dom_id).css('height', '50px');
      }
    }

    if (!this.init) {
      this.setupPieChart();
    } // options


    var flot_options = {
      series: {
        pie: {
          show: true,
          showLabel: true
          /*
                              label: {
                                  show: true,
                                  background: {
                                      color: '#ffffff',
                                      opacity: '.7'
                                  },
                                  radius:1,
                                  formatter: function(label, slice){
                                      return '<div style="font-size:x-small;text-align:center;padding:2px;color:'+slice.color+';">'+Math.round(slice.percent)+'%</div>';
                                  }
                                  //formatter: function(label, slice){ return '<div style="font-size:x-small;text-align:center;padding:2px;color:'+slice.color+';">'+label+'<br/>'+Math.round(slice.percent)+'%</div>';}
          
                              }
          */

        }
      },
      legend: {
        show: false,
        position: "ne",
        margin: [-160, 50]
      },
      colors: ["#6BAED6", "#FD8D3C", "#dba255", "#919733"]
    }; //GRAPH

    OWA.debug(JSON.stringify(data));
    jQuery.plot(jQuery(selector), data, flot_options);
    this.init = true;
  },
  // moved when migrating pie chart
  getContainerWidth: function getContainerWidth() {
    var that = this;

    if (this.getOption('autoSizeWidth')) {
      return jQuery("#" + that.dom_id).width();
    } else {
      return this.option.width;
    }
  },
  //move when migrating pie chart
  getContainerHeight: function getContainerHeight() {
    var that = this;
    var h = jQuery("#" + that.dom_id).height(); //alert(h);

    return h;
  }
};

/***/ }),

/***/ "./modules/base/js-src/owa.report.js":
/*!*******************************************!*\
  !*** ./modules/base/js-src/owa.report.js ***!
  \*******************************************/
/*! no static exports found */
/***/ (function(module, exports) {

OWA.report = function (dom_id, options) {
  this.options = {
    autoRefreshResultSets: false,
    autoRefreshResultSetsInterval: 15000
  };
  this.overrideOptions(options);
  this.dom_id = dom_id;
  this.config = OWA.config;
  this.properties = {};
  this.tabs = {};
  this.timePeriodControl = ''; // container for resultSetExplorer objects

  this.resultSetExplorers = {}; // is window active?

  this.isActive = false; // the dom id of the active tab

  this.activeTab = '';
  var ar = this.getOption('autoRefreshResultSets'); // bind focus/blur handlers
};

OWA.report.prototype = {
  display: function display() {},
  showAutoRefreshControl: function showAutoRefreshControl(options) {
    var selector = '';

    if (options.hasOwnProperty('target')) {
      selector = options.target;
    } else {
      selector = '#' + this.dom_id + ' > .liveViewSwitch';
    }

    if (options.hasOwnProperty('label')) {
      label = options.label;
    } else {
      selector = 'Live View: ';
    }

    var c = [];
    c.push('<div class="autoRefreshControl">');
    c.push(OWA.util.sprintf('<span class="label">%s</span>', label));
    c.push('<span class="buttons">');
    c.push('<input type="radio" name="autorefresh" id="autorefresh-on-button" /><label for="autorefresh-on-button">On</label>');
    c.push('<input type="radio" name="autorefresh" checked="checked" id="autorefresh-off-button" /><label for="autorefresh-off-button">Off</label>');
    c.push('</span>');
    c.push('<div style="clear:both;"></div>');
    c.push('</div>');
    jQuery(selector).append(c.join(' '));
    jQuery(selector + ' > .autoRefreshControl > .buttons').buttonset();
    var that = this;
    jQuery(selector + ' > .autoRefreshControl > .buttons > #autorefresh-on-button').click(function () {
      that.startAutoRefresh();
    });
    jQuery(selector + ' > .autoRefreshControl > .buttons > #autorefresh-off-button').click(function () {
      that.stopAutoRefresh();
    }); // bind window focus events to start auto refresh        

    jQuery(window).focus(function () {
      // set flag
      that.isActive = true; // enable auto-refesh if called for

      if (that.getOption('autoRefreshResultSets')) {
        that.startAutoRefresh();
      }
    }); // bind window blur event to stop needless auto-refreshes

    jQuery(window).blur(function () {
      // set flag
      that.isActive = false; //pause. stops but keeps the option set to true

      if (that.getOption('autoRefreshResultSets')) {
        that.pauseAutoRefresh();
      }
    });
  },
  startAutoRefresh: function startAutoRefresh() {
    var interval = this.getOption('autoRefreshResultSetsInterval');

    if (OWA.util.countObjectProperties(this.resultSetExplorers) > 0) {
      for (name in this.resultSetExplorers) {
        if (this.resultSetExplorers.hasOwnProperty(name)) {
          this.resultSetExplorers[name].enableAutoRefresh(interval);
        }
      }
    } // if there are any tabs, start their resultSetExplorers too.


    if (this.activeTab) {
      this.tabs[this.activeTab].startAutoRefresh();
    }

    this.options.autoRefreshResultSets = true;
  },
  stopAutoRefresh: function stopAutoRefresh() {
    if (OWA.util.countObjectProperties(this.resultSetExplorers) > 0) {
      for (name in this.resultSetExplorers) {
        if (this.resultSetExplorers.hasOwnProperty(name)) {
          this.resultSetExplorers[name].stopAutoRefresh();
        }
      }
    } // if there are any tabs, stop their resultSetExplorers too.
    // if there are any tabs, start their resultSetExplorers too.


    if (this.activeTab) {
      this.tabs[this.activeTab].stopAutoRefresh();
    }

    this.options.autoRefreshResultSets = false;
  },
  pauseAutoRefresh: function pauseAutoRefresh() {
    this.stopAutoRefresh();
    this.options.autoRefreshResultSets = true;
  },
  registerResultSetExplorer: function registerResultSetExplorer(name, rse) {
    if (this.getOption('autoRefreshResultSets')) {
      rse.enableAutoRefresh(this.getOption('autoRefreshResultSetsInterval'));
    }

    this.resultSetExplorers[name] = rse;
  },
  overrideOptions: function overrideOptions(options) {
    options = options || {}; // override default options

    for (option in options) {
      if (options.hasOwnProperty(option)) {
        this.options[option] = options[option];
      }
    }
  },
  getOption: function getOption(name) {
    if (this.options.hasOwnProperty(name)) {
      return this.options[name];
    }
  },
  config: '',
  displayTimePeriodPicker: function displayTimePeriodPicker(dom_id) {
    var that = this;
    dom_id = dom_id || '#owa_reportPeriodLabelContainer';

    if (!this.timePeriodControl) {
      this.timePeriodControl = new OWA.report.timePeriodControl(dom_id, {
        startDate: this.getStartDate(),
        endDate: this.getEndDate(),
        selectedPeriod: this.getProperty('period')
      }); //bind event listener for when a new date is set

      jQuery(dom_id).bind('owa_new_time_period_set', function (event, startDate, endDate) {
        that.setDateRange(startDate, endDate);
        that.reload();
      }); // bind event listener for when new fixed period is set
      // this will go away once data picker sets it's own fixed
      // time periods instead of relying on the server to do it.

      jQuery(dom_id).bind('owa_new_fixed_time_period_set', function (event, period) {
        that.reportSetTimePeriod(period);
      });
    }
  },
  showSiteFilter: function showSiteFilter(dom_id) {
    // create dom elements
    // ...
    // bind event handlers
    var that = this;
    jQuery('#owa_reportSiteFilterSelect').change(function () {
      that.reload();
    });
  },
  reportSetTimePeriod: function reportSetTimePeriod(period) {
    this.setPeriod(period);
    this.reload();
  },
  reload: function reload() {
    // add new site_id to properties
    var siteId = jQuery("#owa_reportSiteFilterSelect option:selected").val();
    OWA.debug(this.properties['action']);

    if (siteId != undefined) {
      this.properties['siteId'] = siteId;
    } // reload report    


    var url = OWA.util.makeUrl(OWA.config.link_template, OWA.config.main_url, this.properties);
    window.location.href = url;
  },
  _parseDate: function _parseDate(date) {},
  setDateRange: function setDateRange(startDate, endDate) {
    this.setProperty('startDate', startDate);
    this.setProperty('endDate', endDate);
    this.removeProperty('period');
  },
  setPeriod: function setPeriod(period) {
    this.properties.period = period;

    if (this.properties.hasOwnProperty('startDate')) {
      delete this.properties['startDate'];
    }

    if (this.properties.hasOwnProperty('endDate')) {
      delete this.properties['endDate'];
    }
  },
  addTab: function addTab(obj) {
    if (obj.dom_id.length > 0) {
      this.tabs[obj.dom_id] = obj;
    } else {
      OWA.debug('tab cannot be added with no dom_id set.');
    }
  },
  createTabs: function createTabs() {
    var that = this;
    jQuery("#report-tabs").prepend('<ul class="report-tabs-nav-list"></ul>');

    for (tab in this.tabs) {
      if (this.tabs.hasOwnProperty(tab)) {
        jQuery("#report-tabs > .report-tabs-nav-list").append(OWA.util.sprintf('<li><a href="#%s">%s</a></li>', tab, that.tabs[tab].label));
      }
    }

    jQuery("#report-tabs").tabs({
      show: function show(event, ui) {
        OWA.debug('tab selected is: %s', ui.panel.id);
        that.tabs[ui.panel.id].load(); // stop auto refresh of last selected tab

        if (that.activeTab && that.getOption('autoRefreshResultSets')) {
          that.tabs[that.activeTab].stopAutoRefresh();
        }

        that.activeTab = ui.panel.id; // start auto refresh of  selected tab

        if (that.activeTab && that.getOption('autoRefreshResultSets')) {
          that.tabs[that.activeTab].startAutoRefresh();
        }
      }
    });
  },
  getSiteId: function getSiteId() {
    return this.getProperty('siteId');
  },
  getPeriod: function getPeriod() {
    return this.getProperty('period');
  },
  getStartDate: function getStartDate() {
    return this.getProperty('startDate');
  },
  getEndDate: function getEndDate() {
    return this.getProperty('endDate');
  },
  setRequestProperty: function setRequestProperty(name, value) {
    this.setProperty(name, value);
  },
  setProperty: function setProperty(name, value) {
    this.properties[name] = value;
  },
  removeProperty: function removeProperty(name) {
    if (this.properties.hasOwnProperty(name)) {
      delete this.properties[name];
    }
  },
  getProperty: function getProperty(name) {
    if (this.properties.hasOwnProperty(name)) {
      return this.properties[name];
    }
  }
};

OWA.report.tab = function (dom_id) {
  this.dom_id = dom_id;
  this.resultSetExplorers = {};
  this.label = 'Default label';
  this.isLoaded = false;

  this.load = function () {
    if (!this.isLoaded) {
      for (rse in this.resultSetExplorers) {
        if (this.resultSetExplorers.hasOwnProperty(rse)) {
          this.resultSetExplorers[rse].load();
        }
      }

      this.isLoaded = true;
    }
  };
};

OWA.report.tab.prototype = {
  startAutoRefresh: function startAutoRefresh() {
    for (rse in this.resultSetExplorers) {
      if (this.resultSetExplorers.hasOwnProperty(rse)) {
        this.resultSetExplorers[rse].enableAutoRefresh();
      }
    }
  },
  stopAutoRefresh: function stopAutoRefresh() {
    for (rse in this.resultSetExplorers) {
      if (this.resultSetExplorers.hasOwnProperty(rse)) {
        this.resultSetExplorers[rse].stopAutoRefresh();
      }
    }
  },
  addRse: function addRse(name, rse) {
    this.resultSetExplorers[name] = rse;
  },
  setLabel: function setLabel(label) {
    this.label = label;
  },
  setDomId: function setDomId(dom_id) {
    this.dom_id = dom_id;
  }
};

OWA.report.timePeriodControl = function (dom_id, options) {
  var options = options || {};
  this.dom_id = dom_id || 'owa_reportPeriodControl';
  this.startDate = '';
  this.endDate = '';
  this.label = '';

  if (options.hasOwnProperty('startDate')) {
    this.setStartDate(options.startDate);
  }

  if (options.hasOwnProperty('endDate')) {
    this.setEndDate(options.endDate);
  }

  if (options.hasOwnProperty('selectedPeriod')) {
    this.setSelectedPeriod(options.selectedPeriod);
  }

  this.label = OWA.util.sprintf('%s - %s', this.formatYyyymmdd(this.getStartDate(), '/'), this.formatYyyymmdd(this.getEndDate(), '/'));

  if (!OWA.isJsLoaded('jquery-ui')) {
    OWA.requireJs('jquery-ui', OWA.getOption('modules_url') + 'base/js/includes/jquery/jquery-ui-1.8.12.custom.min.js', OWA.requireJs('jqote', OWA.getOption('modules_url') + 'base/js/includes/jquery/jQote2/jquery.jqote2.min.js', this.setupDomElements()));
  } else {
    this.setupDomElements();
  }
};

OWA.report.timePeriodControl.prototype = {
  fixedPeriods: {
    today: 'Today',
    yesterday: 'Yesterday',
    this_week: 'This Week',
    this_month: 'This Month',
    this_year: 'This Year',
    last_week: 'Last Week',
    last_month: 'Last Month',
    last_year: 'Last Year',
    last_seven_days: 'Last Seven Days',
    last_thirty_days: 'Last Thirty Days',
    same_day_last_week: 'Same Day Last Week',
    same_week_last_year: 'Same Week Last Year',
    same_month_last_year: 'Same Month Last Year'
  },
  setSelectedPeriod: function setSelectedPeriod(period) {
    this.selectedPeriod = period;
  },
  formatYyyymmdd: function formatYyyymmdd(yyyymmdd, sep) {
    sep = sep || '-';
    var year = yyyymmdd.substr(2, 2);
    var month = yyyymmdd.substr(4, 2);
    var day = yyyymmdd.substr(6, 2);
    return month + sep + day + sep + year;
  },
  setStartDate: function setStartDate(yyyymmdd) {
    this.startDate = yyyymmdd;
  },
  setEndDate: function setEndDate(yyyymmdd) {
    this.endDate = yyyymmdd;
  },
  getStartDate: function getStartDate() {
    return this.startDate;
  },
  getEndDate: function getEndDate() {
    return this.endDate;
  },
  isValidDateString: function isValidDateString(str) {
    if (str.length != 10) {
      return false;
    }

    if (str.substr(2, 1) != '-' || str.substr(5, 1) != '-') {
      return false;
    }

    return true;
  },
  setupDomElements: function setupDomElements() {
    //closure
    var that = this; // set template data obj

    var data = {
      periods: this.fixedPeriods,
      datelabel: this.label,
      selectedPeriod: this.selectedPeriod
    }; // fetch template from server

    jQuery.get(OWA.getOption('modules_url') + 'base/templates/filter_period.php', function (tmpl) {
      // inject into dom
      jQuery(that.dom_id).jqoteapp(tmpl, data, '*'); // register show/hide controls event handler

      jQuery("#owa_reportPeriodLabelContainer").click(function () {
        jQuery('#owa_reportPeriodFiltersContainer').toggle();
      }); // bind handler to change start date picker when user enters date by hand.

      jQuery('#owa_report-datepicker-start-display').change(function () {
        var value = jQuery(this).val();

        if (that.isValidDateString(value)) {
          // set date picker
          jQuery("#owa_report-datepicker-start").datepicker("setDate", value); // simulate triggering the onSelect event by calling the
          // handler directly.

          var func = jQuery("#owa_report-datepicker-start").datepicker("option", "onSelect");
          func(value);
        } else {
          alert('Date must be in mm-dd-yyyy format.'); // wipe value

          jQuery('#owa_report-datepicker-start-display').val('');
        }
      }); // bind handler to change end date picker when user enters date by hand.

      jQuery('#owa_report-datepicker-end-display').change(function () {
        var value = jQuery(this).val();

        if (that.isValidDateString(value)) {
          // set date picker
          jQuery("#owa_report-datepicker-end").datepicker("setDate", value); // simulate triggering the onSelect event by calling the
          // handler directly.

          var func = jQuery("#owa_report-datepicker-end").datepicker("option", "onSelect");
          func(value);
        } else {
          alert('Date must be in mm-dd-yyyy format.'); // wipe value

          jQuery('#owa_report-datepicker-end-display').val('');
        }
      }); // create data picker objects

      jQuery("#owa_report-datepicker-start").datepicker({
        dateFormat: 'mm-dd-yy',
        altField: "#owa_report-datepicker-start-display",
        onSelect: function onSelect(selectedDate) {
          // parse date
          var instance = jQuery("#owa_report-datepicker-start").data("datepicker");
          var date = jQuery.datepicker.parseDate(instance.settings.dateFormat || jQuery.datepicker._defaults.dateFormat, selectedDate, instance.settings); // constrain min date

          jQuery("#owa_report-datepicker-end").datepicker("option", 'minDate', date); // constrain new max date using value from end date picker

          jQuery("#owa_report-datepicker-start").datepicker("option", 'maxDate', jQuery("#owa_report-datepicker-end").datepicker("getDate"));
        },
        defaultDate: that.formatYyyymmdd(that.getStartDate())
      });
      jQuery("#owa_report-datepicker-end").datepicker({
        dateFormat: 'mm-dd-yy',
        altField: "#owa_report-datepicker-end-display",
        onSelect: function onSelect(selectedDate) {
          // parse date
          var instance = jQuery("#owa_report-datepicker-end").data("datepicker");
          var date = jQuery.datepicker.parseDate(instance.settings.dateFormat || jQuery.datepicker._defaults.dateFormat, selectedDate, instance.settings); // constrain min date using value from start date picker

          jQuery("#owa_report-datepicker-end").datepicker("option", 'minDate', jQuery("#owa_report-datepicker-start").datepicker("getDate")); // constrain new max date 

          jQuery("#owa_report-datepicker-start").datepicker("option", 'maxDate', date);
        },
        defaultDate: that.formatYyyymmdd(that.getEndDate())
      }); // trigger owa_new_time_period_set event when 
      // submit button is pressed

      jQuery('#owa_reportPeriodFilterSubmit').click(function () {
        jQuery(that.dom_id).trigger('owa_new_time_period_set', [jQuery.datepicker.formatDate('yymmdd', jQuery("#owa_report-datepicker-start").datepicker("getDate")), jQuery.datepicker.formatDate('yymmdd', jQuery("#owa_report-datepicker-end").datepicker("getDate"))]);
      }); // trigger change event when new fixed time period is selected
      // TODO: refactor this to just set new dates in the date pickers

      jQuery('#owa_reportPeriodFilter').change(function () {
        var period = jQuery("#owa_reportPeriodFilter option:selected").val();
        jQuery(that.dom_id).trigger('owa_new_fixed_time_period_set', [period]);
      });
    });
  }
};

/***/ }),

/***/ "./modules/base/js-src/owa.resultSetExplorer.js":
/*!******************************************************!*\
  !*** ./modules/base/js-src/owa.resultSetExplorer.js ***!
  \******************************************************/
/*! no static exports found */
/***/ (function(module, exports) {

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2010 Peter Adams. All rights reserved.
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
 * Result Set Object
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @web            <a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.5.0
 */
OWA.resultSet = function (attributes) {
  for (attribute in attributes) {
    this[attribute] = attributes[attribute];
  }
};

OWA.resultSet.prototype = {
  getMetricLabel: function getMetricLabel(name) {
    //alert(this.resultSet.aggregates[name].label);
    if (this.aggregates[name].label.length > 0) {
      return this.aggregates[name].label;
    } else {
      return 'unknown';
    }
  },
  getMetricValue: function getMetricValue(name) {
    //alert(this.resultSet.aggregates[name].label);
    if (this.aggregates[name].value.length > 0) {
      return this.aggregates[name].value;
    } else {
      return 0;
    }
  },
  getSeries: function getSeries(value_name, value_name2, filter) {
    if (this.resultsRows.length > 0) {
      var series = []; //create data array

      for (var i = 0; i <= this.resultsRows.length - 1; i++) {
        if (filter) {
          check = filter(this.resultsRows[i]);

          if (!check) {
            continue;
          }
        }

        var item = '';

        if (value_name2) {
          item = [this.resultsRows[i][value_name].value, this.resultsRows[i][value_name2].value];
        } else {
          item = this.resultsRows[i][value_name].value;
        }

        series.push(item);
      }

      return series;
    }
  }
};
/**
 * Result Set Explorer Library
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @web            <a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.3.0
 */

OWA.resultSetExplorer = function (dom_id, options) {
  this.dom_id = dom_id || '';
  this.gridInit = false;
  this.init = {
    grid: false,
    pieChart: false,
    areaChart: false
  };
  this.columnLinks = '';
  this._columnLinksCount = 0;
  this.resultSet = [];
  this.currentView = '';
  this.currentContainerWidth = '';
  this.currentWindowWidth = '';
  this.view = '';
  this.asyncQueue = [];
  this.subscriber_dom_ids = [];
  this.autoRefreshInterval = 10000;
  this.autoRefresh = false;
  this.autoRefreshTimerId = '';
  this.domSelectors = {
    areaChart: '',
    grid: ''
  };
  this.options = {
    defaultView: 'grid',
    areaChart: {
      series: [],
      showDots: true,
      showLegend: true,
      lineWidth: 4
    },
    pieChart: {
      metric: '',
      dimension: '',
      metrics: [],
      numSlices: 5
    },
    sparkline: {
      metric: ''
    },
    grid: {
      showRowNumbers: true,
      excludeColumns: [],
      columnFormatters: {}
    },
    template: {
      template: '',
      params: '',
      mode: 'append',
      dom_id: ''
    },
    metricBoxes: {
      width: ''
    },
    chart: {
      showGrid: true
    },
    chartHeight: 125,
    chartWidth: 700,
    autoResizeCharts: true,
    views: ['grid', 'areaChart', 'pie', 'sparkline']
  };
  this.viewObjects = {};
  this.loadUrl = '';
  this.dataExportApiParams = {};
  this.isLoaded = false;
};

OWA.resultSetExplorer.prototype = {
  //remove
  viewMethods: {
    grid: 'refreshGrid',
    areaChart: 'makeAreaChart',
    pie: 'makePieChart',
    sparkline: 'makeSparkline',
    template: 'renderTemplate'
  },
  setDataLoadUrl: function setDataLoadUrl(url) {
    this.loadUrl = url;
  },
  changeSort: function changeSort(column, order) {
    var url = new OWA.uri(this.resultSet.self);
    var sortorder = '';

    if (order === 'desc') {
      sortorder = '-';
    } // set sort order


    url.setQueryParam('owa_sort', column + sortorder); // remove page param

    url.removeQueryParam('owa_page'); // fetch new results
    //alert( url.getSource() );

    this.getNewResultSet(url.getSource());
  },

  /**
   * Add/Changes a dimension
   * handler for secondary_dimension_change events
   */
  changeDimension: function changeDimension(oldname, newname) {
    // get current list of dimensions from url
    var url = new OWA.uri(this.resultSet.self);
    var dims = OWA.util.urldecode(url.getQueryParam('owa_dimensions'));
    var new_dims = [];

    if (dims) {
      dims = dims.split(',');

      if (OWA.util.in_array(oldname, dims)) {
        // loop through dims looking for the current sec. dim
        for (var i = 0; i < dims.length; i++) {
          // if you find it replace with new one
          if (dims[i] === oldname) {
            new_dim = newname;
          } else {
            new_dim = dims[i];
          }

          new_dims.push(new_dim);
        }
      } else {
        // just add to the existng dim set
        new_dims = dims;
        new_dims.push(newname);
      }

      new_dims = new_dims.join(',');
      url.setQueryParam('owa_dimensions', new_dims);
      this.getNewResultSet(url.getSource());
    }
  },
  changeConstraints: function changeConstraints(constraints) {
    var url = new OWA.uri(this.resultSet.self); // set constraints

    url.setQueryParam('owa_constraints', constraints); // fetch new results

    this.getNewResultSet(url.getSource());
  },
  getOption: function getOption(name) {
    return this.options[name];
  },
  getAggregates: function getAggregates() {
    return this.resultSet.aggregates;
  },
  // needed??
  setView: function setView(name) {
    this.view = name;
  },
  // called after data is rendered for a view
  // needed???
  setCurrentView: function setCurrentView(name) {
    jQuery(that.domSelectors[that.currentView]).toggle();
    this.currentView = name;
  },
  // makesa unqiue idfor each row
  // needed?
  makeRowGuid: function makeRowGuid(row) {},
  getRowValues: function getRowValues(old) {
    var row = {};

    for (var item in old) {
      if (old.hasOwnProperty(item)) {
        row[item] = old[item].value;
      }
    }

    return row;
  },
  loadFromArray: function loadFromArray(json, view) {
    if (view) {
      this.view = view;
    }

    this.loader(json);
  },
  load: function load(url) {
    this.showLoader();
    url = url || this.loadUrl;
    this.getResultSet(url);
  },

  /**
   * Creates a data grid from the result set
   *
   * @param    dom_id    string    the target dom ID for the grid
   * @param    options    obj        grid options
   */
  createGrid: function createGrid(dom_id, options) {
    // set defaults for backwards compatability
    dom_id = dom_id || this.dom_id;
    options = options || this.options.grid; // make new grid object

    var grid = new OWA.dataGrid(dom_id, options); // show grid

    grid.generate(this.resultSet); //register dom_id as a listener for data change events

    this.registerDataChangeSubscriber(dom_id); // closure

    var that = this; // subscribe to grid page events

    jQuery("#" + dom_id).bind('page_forward', function (event) {
      that.getNewResultSet(that.resultSet.next);
    });
    jQuery("#" + dom_id).bind('page_back', function (event) {
      that.getNewResultSet(that.resultSet.previous);
    }); // subscribe to grid secondary dimension change event

    jQuery("#" + dom_id).bind('secondary_dimension_change', function (event, oldname, newname) {
      that.changeDimension(oldname, newname);
    }); // subscribe to grid sort column change event

    jQuery("#" + dom_id).bind('sort_column_change', function (event, column, direction) {
      that.changeSort(column, direction);
    }); // subscribe to constraint_change event

    jQuery("#" + dom_id).bind('constraint_change', function (event, constraints) {
      that.changeConstraints(constraints);
    });
  },

  /**
   * Registers a dom_id to publish new result sets to
   */
  registerDataChangeSubscriber: function registerDataChangeSubscriber(dom_id) {
    this.subscriber_dom_ids.push(dom_id);
  },

  /**
   * Depricated
   */
  refreshGrid: function refreshGrid() {
    return this.createGrid();
  },
  loader: function loader(data) {
    if (data) {
      this.setResultSet(data);
      this.isLoaded = true;

      if (this.view) {
        var method_name = this.viewMethods[this.view];
        this[method_name]();
      }

      if (this.asyncQueue.length > 0) {
        for (var i = 0; i < this.asyncQueue.length; i++) {
          this.dynamicFunc(this.asyncQueue[i]);
        }
      }

      if (this.autoRefresh) {
        this.startAutoRefresh();
      }
    }
  },

  /**
   * Enables auto-refresh mode
   */
  enableAutoRefresh: function enableAutoRefresh(interval) {
    if (!this.isLoaded) {
      this.autoRefreshInterval = interval || this.autoRefreshInterval;
      this.autoRefresh = true;
    } else {
      this.startAutoRefresh(interval);
    }
  },

  /**
   * Starts auto refresh timer
   *
   * @param    interval    int    interval duration in milliseconds
   */
  startAutoRefresh: function startAutoRefresh(interval) {
    this.autoRefreshInterval = interval || this.autoRefreshInterval;

    if (this.isLoaded && !this.autoRefreshTimerId) {
      var that = this;
      this.autoRefreshTimerId = setInterval(function () {
        that.getNewResultSet();
      }, this.autoRefreshInterval);
    }
  },

  /**
   * Halts auto refresh of result set
   *
   */
  stopAutoRefresh: function stopAutoRefresh() {
    clearInterval(this.autoRefreshTimerId);
    this.autoRefreshTimerId = '';
  },
  dynamicFunc: function dynamicFunc(func) {
    //alert(func[0]);
    var args = Array.prototype.slice.call(func, 1); //alert(args);

    this[func[0]].apply(this, args);
  },
  showLoader: function showLoader() {
    jQuery('#' + this.dom_id).append('<div class="loader"><img class="loading" src="' + OWA.getSetting('baseUrl') + '/modules/base/i/loader.gif"></div>');
  },
  hideLoader: function hideLoader() {
    jQuery('#' + this.dom_id).find('.loader').remove();
  },
  // fetch the result set from the server
  getResultSet: function getResultSet(url) {
    var that = this;
    jQuery.getJSON(url, '', function (data) {
      that.hideLoader();
      that.loader(data);
    });
  },
  getNewResultSet: function getNewResultSet(url) {
    url = url || this.resultSet.self;
    var that = this;
    jQuery.getJSON(url, '', function (data) {
      that.setResultSet(data);
    });
  },
  setResultSet: function setResultSet(rs) {
    // check to see if resultSet is new
    if (OWA.util.is_object(rs) && OWA.util.is_object(this.resultSet)) {
      // if not new then return. nothing to do.
      if (rs.guid === this.resultSet.guid) {
        OWA.debug('result set has same GUID. no change needed.');
        return;
      } else {
        OWA.debug('result set has new GUID. change needed.');
      }
    } // this applies data to a special resultSet object that
    // has some helper methods.
    //check needed to handle new REST API response object which puts the resultSet in it's 'data' prop.


    if (rs.hasOwnProperty('data')) {
      this.resultSet = new OWA.resultSet(rs.data);
    } else {
      this.resultSet = new OWA.resultSet(rs);
    }

    this.applyLinks(); // notify listeners of new data

    var that = this;

    for (var i = 0; i < that.subscriber_dom_ids.length; i++) {
      OWA.debug('about to trigger data updates.');
      jQuery('#' + that.subscriber_dom_ids[i]).trigger('new_result_set', [that.resultSet]);
    }
  },

  /**
   * Adds a link template to a column
   * @public
   */
  addLinkToColumn: function addLinkToColumn(col_name, link_template, sub_params) {
    this.columnLinks = {};

    if (col_name) {
      var item = {};
      item.name = col_name;
      item.template = link_template;
      item.params = sub_params;
      this.columnLinks[col_name] = item;
      item = '';
    }

    this._columnLinksCount++;
  },

  /**
   * Applies links to result set dimensions where necessary
   * @private
   */
  applyLinks: function applyLinks() {
    var p = '';

    if (this.resultSet.resultsRows.length > 0) {
      if (this._columnLinksCount > 0) {
        for (var i = 0; i <= this.resultSet.resultsRows.length - 1; i++) {
          for (var y in this.columnLinks) {
            if (this.columnLinks.hasOwnProperty(y)) {
              //alert(this.dom_id + ' : '+y);
              var template = this.columnLinks[y].template;

              if (this.resultSet.resultsRows[i][y].name.length > 0) {
                //if (this.resultSet.resultsRows[i][this.columnLinks[y]].name.length > 0) {
                for (var z in this.columnLinks[y].params) {
                  if (this.columnLinks[y].params.hasOwnProperty(z)) {
                    template = template.replace('%s', OWA.util.urlEncode(this.resultSet.resultsRows[i][this.columnLinks[y].params[z]].value));
                  }
                }

                this.resultSet.resultsRows[i][this.columnLinks[y].name].link = template;
              }
            }
          }
        }
      }
    }
  },
  // move to resultSet obj?
  formatValue: function formatValue(type, value) {
    switch (type) {
      // convery yyyymmdd to javascript timestamp as  flot requires that
      case 'yyyymmdd':
        //date = jQuery.datepicker.parseDate('yymmdd', value);
        //value = Date.parse(date);
        var year = value.substring(0, 4) * 1;
        var month = value.substring(4, 6) * 1 - 1;
        var day = value.substring(6, 8) * 1;
        var d = Date.UTC(year, month, day, 0, 0, 0, 0);
        value = d;
        OWA.debug('year: %s, month: %s, day: %s, timestamp: %s', year, month, day, d);
        break;

      case 'currency':
        value = value / 100;
    }

    return value;
  },
  // move? check first to see if used by anyone other than area shart.
  timestampFormatter: function timestampFormatter(timestamp) {
    var d = new Date(timestamp * 1);
    var curr_date = d.getUTCDate();
    var curr_month = d.getUTCMonth() + 1;
    var curr_year = d.getUTCFullYear(); //alert(d+' date: '+curr_month);

    var date = curr_month + "/" + curr_date + "/" + curr_year; //var date =  curr_month + "/" + curr_date;

    return date;
  },

  /**
   * Main method for displaying an area chart
   */
  makeAreaChart: function makeAreaChart(series, dom_id) {
    // setup area chart options
    var options = {};
    var ac = new OWA.areaChart();
    dom_id = dom_id || this.dom_id; // set the target dom_id chart should appear in

    ac.setDomId(dom_id); // generate area chart

    ac.generate(this.resultSet, series, dom_id); //register dom_id as a listener for data change events

    this.registerDataChangeSubscriber(dom_id);
  },
  // shows a tool tip for flot charts
  showTooltip: function showTooltip(x, y, contents) {
    jQuery('<div id="tooltip">' + contents + '</div>').css({
      position: 'absolute',
      display: 'none',
      top: y + 5,
      left: x + 5,
      border: '1px solid #cccccc',
      padding: '2px',
      'background-color': '#ffffff',
      opacity: 0.90
    }).appendTo("body").fadeIn(100);
  },
  getMetricLabel: function getMetricLabel(name) {
    return this.resultSet.getMetricLabel(name);
  },
  getMetricValue: function getMetricValue(name) {
    return this.resultSet.getMetricValue(name);
  },
  makePieChart: function makePieChart(resultSet, dom_id, options) {
    var pc = new OWA.pieChart();

    if (!options) {
      options = this.options.pieChart;
    }

    ;

    if (!dom_id) {
      dom_id = this.dom_id;
    }

    if (!resultSet) {
      resultSet = this.resultSet;
    }

    pc.generate(resultSet, dom_id, options); //register dom_id as a listener for data change events

    this.registerDataChangeSubscriber(dom_id);
  },
  renderTemplate: function renderTemplate(template, params, mode, dom_id) {
    template = template || this.options.template.template;
    params = params || this.options.template.params;
    mode = mode || this.options.template.mode;
    dom_id = dom_id || this.options.template.dom_id || this.dom_id;
    jQuery.jqotetag('*'); //dom_id = dom_id || this.dom_id;

    if (mode === 'append') {
      jQuery('#' + dom_id).jqoteapp(template, params);
    } else if (mode === 'prepend') {
      jQuery('#' + dom_id).jqotepre(template, params);
    } else if (mode === 'replace') {
      jQuery('#' + dom_id).jqotesub(template, params);
    }
  },
  // moved to resultSet
  getSeries: function getSeries(value_name, value_name2, filter) {
    if (this.resultSet.resultsRows.length > 0) {
      var series = []; //create data array

      for (var i = 0; i <= this.resultSet.resultsRows.length - 1; i++) {
        if (filter) {
          check = filter(this.resultSet.resultsRows[i]);

          if (!check) {
            continue;
          }
        }

        var item = '';

        if (value_name2) {
          item = [this.resultSet.resultsRows[i][value_name].value, this.resultSet.resultsRows[i][value_name2].value];
        } else {
          item = this.resultSet.resultsRows[i][value_name].value;
        }

        series.push(item);
      }

      return series;
    }
  },
  makeMetricBoxes: function makeMetricBoxes(dom_id, template, label, metrics, filter) {
    var kpi = new OWA.kpiBox();

    if (!dom_id) {
      dom_id = this.dom_id;
    }

    var options = {};

    if (template) {
      options.template = template;
    }

    if (label) {
      options.label = label;
    }

    if (metrics) {
      options.metrics = metrics;
    }

    if (filter) {
      options.filter = filter;
    }

    if (this.options.metricBoxes.width) {
      options.width = this.options.metricBoxes.width;
    }

    kpi.generate(this.resultSet, dom_id, options); //register dom_id as a listener for data change events

    this.registerDataChangeSubscriber(dom_id);
  },
  makeSparkLine: function makeSparkLine(dom_id, options) {
    if (!dom_id) {
      dom_id = this.dom_id;
    }

    var sl = new OWA.sparkline();
    sl.generate(this.resultSet, dom_id, options); //register dom_id as a listener for data change events

    this.registerDataChangeSubscriber(dom_id);
  },
  renderResultsRows: function renderResultsRows(dom_id, template) {
    if (this.resultSet.resultsRows.length > 0) {
      var that = this;
      dom_id = dom_id || this.dom_id;
      var table = '';
      var data = []; //re-order the data into an array

      for (var d_item in this.resultSet.resultsRows[0]) {
        if (this.resultSet.resultsRows[0].hasOwnProperty(d_item)) {
          data.push(this.resultSet.resultsRows[0][d_item]);
        }
      } // set alt tag for jqote. needed to avoid problem with php's asp_tags ini directive


      jQuery.jqotetag('*'); //make table headers

      var ths = jQuery('#simpleTable-headers').jqote(data); // make outer table

      table = jQuery('#simpleTable-outer').jqote({
        dom_id: dom_id + '_simpleTable',
        headers: ths
      }); // add to dom

      jQuery('#' + dom_id).html(table); // append rows

      for (i = 0; i <= this.resultSet.resultsRows.length - 1; i++) {
        var cells = '';

        for (var r_item in this.resultSet.resultsRows[i]) {
          if (this.resultSet.resultsRows[i].hasOwnProperty(r_item)) {
            cells += jQuery('#table-column').jqote(this.resultSet.resultsRows[i][r_item]);
          }
        }

        var row = jQuery('#table-row').jqote({
          columns: cells
        });
        jQuery('#' + dom_id + '_simpleTable').append(row);
      }
    } else {
      jQuery('#' + dom_id).html("No results to display.");
    }
  },
  getApiEndpoint: function getApiEndpoint() {
    return this.getOption('api_endpoint') || OWA.getSetting('api_endpoint');
  },
  makeApiRequestUrl: function makeApiRequestUrl(method, options, url) {
    var url = url || this.getApiEndpoint();
    url += '?';
    url += 'owa_do=' + method;
    var count = OWA.util.countObjectProperties(options);
    var i = 1;

    for (option in options) {
      if (options.hasOwnProperty(option)) {
        if (typeof options[option] != 'undefined') {
          url += '&owa_' + option + '=' + OWA.util.urlEncode(options[option]);
        }

        i++;
      }
    }

    return url;
  }
};
/**
 * Dimension Picker UI control Class
 *
 * @param    target_dom_id    string    dom id where the control should be created.
 * @param     options            obj        config object
 */

OWA.dimensionPicker = function (target_dom_selector, options) {
  this.dim_list = {};
  this.alternate_field_selector = '';
  this.dom_id = target_dom_selector;
  this.exclusions = [];

  if (options && options.hasOwnProperty('exclusions')) {
    this.setExclusions(options.exclusions);
  }
};

OWA.dimensionPicker.prototype = {
  setDimensions: function setDimensions(dims) {
    this.dim_list = dims;
  },
  reset: function reset(dim_list) {
    if (dim_list) {
      this.setDimensions(dim_list);
    }

    this.generateDimList();
  },
  display: function display(selected) {
    var dom_id = this.dom_id;
    var container_selector = dom_id; // add container level dom elements

    var container_dom_elements = '<span class="dimensionPicker">';
    container_dom_elements += '</span>';
    jQuery(container_selector).html(container_dom_elements); // hide the dim list

    jQuery(container_selector + ' > .dimensionPicker > .dim-list').hide();
    this.generateDimList(container_selector + ' > .dimensionPicker', selected);
  },
  setDimensionlist: function setDimensionlist(dim_list) {
    this.dim_list = dim_list;
  },
  generateDimList: function generateDimList(selector, selected) {
    var container_selector = selector;
    var c = '<select data-placeholder="Select..." name="dim-list" class="dim-list" style="width:150px;"><option value=""></option>';
    var that = this;

    if (OWA.util.countObjectProperties(this.dim_list) > 0) {
      for (group in this.dim_list) {
        if (this.dim_list.hasOwnProperty(group)) {
          c += OWA.util.sprintf('<optgroup label="%s">', group);
          var num_dim_in_group = 0; // add list items

          for (var i = 0; i < this.dim_list[group].length; i++) {
            // check to see if the dim is on the exclusion list
            if (this.exclusions.length > 0 && OWA.util.in_array(this.dim_list[group][i].name, this.exclusions)) {
              // skip if so
              continue;
            } else {
              c += OWA.util.sprintf('<option value="%s">%s</option>', this.dim_list[group][i].name, this.dim_list[group][i].label);
              num_dim_in_group++;
            }
          } // if there are no dims in a group due to
          // exclusions there remoe the header


          if (num_dim_in_group < 1) {//jQuery( container_selector + ' > .dimensionPicker > .dim-list > h4:last' ).remove();
          }
        }
      }
    } else {
      c += OWA.l('There are no related dimensions.');
    } // append container and list to dom


    jQuery(container_selector).append(c); // transform into select menu

    jQuery(container_selector + ' > .dim-list').chosen({
      no_results_text: "Name not found."
    });
    jQuery(container_selector + ' > .dim-list').chosen().change(function () {
      //OWA.debug(JSON.stringify(obj));
      var value = jQuery(selector + ' > .dim-list').val();
      jQuery(that.dom_id).trigger('dimension_change', ['', value]);
    }); // set select value

    if (selected) {
      jQuery(selector + ' > .dim-list').val(selected).trigger('liszt:updated');
    } else {// hack for setting label of select menu
      //jQuery(container_selector + ' > .ui-selectmenu > .ui-selectmenu-status').html(OWA.l('Select...'));
    }
  },
  setAlternateField: function setAlternateField(selector) {
    this.alternate_field_selector = selector;
  },
  setExclusions: function setExclusions(ex_array) {
    this.exclusions = ex_array;
  }
};
/**
 * Data Grid UI control Class
 *
 * @param    target_dom_id    string    dom id where the control should be created.
 * @param     options            obj        config object
 *
 */

OWA.dataGrid = function (target_dom_id, options) {
  this.dom_id = target_dom_id;
  this.options = options;
  this.init = false;
  this.gridColumnOrder = [];
  this.columnLinks = '';
  this.constraintPicker = '';
  this.previousDimensionName = '';
};

OWA.dataGrid.prototype = {
  generate: function generate(resultSet) {
    OWA.debug('hi from generate');
    var that = this; // custom formattter functions.

    jQuery.extend(jQuery.fn.fmatter, {
      // urlFormatter allows for a single param substitution.
      urlFormatter: function urlFormatter(cellvalue, options, rowdata) {
        //alert(JSON.stringify(cellvalue));
        var sub_value = options.rowId; //alert(options.rowId);

        var name = options.colModel.realColName;
        OWA.debug(options.rowId - 1 + ' ' + name);

        if (rowdata[name].link.length > 0) {
          var new_url = rowdata[name].link;
          var link = '<a href="' + new_url + '">' + cellvalue.formatted_value + '</a>';
          return link;
        }
      },
      useServerFormatter: function useServerFormatter(cellvalue, options, rowdata) {
        var name = options.colModel.realColName;
        return rowdata[name].formatted_value; //return that.resultSet.resultsRows[options.rowId-1][name].formatted_value;
      }
    }); // load grid control
    // happens with first results set when loading from URL.

    if (this.init !== true) {
      this.display(resultSet);
    } else {
      this.refresh(resultSet);
    } // hide the built in jqgrid loading divs.


    jQuery("#load_" + that.dom_id + "_grid").hide();
    jQuery("#load_" + that.dom_id + "_grid").css("z-index", 101); // check to see if we need ot hide the previous page control.

    if (resultSet.page == 1) {
      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').show();
      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').hide();
    } else if (resultSet.page == resultSet.total_pages) {
      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').hide();
      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').show();
    } else {
      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').show();
    } //alert(resultSet.page + ' ' + resultSet.total_pages);

  },

  /**
   * creates the entire grid for the first time
   * @private
   */
  display: function display(resultSet) {
    if (resultSet.resultsReturned > 0) {
      // listen for changes to result set
      this.subscribeToDataUpdates();
      this.injectDomElements(resultSet);
      this.setGridOptions(resultSet);
      this.addAllRowsToGrid(resultSet);
      this.makeGridPagination(resultSet);
      this.init = true;
    } else {
      var dom_id = this.dom_id;
      jQuery("#" + dom_id).html("No data is available for this time period.");
    }
  },

  /**
   * refreshes the grid
   * @private
   */
  refresh: function refresh(resultSet) {
    var that = this; // unload current grid jut in case columns have changed

    jQuery("#" + that.dom_id + '_grid').jqGrid('GridUnload', "#gbox_" + that.dom_id + '_grid'); // setup grid columns/options again

    this.setGridOptions(resultSet);
    jQuery("#" + that.dom_id + ' _grid').jqGrid('clearGridData', true);
    this.addAllRowsToGrid(resultSet);
  },
  // listens for changes to parent resultSet object
  subscribeToDataUpdates: function subscribeToDataUpdates() {
    var that = this; // listen for data changes

    jQuery('#' + that.dom_id).bind('new_result_set', function (event, resultSet) {
      that.generate(resultSet);
    });
  },
  makeGridPagination: function makeGridPagination(resultSet) {
    if (resultSet.more) {
      var that = this;
      var p = '';
      p = p + '<LI class="owa_previousPageControl">';
      p = p + '<span>&laquo</span></LI>';
      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL').append(p); //style button

      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').button();
      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl > .ui-button-text').css('line-height', '0.5'); // bind click

      jQuery(".owa_previousPageControl").bind('click', function () {
        that.pageGrid('back');
      });
      var pn = '';
      pn = pn + '<LI class="owa_nextPageControl">';
      pn = pn + '<span>&raquo</span></LI>';
      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL').append(pn); // style button
      //style button

      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').button();
      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl > .ui-button-text').css('line-height', '0.5'); //bind click

      jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').bind('click', function () {
        that.pageGrid('forward');
      });

      if (resultSet.page == 1) {
        jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').hide();
      }
    }
  },
  pageGrid: function pageGrid(direction) {
    var that = this; // valid event names are 'page_forward' and 'page_back'

    jQuery('#' + that.dom_id).trigger('page_' + direction, []);
  },
  addAllRowsToGrid: function addAllRowsToGrid(resultSet) {
    var that = this; // uses the built in jqgrid loading divs. just giveit a message and show it.

    jQuery("#load_" + that.dom_id + "_grid").html('Loading...');
    jQuery("#load_" + that.dom_id + "_grid").show();
    jQuery("#load_" + that.dom_id + "_grid").css("z-index", 1000); // add data to grid

    jQuery("#" + that.dom_id + '_grid')[0].addJSONData(resultSet); // dispay new count

    this.displayRowCount(resultSet);
  },
  displayRowCount: function displayRowCount(resultSet) {
    if (resultSet.total_pages > 1) {
      var start = '';
      var end = '';

      if (resultSet.page === 1) {
        start = 1;
        end = resultSet.resultsReturned;
      } else {
        start = (resultSet.page - 1) * resultSet.resultsPerPage + 1;
        end = (resultSet.page - 1) * resultSet.resultsPerPage + resultSet.resultsReturned;
      }

      var that = this; //jQuery("#"+that.dom_id + '_grid').jqGrid('setGridParam', { rowNum: start } );

      var p = '<li class="owa_rowCount">';
      p += 'Results: ' + start + ' - ' + end;
      p = p + '</li>'; //alert ("#"+that.dom_id + '_grid' + ' > .owa_rowCount');

      var check = jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_rowCount').html(); //alert(check);

      if (check === null) {
        jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL').append(p);
      } else {
        jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_rowCount').html(p);
      }
    }
  },
  injectDomElements: function injectDomElements(resultSet) {
    var p = '';
    p += '<div class="owa_genericHorizontalList explorerTopControls"><ul></ul><div style="clear:both;"></div></div>';
    p += '<div style="clear:both;"></div>';
    p += '<table id="' + this.dom_id + '_grid"></table>';
    p += '<div class="owa_genericHorizontalList owa_resultsExplorerBottomControls"><ul></ul></div>';
    p += '<div style="clear:both;"></div>';
    var that = this;
    jQuery('#' + that.dom_id).append(p); // add top level controls
    // secondard dimension picker

    jQuery('#' + that.dom_id + ' > .explorerTopControls > ul').append(OWA.util.sprintf('<li class="controlItem"><span class="label">%s:</span> <span id="%s"></span></li>', OWA.l('Secondary Dimension'), this.dom_id + '_grid_secondDimensionChooser')); // create secondary dimension picker

    var sdc = new OWA.dimensionPicker('#' + this.dom_id + '_grid_secondDimensionChooser');
    sdc.setExclusions(this.getDimensions(resultSet)); //sdc.setExclusions( this.gridColumnOrder );

    sdc.setDimensions(resultSet.relatedDimensions);
    sdc.display(); // listen for the change to secondary dimension

    jQuery('#' + that.dom_id + '_grid_secondDimensionChooser').bind('dimension_change', function (event, oldname, newname) {
      // lookup current secondary dimension as displayed in the grid

      /*if ( that.gridColumnOrder.length >= 1 ) {
          oldname = that.gridColumnOrder[1];
      } else {
          oldname = '';
      }*/
      // propigate the event up one level where result set explorer is listening
      jQuery('#' + that.dom_id).trigger('secondary_dimension_change', [that.previousDimensionName, newname]);
      that.previousDimensionName = newname;
    }); // inject constraint builder
    // secondard dimension picker

    jQuery('#' + that.dom_id + ' > .explorerTopControls > ul').append('<li class="controlItem"><span class="label">Filter:</span> <span class="constraintPicker"></span></li>'); // constraint builder selector

    var cb_button_selector = '#' + this.dom_id + ' > .explorerTopControls > ul > .controlItem > .constraintPickerButton';
    var cb_cont_selector = '#' + this.dom_id + ' > .explorerTopControls > ul > .controlItem > .constraintPicker'; // turn into button

    jQuery(cb_button_selector).button(); // make object

    this.constraintPicker = new OWA.constraintBuilder(cb_cont_selector, {});
    this.constraintPicker.setRelatedDimensions(resultSet.relatedDimensions, []);
    this.constraintPicker.setRelatedMetrics(resultSet.relatedMetrics, []); // add current constraints to this method call

    var resultSet_url = new OWA.uri(resultSet.self);
    var cur_con = resultSet_url.getQueryParam('owa_constraints');
    this.constraintPicker.display(cur_con); // listen for the constraint change event

    jQuery(cb_cont_selector).bind('constraint_change', function (event, constraints) {
      // propigate the event up one level where result set explorer might be listening
      jQuery('#' + that.dom_id).trigger('constraint_change', [constraints]);
    });
  },
  setGridOptions: function setGridOptions(resultSet) {
    var that = this;
    var columns = [];
    var columnDef = ''; // reset grid column order

    this.gridColumnOrder = [];

    for (var column in resultSet.resultsRows[0]) {
      // check to see if we should exclude any columns
      if (this.options.excludeColumns.length > 0) {
        for (var i = 0; i <= this.options.excludeColumns.length - 1; i++) {
          // if column name is not on the exclude list then add it.
          if (this.options.excludeColumns[i] != column) {
            // add column
            columnDef = this.makeGridColumnDef(resultSet.resultsRows[0][column]);
            columns.push(columnDef); // set grid column order

            this.gridColumnOrder.push(resultSet.resultsRows[0][column].name);
          }
        }
      } else {
        // add column
        columnDef = this.makeGridColumnDef(resultSet.resultsRows[0][column]);
        columns.push(columnDef); // set grid column order

        this.gridColumnOrder.push(resultSet.resultsRows[0][column].name);
      }
    }

    jQuery('#' + that.dom_id + '_grid').jqGrid({
      jsonReader: {
        repeatitems: false,
        root: "resultsRows",
        cell: '',
        id: '',
        page: 'page',
        total: 'total_pages',
        records: 'resultsReturned'
      },
      afterInsertRow: function afterInsertRow(rowid, rowdata, rowelem) {
        return;
      },
      datatype: 'local',
      colModel: columns,
      rownumbers: that.options.showRowNumbers,
      viewrecords: true,
      rowNum: resultSet.resultsReturned,
      height: '100%',
      autowidth: true,
      hoverrows: false,
      sortname: resultSet.sortColumn,
      sortorder: resultSet.sortOrder,
      onSortCol: function onSortCol(index, iCol, sortorder) {
        //that.sortGrid( index, sortorder );
        jQuery('#' + that.dom_id).trigger('sort_column_change', [index, sortorder]);
        return 'stop';
      }
    }); // set header css

    for (var y = 0; y < columns.length; y++) {
      var css = {}; //if dimension column then left align

      if (columns[y].classes == 'owa_dimensionGridCell') {
        css['text-align'] = 'left';
      } else {
        css['text-align'] = 'right';
      } // if sort column then bold.


      if (resultSet.sortColumn + '' === columns[y].name) {//css.fontWeight = 'bold';
      } // set the css. no way to just set a class...


      jQuery('#' + that.dom_id + '_grid').jqGrid('setLabel', columns[y].name, '', css);
    }
  },
  // private
  makeGridColumnDef: function makeGridColumnDef(column) {
    var _sort_type = '';
    var _align = '';
    var _format = '';
    var _class = '';
    var _width = '';
    var _resizable = true;
    var _fixed = false;
    var _datefmt = '';
    var _link_template = '';

    if (column.result_type === 'dimension') {
      _align = 'left';
      _class = 'owa_dimensionGridCell';
    } else {
      _align = 'right';
      _class = 'owa_metricGridCell';
      _width = 100;
      _resizable = false;
      _fixed = true;
    }

    if (column.data_type === 'string') {
      _sort_type = 'text';
    } else {
      _sort_type = 'number';
    }

    if (column.link) {
      _format = 'urlFormatter';
    } else {
      _format = 'useServerFormatter';
    } // set custom formatter if one exists.


    if (this.options.columnFormatters.hasOwnProperty(column.name)) {
      _format = this.options.columnFormatters[column.name];
    }

    if (this.columnLinks.hasOwnProperty(column.name)) {
      _link_template = this.columnLinks[column.name].template;
    }

    var columnDef = {
      //name: column.name +'.value',
      name: column.name,
      //index: column.name +'.value',
      index: column.name + '',
      label: column.label,
      sorttype: _sort_type,
      align: _align,
      formatter: _format,
      classes: _class,
      width: _width,
      resizable: _resizable,
      fixed: _fixed,
      realColName: column.name,
      datefmt: _datefmt,
      link_template: _link_template
    };
    return columnDef;
  },
  getDimensions: function getDimensions(resultSet) {
    var dims = '';
    var self = new OWA.uri(resultSet.self);
    dims = OWA.util.urldecode(self.getQueryParam('owa_dimensions'));
    dims = dims.split(',');
    return dims;
  }
};

OWA.constraintBuilder = function (target_dom_selector, options) {
  this.dom_selector = target_dom_selector;
  this.options = {};
  this.constraints = {};
  this.relatedDimensions = {};
  this.relatedMetrics = {};
};

OWA.constraintBuilder.prototype = {
  operators: {
    '==': 'Exactly Matching',
    '!=': 'Not Matching',
    '>': 'Greater than',
    '<': 'Less than',
    '=@': 'Contains'
  },
  parseConstraintString: function parseConstraintString(str) {
    con_obj = {
      name: '',
      value: '',
      operator: ''
    };
    return con_obj;
  },
  constraintsStringToArray: function constraintsStringToArray(str) {
    var a = [];
    var c_array = [];

    if (str) {
      if (OWA.util.strpos(str, ',')) {
        a = str.split(',');
      } else {
        a.push(str);
      }

      for (var i = 0; i < a.length; i++) {
        for (operator in this.operators) {
          if (this.operators.hasOwnProperty(operator)) {
            if (OWA.util.strpos(a[i], operator)) {
              var b = a[i].split(operator);
              var c = {
                'name': b[0],
                'operator': operator,
                'value': b[1]
              };
              c_array.push(c);
            }
          }
        }
      }
    }

    return c_array;
  },
  display: function display(constraints_str) {
    var c_array = this.constraintsStringToArray(constraints_str);
    this.createConstraintAssembler(c_array);
  },
  createConstraintAssembler: function createConstraintAssembler(constraints) {
    var that = this; // outer container

    jQuery(that.dom_selector).append('<div class="constraintPickerContainer"></div>');
    var container_selector = that.dom_selector + ' > .constraintPickerContainer';
    jQuery(container_selector).append('<span class="toggle-button"></span><div class="builder"><ul></ul><div style="clear:both;"></div><div class="add-button"></div><div class="apply-button"></div>');
    var button_selector = container_selector + ' > .toggle-button';
    var builder_selector = container_selector + ' > .builder';
    jQuery(builder_selector).hide(); // if there are existing constraints

    if (constraints.length > 0) {
      for (var i = 0; i < constraints.length; i++) {
        this.addNewConstraintRow(builder_selector + ' > ul', constraints[i].name, constraints[i].operator, constraints[i].value);
      }
    } else {
      // just add an empty row
      this.addNewConstraintRow(builder_selector + ' > ul');
    } // setup the toggle button


    jQuery(button_selector).button({
      icons: {
        primary: 'ui-icon-blank',
        secondary: 'ui-icon-triangle-1-s'
      },
      label: OWA.l('Select...')
    }).click(function () {
      jQuery(builder_selector).toggle();
    }); // setup add button

    jQuery(builder_selector + ' > .add-button').button({
      label: OWA.l('+ Add Filter ')
    }).click(function () {
      that.addNewConstraintRow(builder_selector + ' > ul');
    }); // setup apply button

    jQuery(builder_selector + ' > .apply-button').button({
      label: OWA.l('Apply')
    }).click(function () {
      var constraints = ''; // iterate through constraint rows

      jQuery(builder_selector + ' > ul > li').each(function (index) {
        var name = jQuery(this).children('.constraintDimensionPicker').children('.dimensionPicker').children('.dim-list').val();
        var operator = jQuery(this).children('.constraintOperatorPicker').children('.operator-list').selectmenu('value');
        var value = jQuery(this).children('.constraintValueField').val();

        if (value) {
          //constraints += OWA.util.sprintf('%s%s%s,' name, operator, value);
          constraints += name + operator + value;

          if (index < jQuery(builder_selector + ' > ul > li').length - 1) {
            //if (index < jQuery(this).siblings().length - 1 ) {
            constraints += ',';
          }
        }
      });
      var el = jQuery(that.dom_selector).trigger('constraint_change', [constraints]);
    });
  },
  setRelatedDimensions: function setRelatedDimensions(dims, exclusions) {
    if (exclusions) {// filter the dim list
    }

    this.relatedDimensions = dims;
  },
  setRelatedMetrics: function setRelatedMetrics(metrics, exclusions) {
    if (exclusions) {// filter the dim list
    }

    this.relatedMetrics = metrics;
  },
  combineRelatedMetricsWithDimensions: function combineRelatedMetricsWithDimensions() {
    var metrics = false;
    var dimensions = false;

    if (OWA.util.countObjectProperties(this.relatedDimensions) > 0) {
      dimensions = true;
    }

    if (OWA.util.countObjectProperties(this.relatedMetrics) > 0) {
      metrics = true;
    }

    if (metrics && dimensions) {
      var n = this.relatedDimensions;

      for (metric in this.relatedMetrics) {
        if (this.relatedMetrics.hasOwnProperty(metric)) {
          n[metric] = this.relatedMetrics[metric];
        }
      }

      return n;
    } else if (metrics) {
      return this.relatedMetrics;
    } else if (dimensions) {
      return this.relatedDimensions;
    }
  },
  addNewConstraintRow: function addNewConstraintRow(selector, name, operator, value) {
    // generate container
    // generate the dim/metric chooser button
    jQuery(selector).append('<LI class="constraintRow"><span class="constraintDimensionPicker"></span> <span class="constraintOperatorPicker"></span><input class="constraintValueField" type="text" size="30"><span class="constraintRemoveButton">X</span></LI>'); // create constraint dimension picker

    var dimpicker_selector = selector + ' > li:last > .constraintDimensionPicker';
    var cdp = new OWA.dimensionPicker(dimpicker_selector);
    cdp.setDimensions(this.combineRelatedMetricsWithDimensions());
    cdp.display(name); // generate operatior picker

    this.makeOperatorPicker(selector + ' > li:last > .constraintOperatorPicker', operator);

    if (value) {
      jQuery(selector + ' > li:last > .constraintValueField').val(value);
    } // setup add button


    jQuery(selector + '> li:last > .constraintRemoveButton').button({
      label: OWA.l('X')
    }).click(function () {
      jQuery(this).parent().remove();
    });
  },
  makeOperatorPicker: function makeOperatorPicker(selector, selected) {
    // append the container
    var c = ''; //c += '<label for="operator-list">Select Operator:</label>';

    c += '<select name="operator-list" class="operator-list">'; // build the list of operators

    for (operator in this.operators) {
      if (this.operators.hasOwnProperty(operator)) {
        c += OWA.util.sprintf('<option value="%s">%s</option>', operator, this.operators[operator]);
      }
    }

    c += '</select>';
    c += '';
    jQuery(selector).append(c);
    jQuery(selector + ' > .operator-list').selectmenu({
      width: 200
    }); // set select value

    if (selected) {
      jQuery(selector + ' > .operator-list').selectmenu("value", selected);
    }
  }
};

/***/ }),

/***/ "./modules/base/js-src/owa.sparkline.js":
/*!**********************************************!*\
  !*** ./modules/base/js-src/owa.sparkline.js ***!
  \**********************************************/
/*! no static exports found */
/***/ (function(module, exports) {

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2010 Peter Adams. All rights reserved.
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
 * OWA Sparkline Implementation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @web            <a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.3.0
 */
OWA.sparkline = function (dom_id) {
  this.config = OWA.config || '';
  this.dom_id = dom_id || '';
  this.data = '';
  this.options = {
    type: 'line',
    lineWidth: 2,
    width: '100px',
    height: '20px',
    spotRadius: 0,
    //lineColor: '',
    //spotColor: '',
    minSpotColor: '#FF0000',
    maxSpotColor: '#00FF00'
  };
};

OWA.sparkline.prototype = {
  mergeOptions: function mergeOptions(options) {
    for (option in options) {
      if (options.hasOwnProperty(option)) {
        this.options[option] = options[option];
      }
    }
  },
  setDomId: function setDomId(dom_id) {
    this.dom_id = dom_id;
    this.domSelector = this.dom_id + ' > .sparkline'; // listen for data change events

    var that = this;
    jQuery('#' + that.dom_id).bind('new_result_set', function (event, resultSet) {
      jQuery(that.domSelector).remove();
      that.generate(resultSet);
    });
  },
  getOption: function getOption(name) {
    if (this.options.hasOwnProperty(name)) {
      return this.options[name];
    }
  },
  setOption: function setOption(name, value) {
    this.options[name] = value;
  },
  generate: function generate(resultSet, dom_id, options) {
    if (dom_id) {
      this.setDomId(dom_id);
    }

    dom_id = this.dom_id;

    if (options) {
      this.mergeOptions(options);
    }

    var data = resultSet.getSeries(this.options.metric, '', this.options.filter);

    if (!data) {
      data = [0, 0, 0];
    }

    var selector = this.domSelector;
    jQuery('#' + dom_id).append('<p class="sparkline"></p>');
    this.loadFromArray(data);
  },
  render: function render() {
    jQuery('#' + this.dom_id).sparkline('html', this.options);
  },
  loadFromArray: function loadFromArray(data) {
    var selector = '#' + this.domSelector;
    jQuery(selector).sparkline(data, this.options);
  },
  setHeight: function setHeight(height) {
    this.options.height = height;
  },
  setWidth: function setWidth(width) {
    this.options.width = width;
  }
};

/***/ }),

/***/ 1:
/*!*************************************************************************************************************************************************************************************************************************************************************************************!*\
  !*** multi ./modules/base/js-src/owa.js ./modules/base/js-src/owa.report.js ./modules/base/js-src/owa.resultSetExplorer.js ./modules/base/js-src/owa.sparkline.js ./modules/base/js-src/owa.areachart.js ./modules/base/js-src/owa.piechart.js ./modules/base/js-src/owa.kpibox.js ***!
  \*************************************************************************************************************************************************************************************************************************************************************************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {

__webpack_require__(/*! /var/www/stats.artfulrobot.uk/public/modules/base/js-src/owa.js */"./modules/base/js-src/owa.js");
__webpack_require__(/*! /var/www/stats.artfulrobot.uk/public/modules/base/js-src/owa.report.js */"./modules/base/js-src/owa.report.js");
__webpack_require__(/*! /var/www/stats.artfulrobot.uk/public/modules/base/js-src/owa.resultSetExplorer.js */"./modules/base/js-src/owa.resultSetExplorer.js");
__webpack_require__(/*! /var/www/stats.artfulrobot.uk/public/modules/base/js-src/owa.sparkline.js */"./modules/base/js-src/owa.sparkline.js");
__webpack_require__(/*! /var/www/stats.artfulrobot.uk/public/modules/base/js-src/owa.areachart.js */"./modules/base/js-src/owa.areachart.js");
__webpack_require__(/*! /var/www/stats.artfulrobot.uk/public/modules/base/js-src/owa.piechart.js */"./modules/base/js-src/owa.piechart.js");
module.exports = __webpack_require__(/*! /var/www/stats.artfulrobot.uk/public/modules/base/js-src/owa.kpibox.js */"./modules/base/js-src/owa.kpibox.js");


/***/ })

/******/ });