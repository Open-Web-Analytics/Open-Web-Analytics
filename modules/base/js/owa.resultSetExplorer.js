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
 * @web			<a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.5.0
 */

OWA.resultSet = function( attributes ) {

	for (attribute in attributes) {
		
		this[attribute] = attributes[attribute];
	}
};

OWA.resultSet.prototype = {
	
	getMetricLabel : function(name) {
		//alert(this.resultSet.aggregates[name].label);
		if (this.aggregates[name].label.length > 0) {
			return this.aggregates[name].label;
		} else {
			return 'unknown';
		}
	},
	
	getMetricValue : function(name) {
		//alert(this.resultSet.aggregates[name].label);
		if (this.aggregates[name].value.length > 0) {
			return this.aggregates[name].value;
		} else {
			return 0;
		}
	},
	
	getSeries : function(value_name, value_name2, filter) {
		
		if (this.resultsRows.length > 0) {
			
			var series = [];
			//create data array
			for(var i=0;i<=this.resultsRows.length -1;i++) {
			
				if (filter) {
					check = filter(this.resultsRows[i]);
					if (!check) {
						continue;
					}
				}
				
				var item = '';
				if (value_name2) {
					item =[this.resultsRows[i][value_name].value, this.resultsRows[i][value_name2].value];
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
 * @web			<a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */
 
OWA.resultSetExplorer = function(dom_id, options) {
	
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
			series:[],
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
		chart: {showGrid: true},
		chartHeight: 125, 
		chartWidth:700,
		autoResizeCharts: true,
		views:['grid', 'areaChart','pie', 'sparkline']
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
	
	setDataLoadUrl : function(url) {
		
		this.loadUrl = url;
	},
	
	changeSort : function(column, order) {
		
		var url = new OWA.uri( this.resultSet.self );
		var sortorder = '';
		if (order === 'desc') {
			sortorder = '-';
		}
		
		// set sort order
		url.setQueryParam('owa_sort', column + sortorder);
		// remove page param
		url.removeQueryParam('owa_page');
		// fetch new results
		//alert( url.getSource() );
		this.getNewResultSet( url.getSource() );
		
	},
	
	/**
	 * Add/Changes a dimension
	 * handler for secondary_dimension_change events
	 */
	changeDimension : function(oldname, newname) {
	
		// get current list of dimensions from url
		var url = new OWA.uri( this.resultSet.self );
		var dims = OWA.util.urldecode(url.getQueryParam('owa_dimensions'));
		
		var new_dims = [];
				
		if (dims) {
			
			dims = dims.split(',');			
			
			if ( OWA.util.in_array(oldname, dims) ) {
			
				// loop through dims looking for the current sec. dim	
				for (var i=0; i < dims.length; i++) {
					// if you find it replace with new one
					if ( dims[i] === oldname ) {
						new_dim = newname;
					} else {
						new_dim = dims[i]
					}
					
					new_dims.push(new_dim);		
				}
			} else {
				// just add to the existng dim set
				new_dims = dims;
				new_dims.push( newname );
			}	
			
			new_dims = new_dims.join(',');
			
			url.setQueryParam('owa_dimensions', new_dims);
			this.getNewResultSet( url.getSource() );
		}
	},
	
	changeConstraints : function (constraints) {
	
		var url = new OWA.uri( this.resultSet.self );
		
		// set constraints
		url.setQueryParam('owa_constraints', constraints);
	
		// fetch new results
		this.getNewResultSet( url.getSource() );
		
	},
	
	getOption : function(name) {
		
		return this.options[name];
	},
	
	getAggregates : function() {
		
		return this.resultSet.aggregates;
	},
	
	// needed??
	setView : function(name) {
		
		this.view = name;
	},
	
	// called after data is rendered for a view
	// needed???
	setCurrentView : function(name) {
		
		jQuery(that.domSelectors[that.currentView]).toggle();
		this.currentView = name;
	},
	
	// makesa unqiue idfor each row
	// needed?
	makeRowGuid : function(row) {
		
	},
	
	getRowValues : function(old) {
		
		var row = {};
		
		for (var item in old) {
			
			if (old.hasOwnProperty(item)) {
				row[item] = old[item].value;
			}
		}
	
		return row;
	},
	
	loadFromArray : function(json, view) {
	
		if (view) {
			this.view = view;
		}
		
		this.loader(json);
		
	},
	
	load : function(url) {
		this.showLoader();
		url = url || this.loadUrl;
		this.getResultSet(url);
	},
	
	/**
	 * Creates a data grid from the result set
	 *
	 * @param	dom_id	string	the target dom ID for the grid
	 * @param	options	obj		grid options
	 */	
	createGrid : function (dom_id, options) {
		
		// set defaults for backwards compatability
		dom_id = dom_id || this.dom_id;
		options = options || this.options.grid;
		
		// make new grid object
		var grid = new OWA.dataGrid( dom_id, options );
		
		// show grid
		grid.generate(this.resultSet);
		
		//register dom_id as a listener for data change events
		this.registerDataChangeSubscriber( dom_id );
		
		// closure
		var that = this;
		
		// subscribe to grid page events
		jQuery( "#" + dom_id ).bind( 'page_forward', function(event) {
			that.getNewResultSet(that.resultSet.next);
		});
		
		jQuery( "#" + dom_id ).bind( 'page_back', function(event) {
			that.getNewResultSet(that.resultSet.previous);
		});
		
		// subscribe to grid secondary dimension change event
		jQuery( "#" + dom_id ).bind( 'secondary_dimension_change', function(event, oldname, newname) {
			that.changeDimension(oldname, newname);
		});
		
		// subscribe to grid sort column change event
		jQuery( "#" + dom_id ).bind( 'sort_column_change', function(event, column, direction) {
			that.changeSort(column, direction);
		});
		
		// subscribe to constraint_change event
		jQuery( "#" + dom_id ).bind( 'constraint_change', function(event, constraints) {
			that.changeConstraints(constraints);
		});

	},
	
	/**
	 * Registers a dom_id to publish new result sets to
	 */
	registerDataChangeSubscriber : function( dom_id ) {
		
		this.subscriber_dom_ids.push( dom_id );
	},
		
	/**
	 * Depricated
	 */
	refreshGrid : function() {
		
		return this.createGrid();
	},
	
	loader : function(data) {
		
		if (data) {
			
			this.setResultSet(data);
			this.isLoaded = true;
				
			if (this.view) {
				var method_name = this.viewMethods[this.view];
				this[method_name]();	
			}
			
			if (this.asyncQueue.length > 0) {
				
				for(var i=0;i< this.asyncQueue.length;i++) {
					
					this.dynamicFunc(this.asyncQueue[i]);
				}
			}
			
			if ( this.autoRefresh ) {
				
				this.startAutoRefresh();
			}	
		}	
	},
	
	/**
	 * Enables auto-refresh mode
	 */
	enableAutoRefresh : function( interval ) {
		
		if ( ! this.isLoaded ) {
		
			this.autoRefreshInterval = interval || this.autoRefreshInterval;
			this.autoRefresh = true;
		} else {
		
			this.startAutoRefresh( interval );
		}
	},
		
	/**
	 * Starts auto refresh timer
	 *
	 * @param	interval	int	interval duration in milliseconds
	 */
	startAutoRefresh : function(interval) {
		
		this.autoRefreshInterval = interval || this.autoRefreshInterval;
		
		if ( this.isLoaded && ! this.autoRefreshTimerId ) {
		
			var that = this;
			this.autoRefreshTimerId = setInterval(function() {
					that.getNewResultSet();
				}, 
				this.autoRefreshInterval
			);
		}
	},
	
	/**
	 * Halts auto refresh of result set
	 *
	 */
	stopAutoRefresh : function() {
		
		clearInterval(this.autoRefreshTimerId);
		this.autoRefreshTimerId = '';
	},
	
	dynamicFunc : function (func){
		//alert(func[0]);
		var args = Array.prototype.slice.call(func, 1);
		//alert(args);
		this[func[0]].apply(this, args);
	},
	
	showLoader: function() {
		jQuery('#'+this.dom_id).append('<div class="loader"><img class="loading" src="'+OWA.getSetting('baseUrl')+'/modules/base/i/loader.gif"></div>');
	},
	
	hideLoader: function() {
		jQuery('#'+this.dom_id).find('.loader').remove();
	},
	
	// fetch the result set from the server
	getResultSet : function(url) {
		
		var that = this;
		jQuery.getJSON(url, '', function (data) {that.hideLoader(); that.loader(data); });
	},
	
	getNewResultSet : function( url ) {
		
		url = url || this.resultSet.self;
		
		var that = this;
		jQuery.getJSON(url, '', function (data) {that.setResultSet(data);});
	},
	
	setResultSet : function(rs) {
		
		// check to see if resultSet is new 
		if ( OWA.util.is_object(rs) && OWA.util.is_object( this.resultSet ) ) {
			// if not new then return. nothing to do.
			if (rs.guid === this.resultSet.guid) {
				OWA.debug('result set has same GUID. no change needed.');
				return;
			} else {
				OWA.debug('result set has new GUID. change needed.');
			}
			
		}
		
		// this applies data to a special resultSet object that
		// has some helper methods.
		this.resultSet = new OWA.resultSet(rs);
		
		this.applyLinks();
		
		// notify listeners of new data
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
	addLinkToColumn : function(col_name, link_template, sub_params) {
		
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
	applyLinks : function() {
		
		var p = '';
		
		if (this.resultSet.resultsRows.length > 0) {
			
			if (this._columnLinksCount > 0) {
			
				for(var i=0;i<=this.resultSet.resultsRows.length - 1;i++) {
					
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
	formatValue : function(type, value) {
		
		switch(type) {
			// convery yyyymmdd to javascript timestamp as  flot requires that
			case 'yyyymmdd':
				
				//date = jQuery.datepicker.parseDate('yymmdd', value);
				//value = Date.parse(date);
				var year = value.substring(0,4) * 1;
				var month = (value.substring(4,6) * 1) -1;
				var day = value.substring(6,8) * 1; 
				var d = Date.UTC(year,month,day,0,0,0,0);
				value = d;
				OWA.debug('year: %s, month: %s, day: %s, timestamp: %s',year,month,day,d);
				break;
				
			case 'currency':
				value = value/100;
		}
		
		return value;
	},
	
	// move? check first to see if used by anyone other than area shart.
	timestampFormatter : function(timestamp) {
		
		var d = new Date(timestamp*1);
		var curr_date = d.getUTCDate();
		var curr_month = d.getUTCMonth() + 1;
		var curr_year = d.getUTCFullYear();
		//alert(d+' date: '+curr_month);
		var date =  curr_month + "/" + curr_date + "/" + curr_year;
		//var date =  curr_month + "/" + curr_date;
		return date;
	},
	
	
	/**
	 * Main method for displaying an area chart
	 */
	makeAreaChart : function(series, dom_id) {
		
		// setup area chart options
		var options = {
		
		};
		
		var ac = new OWA.areaChart();
		dom_id = dom_id || this.dom_id;
		// set the target dom_id chart should appear in
		ac.setDomId( dom_id );
		// generate area chart
		ac.generate(this.resultSet, series, dom_id);
		
		//register dom_id as a listener for data change events
		this.registerDataChangeSubscriber( dom_id );					
	},
	
	// shows a tool tip for flot charts
	showTooltip : function(x, y, contents) {
	
        jQuery('<div id="tooltip">' + contents + '</div>').css( {
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
    
    getMetricLabel : function(name) {
		
		return this.resultSet.getMetricLabel( name );
	},
	
    getMetricValue : function(name) {
		
		return this.resultSet.getMetricValue( name );
	},
	    
    makePieChart : function (resultSet, dom_id, options) {
     
     	var pc = new OWA.pieChart();
     	
     	if ( ! options ) {
     		options = this.options.pieChart;
     	};
     	
     	if ( ! dom_id ) {
     		
     		dom_id = this.dom_id;
     	}
     	
     	if ( ! resultSet ) {
     		
     		resultSet = this.resultSet;
     	}
     	
     	pc.generate(resultSet, dom_id, options);
     	
     	//register dom_id as a listener for data change events
		this.registerDataChangeSubscriber( dom_id );		
    },
    
	renderTemplate : function(template, params, mode, dom_id) {
		
		template = template || this.options.template.template;
		params = params || this.options.template.params;
		mode = mode || this.options.template.mode;
		dom_id = dom_id || this.options.template.dom_id || this.dom_id;
		jQuery.jqotetag('*');
		//dom_id = dom_id || this.dom_id; 
		
		if (mode === 'append') {
			jQuery('#' + dom_id).jqoteapp(template, params);
		} else if (mode === 'prepend') {
			jQuery('#' + dom_id).jqotepre(template, params);
		} else if (mode === 'replace') {
			jQuery('#' + dom_id).jqotesub(template, params);
		}
	},
	
	// moved to resultSet
	getSeries : function(value_name, value_name2, filter) {
		
		if (this.resultSet.resultsRows.length > 0) {
			
			var series = [];
			//create data array
			for(var i=0;i<=this.resultSet.resultsRows.length -1;i++) {
			
				if (filter) {
					check = filter(this.resultSet.resultsRows[i]);
					if (!check) {
						continue;
					}
				}
				
				var item = '';
				if (value_name2) {
					item =[this.resultSet.resultsRows[i][value_name].value, this.resultSet.resultsRows[i][value_name2].value];
				} else {
					item = this.resultSet.resultsRows[i][value_name].value;
					
				}
					
				series.push(item);
			}
			
			return series;
		}
    },
    
	makeMetricBoxes : function(dom_id, template, label, metrics, filter) {
		
		var kpi = new OWA.kpiBox();
		
		if ( ! dom_id ) {
			dom_id = this.dom_id;
		}
		
		var options = {};
		
		if (template) {
			options.template = template;
		}
		
		if ( label ) {
			options.label = label;
		}
		
		if ( metrics ) {
		
			options.metrics = metrics;
		}
		
		if ( filter ) {
			options.filter = filter;
		}
		
		if ( this.options.metricBoxes.width ) {
			options.width = this.options.metricBoxes.width;
		}
		
		kpi.generate(this.resultSet, dom_id, options);
		
		//register dom_id as a listener for data change events
		this.registerDataChangeSubscriber( dom_id );
		
    },
    
    makeSparkLine : function(dom_id, options) {
    	
    	if ( ! dom_id ) {
    		dom_id = this.dom_id;
    	}
    	
    	var sl = new OWA.sparkline();
    	sl.generate( this.resultSet, dom_id, options);
    	//register dom_id as a listener for data change events
		this.registerDataChangeSubscriber( dom_id );
    },
    
	renderResultsRows : function(dom_id, template) {
	
		if (this.resultSet.resultsRows.length > 0) {
			var that = this;
			dom_id = dom_id || this.dom_id;
			
			var table = '';
			var data = [];
			//re-order the data into an array
			for (var d_item in this.resultSet.resultsRows[0]) {
				
				if (this.resultSet.resultsRows[0].hasOwnProperty(d_item)) {
					data.push(this.resultSet.resultsRows[0][d_item]);
				}
			}
			// set alt tag for jqote. needed to avoid problem with php's asp_tags ini directive
			jQuery.jqotetag('*');
			//make table headers
			var ths = jQuery('#simpleTable-headers').jqote(data); 
			// make outer table
			table = jQuery('#simpleTable-outer').jqote({dom_id: dom_id+'_simpleTable', headers: ths});
			// add to dom
			jQuery('#'+dom_id).html(table);
			// append rows
			for(i=0;i<= this.resultSet.resultsRows.length -1;i++) {
			
				var cells = '';
				for (var r_item in this.resultSet.resultsRows[i]) {
				
					if (this.resultSet.resultsRows[i].hasOwnProperty(r_item)) {
						cells += jQuery('#table-column').jqote(this.resultSet.resultsRows[i][r_item]);
					}
				}
				
				var row = jQuery('#table-row').jqote({columns: cells});
				jQuery('#'+dom_id+'_simpleTable').append(row);	
			}
	    
		} else {
			jQuery('#'+dom_id).html("No results to display.");
		}
    },
    
    getApiEndpoint : function() {
    	
    	return this.getOption('api_endpoint') || OWA.getSetting('api_endpoint');
    },
    
    makeApiRequestUrl : function(method, options, url) {
    	
    	var url = url || this.getApiEndpoint();
    	url += '?';
    	url += 'owa_do=' + method;
    	var count = OWA.util.countObjectProperties(options);
    	var i = 1;
    	for (option in options) {
    		
    		if (options.hasOwnProperty(option)) {
    			
    			if (typeof options[option] != 'undefined') {
    				url += '&owa_' +option + '=' + OWA.util.urlEncode(options[option]);
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
 * @param	target_dom_id	string	dom id where the control should be created.
 * @param 	options			obj		config object
 */
OWA.dimensionPicker = function(target_dom_selector, options) {
	
	this.dim_list = {};
	this.alternate_field_selector = '';
	this.dom_id = target_dom_selector;
	this.exclusions = [];
	
	if ( options && options.hasOwnProperty('exclusions') ) {
		
		this.setExclusions(options.exclusions);
	}
	
};

OWA.dimensionPicker.prototype = {
	
	
	setDimensions : function ( dims ) {
				
		this.dim_list = dims;
	},
	
	reset: function(dim_list) {
	
		if ( dim_list ) {
			this.setDimensions( dim_list );
		}
		
		this.generateDimList();
	},
	
	display: function(selected) {
	
		var dom_id = this.dom_id;
		
		var container_selector = dom_id;
		
		// add container level dom elements
		var container_dom_elements =  '<span class="dimensionPicker">';		
		container_dom_elements += '</span>';
		jQuery( container_selector ).html( container_dom_elements );
		
		// hide the dim list
		jQuery( container_selector + ' > .dimensionPicker > .dim-list').hide();
		
		this.generateDimList(container_selector + ' > .dimensionPicker', selected);
	},
		
	setDimensionlist : function ( dim_list ) {
		
		this.dim_list = dim_list;
	},
	
	generateDimList : function(selector, selected) {
		
		var container_selector = selector;
		var c = '<select data-placeholder="Select..." name="dim-list" class="dim-list" style="width:150px;"><option value=""></option>';
		var that = this;
		
		if ( OWA.util.countObjectProperties( this.dim_list ) > 0 ) {

			for (group in this.dim_list) {
					
				if ( this.dim_list.hasOwnProperty(group) ) {
					
					c += OWA.util.sprintf('<optgroup label="%s">', group);
					
					var num_dim_in_group = 0;
					// add list items
					for( var i=0; i < this.dim_list[group].length; i++ ) {
						
						// check to see if the dim is on the exclusion list
						if ( this.exclusions.length > 0 && 
						     OWA.util.in_array( this.dim_list[group][i].name, this.exclusions )
						) { 
							// skip if so
							continue;
						} else {		
						
							c += OWA.util.sprintf(
									'<option value="%s">%s</option>',
									this.dim_list[group][i].name,
									this.dim_list[group][i].label
							);
	
							num_dim_in_group++;
						}
					}
					
					// if there are no dims in a group due to 
					// exclusions there remoe the header
					
					if ( num_dim_in_group < 1 ) {
						//jQuery( container_selector + ' > .dimensionPicker > .dim-list > h4:last' ).remove();
					}
				}
			}
		} else {
			c += OWA.l('There are no related dimensions.');
		}
		// append container and list to dom
		jQuery( container_selector ).append(c);
		// transform into select menu
		
		jQuery( container_selector + ' > .dim-list' ).chosen({no_results_text: "Name not found."});
		
		
jQuery( container_selector + ' > .dim-list' ).chosen().change( function() {
				
				//OWA.debug(JSON.stringify(obj));
				var value = jQuery(selector + ' > .dim-list').val();
				jQuery( that.dom_id ).trigger(
					'dimension_change', 
					['', value]	
				);			
		});

		
		// set select value
		if ( selected ) {
			jQuery(selector + ' > .dim-list').val( selected ).trigger('liszt:updated');
			
		} else {		
		
			// hack for setting label of select menu
			//jQuery(container_selector + ' > .ui-selectmenu > .ui-selectmenu-status').html(OWA.l('Select...'));
		
		}		
	},
	
	setAlternateField : function( selector ) {
		
		this.alternate_field_selector = selector;
	},
	
	setExclusions : function ( ex_array ) {
		
		this.exclusions = ex_array;
	}

};

/**
 * Data Grid UI control Class
 *
 * @param	target_dom_id	string	dom id where the control should be created.
 * @param 	options			obj		config object
 *
 */

OWA.dataGrid = function(target_dom_id, options) {
	
	this.dom_id = target_dom_id;
	this.options = options;
	this.init = false;
	this.gridColumnOrder = [];
	this.columnLinks = '';
	this.constraintPicker = '';
};

OWA.dataGrid.prototype = {

	generate : function(resultSet) {
		OWA.debug( 'hi from generate');
		var that = this;
		
		// custom formattter functions.
		jQuery.extend(jQuery.fn.fmatter , {
			// urlFormatter allows for a single param substitution.
			urlFormatter : function(cellvalue, options, rowdata) {
			//alert(JSON.stringify(cellvalue));
				var sub_value = options.rowId;
				//alert(options.rowId);
				var name = options.colModel.realColName;
				OWA.debug(options.rowId-1+' '+name);
				
				if ( rowdata[name].link.length > 0 ) {
					var new_url = rowdata[name].link;
					var link =  '<a href="' + new_url + '">' + cellvalue.formatted_value + '</a>';
					return link;
				}
			},
			
			useServerFormatter : function(cellvalue, options, rowdata) {
				var name = options.colModel.realColName;
				return rowdata[name].formatted_value;
				//return that.resultSet.resultsRows[options.rowId-1][name].formatted_value;
			}
			
		});
			
		// load grid control
			
		// happens with first results set when loading from URL.
		if (this.init !== true) {
		
			this.display(resultSet);
					
		} else {
		
			this.refresh(resultSet);
		}
		
		// hide the built in jqgrid loading divs. 
		jQuery("#load_"+that.dom_id+"_grid").hide(); 
		jQuery("#load_"+that.dom_id+"_grid").css("z-index", 101);
		
		// check to see if we need ot hide the previous page control.
		if (resultSet.page == 1) {
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').show();
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').hide();
		} else if (resultSet.page == resultSet.total_pages) {
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').hide();
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').show();
		} else {
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').show();
		}
		//alert(resultSet.page + ' ' + resultSet.total_pages);
		
	},

	
	/**
	 * creates the entire grid for the first time
	 * @private
	 */
	display : function( resultSet ) {
		
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
	refresh : function(resultSet) {
		
		var that = this;
		// unload current grid jut in case columns have changed
		jQuery("#" + that.dom_id + '_grid').jqGrid('GridUnload', "#gbox_" + that.dom_id + '_grid');
		// setup grid columns/options again
		this.setGridOptions(resultSet);
		jQuery("#"+that.dom_id + ' _grid').jqGrid('clearGridData',true);
		this.addAllRowsToGrid(resultSet);
	},
	
	// listens for changes to parent resultSet object
	subscribeToDataUpdates : function() {
		
		var that = this;
		// listen for data changes
		jQuery( '#' + that.dom_id ).bind('new_result_set', function(event, resultSet) {
			that.generate(resultSet);
		});
	},
	
	makeGridPagination : function(resultSet) {
		
		if (resultSet.more) {
			
			var that = this;
			
			var p = '';
			p = p + '<LI class="owa_previousPageControl">';
			p = p + '<span>&laquo</span></LI>';
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL').append(p);
			//style button
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').button();
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl > .ui-button-text').css('line-height', '0.5');
			// bind click
			jQuery(".owa_previousPageControl").bind('click', function() {that.pageGrid('back');});	
			
			var pn = '';
			pn = pn + '<LI class="owa_nextPageControl">';
			pn = pn + '<span>&raquo</span></LI>';
			
			jQuery("#"+that.dom_id + ' > .owa_resultsExplorerBottomControls > UL').append(pn);
			// style button
			//style button
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').button();
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl > .ui-button-text').css('line-height', '0.5');
			//bind click
			jQuery("#"+that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').bind('click', function() {that.pageGrid('forward');});
			
			if (resultSet.page == 1) {
				jQuery("#" + that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').hide();
			}
			
		}
	},
	
	pageGrid : function (direction) {
	
		var that = this;
		// valid event names are 'page_forward' and 'page_back'
		jQuery('#' + that.dom_id).trigger('page_' + direction, []);	
	},
	
	addAllRowsToGrid :function(resultSet) {
		
		var that = this; 
		// uses the built in jqgrid loading divs. just giveit a message and show it.
		jQuery("#load_"+that.dom_id+"_grid").html('Loading...');
		jQuery("#load_"+that.dom_id+"_grid").show(); 
		jQuery("#load_"+that.dom_id+"_grid").css("z-index", 1000);
		// add data to grid
		jQuery("#"+that.dom_id + '_grid')[0].addJSONData(resultSet);
		// dispay new count
		this.displayRowCount(resultSet);
	},
	
	displayRowCount : function(resultSet) {
		
		if (resultSet.total_pages > 1) {

			var start = '';
			var end = '';
			if (resultSet.page === 1) {
				start = 1;
				end = resultSet.resultsReturned;
			} else {
				start = ((resultSet.page -1)  * resultSet.resultsPerPage) + 1;
				end = ((resultSet.page -1) * resultSet.resultsPerPage) + resultSet.resultsReturned;
			}
			
			var that = this;
			//jQuery("#"+that.dom_id + '_grid').jqGrid('setGridParam', { rowNum: start } );
			
			var p = '<li class="owa_rowCount">';
			p += 'Results: '+ start + ' - ' + end;
			p = p + '</li>';
		
			
			//alert ("#"+that.dom_id + '_grid' + ' > .owa_rowCount');
			var check = jQuery("#"+that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_rowCount').html();
			//alert(check);
			if (check === null)	{
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL').append(p);
			} else {
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_rowCount').html(p);			
			}
		}
	},
	
	injectDomElements : function(resultSet) {
	
		var p = '';
		p += '<div class="owa_genericHorizontalList explorerTopControls"><ul></ul><div style="clear:both;"></div></div>';
		p += '<div style="clear:both;"></div>';
		p += '<table id="'+ this.dom_id + '_grid"></table>';
		p += '<div class="owa_genericHorizontalList owa_resultsExplorerBottomControls"><ul></ul></div>';
		p += '<div style="clear:both;"></div>';
		
		var that = this;
		jQuery('#'+that.dom_id).append(p);
		
		// add top level controls
		// secondard dimension picker
		jQuery('#'+that.dom_id + ' > .explorerTopControls > ul').append(
			OWA.util.sprintf(
				'<li class="controlItem"><span class="label">%s:</span> <span id="%s"></span></li>', 
				OWA.l('Secondary Dimension'), 
				this.dom_id + '_grid_secondDimensionChooser' 
			)
		);
		
		// create secondary dimension picker
		var sdc = new OWA.dimensionPicker('#' + this.dom_id + '_grid_secondDimensionChooser');
		sdc.setExclusions( this.getDimensions( resultSet ) );
		//sdc.setExclusions( this.gridColumnOrder );
		sdc.setDimensions( resultSet.relatedDimensions );
		sdc.display();
		
		// listen for the change to secondary dimension
		jQuery( '#' + that.dom_id + '_grid_secondDimensionChooser')
			.bind('dimension_change', function(event, oldname, newname) {
			
			// lookup current secondary dimension as displayed in the grid
			if ( that.gridColumnOrder.length >= 1 ) {
				oldname = that.gridColumnOrder[1];
			} else {
				oldname = '';
			}
			
			// propigate the event up one level where result set explorer is listening
			jQuery( '#' + that.dom_id ).trigger('secondary_dimension_change', [oldname, newname]);
		});
		
		// inject constraint builder
		// secondard dimension picker
		jQuery('#'+that.dom_id + ' > .explorerTopControls > ul').append('<li class="controlItem"><span class="label">Filter:</span> <span class="constraintPicker"></span></li>');
		// constraint builder selector		
		var cb_button_selector = '#'+ this.dom_id + ' > .explorerTopControls > ul > .controlItem > .constraintPickerButton';
		var cb_cont_selector = '#'+ this.dom_id + ' > .explorerTopControls > ul > .controlItem > .constraintPicker';

		// turn into button
		jQuery(cb_button_selector).button();
		
		// make object
		this.constraintPicker = new OWA.constraintBuilder(cb_cont_selector, {});
		
		this.constraintPicker.setRelatedDimensions( resultSet.relatedDimensions, [] );
		this.constraintPicker.setRelatedMetrics( resultSet.relatedMetrics, [] );
		// add current constraints to this method call
		var resultSet_url = new OWA.uri( resultSet.self );
		var cur_con = resultSet_url.getQueryParam('owa_constraints');
		this.constraintPicker.display(cur_con);
		
		// listen for the constraint change event
		jQuery( cb_cont_selector).bind('constraint_change', function(event, constraints) {
			// propigate the event up one level where result set explorer might be listening
			jQuery( '#' + that.dom_id ).trigger('constraint_change', [constraints]);
		});
	},
	
	setGridOptions : function(resultSet) {
		
		var that = this;
		
		var columns = [];
		
		var columnDef = '';
		
		// reset grid column order
		this.gridColumnOrder = [];
		
		for (var column in resultSet.resultsRows[0]) {
			
			// check to see if we should exclude any columns
			if (this.options.excludeColumns.length > 0) {
				
				for (var i=0;i<=this.options.excludeColumns.length -1;i++) {
					// if column name is not on the exclude list then add it.
					if (this.options.excludeColumns[i] != column) {
						// add column	
						columnDef = this.makeGridColumnDef(resultSet.resultsRows[0][column]);
						columns.push(columnDef);
						// set grid column order
						this.gridColumnOrder.push( resultSet.resultsRows[0][column].name );			
					}
				}
				
			} else {
				// add column
				columnDef = this.makeGridColumnDef(resultSet.resultsRows[0][column]);
				columns.push(columnDef);
				// set grid column order
				this.gridColumnOrder.push( resultSet.resultsRows[0][column].name );
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
			afterInsertRow: function(rowid, rowdata, rowelem) {return;},
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
			onSortCol: function( index, iCol, sortorder ) {
				
				//that.sortGrid( index, sortorder );
				jQuery('#' + that.dom_id).trigger('sort_column_change', [index, sortorder]);
				return 'stop';
			}
		});
		
		// set header css
		for (var y=0;y < columns.length;y++) {
			var css = {};
			//if dimension column then left align
			if ( columns[y].classes == 'owa_dimensionGridCell' ) {
				css['text-align'] = 'left';
			} else {
				css['text-align'] = 'right';
			}
			// if sort column then bold.
			if (resultSet.sortColumn +'' === columns[y].name) {
				//css.fontWeight = 'bold';
			}
			// set the css. no way to just set a class...
			jQuery('#' + that.dom_id + '_grid').jqGrid('setLabel', columns[y].name, '',css);
		}
	
	},
		
	// private
	makeGridColumnDef : function(column) {
		
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
		}
		
		// set custom formatter if one exists.
		if (this.options.columnFormatters.hasOwnProperty(column.name)) {
			_format = this.options.columnFormatters[column.name];
		}
		
		if ( this.columnLinks.hasOwnProperty( column.name ) ) {
			_link_template = this.columnLinks[column.name].template;
		}
				
		var columnDef = {
			//name: column.name +'.value', 
			name: column.name, 
			//index: column.name +'.value',
			index: column.name +'', 
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
	
	getDimensions : function ( resultSet ) {
    	
    	var dims = '';
    	var self = new OWA.uri(resultSet.self);
    	dims = OWA.util.urldecode( self.getQueryParam('owa_dimensions') );
    	dims = dims.split(',');
    	
    	return dims;
    }
	
};

OWA.constraintBuilder = function( target_dom_selector, options ) {
	
	this.dom_selector = target_dom_selector;
	this.options = {};
	this.constraints = {};
	this.relatedDimensions = {};
	this.relatedMetrics = {};
	
};

OWA.constraintBuilder.prototype = {
	
	operators: {
		'==':	'Exactly Matching',
		'!=':	'Not Matching',
		'>':	'Greater than',
		'<':	'Less than',
		'=@':	'Contains'
			
	},
	
	parseConstraintString : function( str ) {
		
		con_obj = {
			name: 		'',
			value:		'',
			operator: 	''
		};
		
		return con_obj;
	},
	
	constraintsStringToArray : function ( str ) {
		
		var a = []
		var c_array = [];
		
		if (str) {
			
			if ( OWA.util.strpos(str, ',') ) {
				a = str.split(',');
			} else {
				a.push(str);
			}
			
			for( var i=0; i < a.length; i++ ) {
				
				for ( operator in this.operators ) {
					
					if ( this.operators.hasOwnProperty(operator) ) {
						
						if ( OWA.util.strpos( a[i], operator ) ) {
							
							var b = a[i].split(operator);
							
							var c = {
								'name': 	b[0], 
								'operator': operator,
								'value':	b[1]
							};
							
							c_array.push(c);
						}
					}
				}
			}
		
		}
		
		return c_array;
	},
	
	display : function ( constraints_str ) {
		
		var c_array = this.constraintsStringToArray(constraints_str);
		this.createConstraintAssembler(c_array);
	},
	
	createConstraintAssembler : function( constraints ) {
		
		var that = this;
		// outer container
		jQuery(that.dom_selector).append(
			'<div class="constraintPickerContainer"></div>'
		);
		
		var container_selector = that.dom_selector + ' > .constraintPickerContainer';
		
		jQuery(container_selector).append(
			'<span class="toggle-button"></span><div class="builder"><ul></ul><div style="clear:both;"></div><div class="add-button"></div><div class="apply-button"></div>'
		);
		
		var button_selector = container_selector + ' > .toggle-button';
		var builder_selector = container_selector + ' > .builder';
		jQuery(builder_selector).hide();
		
		// if there are existing constraints
		if (constraints.length > 0) {
			
			for (var i=0; i < constraints.length; i++) {
				
				this.addNewConstraintRow( 
					builder_selector + ' > ul', 
					constraints[i].name,
					constraints[i].operator,
					constraints[i].value
				);
			}
			
		} else {
			// just add an empty row
			this.addNewConstraintRow(builder_selector + ' > ul');
		}
		
		// setup the toggle button
		jQuery( button_selector )
			.button({ 
				icons: {
					primary:'ui-icon-blank',
					secondary:'ui-icon-triangle-1-s'
				},
				label: OWA.l('Select...')
			})
			.click(function() {
				jQuery(builder_selector).toggle();
		});
		
		// setup add button
		jQuery( builder_selector + ' > .add-button' )
			.button({ 
				
				label: OWA.l('+ Add Filter ')
			})
			.click(function() {
				that.addNewConstraintRow( builder_selector + ' > ul' );
		});
		
		// setup apply button
		jQuery( builder_selector + ' > .apply-button' )
			.button({ 
				
				label: OWA.l('Apply')
			})
			.click(function() {
				
				var constraints = '';
				
				// iterate through constraint rows
				jQuery(builder_selector + ' > ul > li').each(function(index) {
    				
    				var name = jQuery(this)
    					.children('.constraintDimensionPicker')
    						.children('.dimensionPicker')
    							.children('.dim-list').val();
    							
    				var operator = jQuery(this)
    					.children('.constraintOperatorPicker')
    							.children('.operator-list').selectmenu('value');
    				
    				var value = jQuery(this)
    					.children('.constraintValueField').val();
    				
    				if ( value ) {
    				//constraints += OWA.util.sprintf('%s%s%s,' name, operator, value);
						constraints += name + operator + value;
						
						if (index < jQuery(builder_selector + ' > ul > li').length - 1 ) {
						//if (index < jQuery(this).siblings().length - 1 ) {	
							constraints += ',';
						}
					}					
					
  				});
				
				var el = jQuery( that.dom_selector ).trigger('constraint_change', [constraints]);
			});
	},
	
	setRelatedDimensions: function ( dims, exclusions ) {
		
		if (exclusions) {
			// filter the dim list
		}
		
		this.relatedDimensions = dims;
	},
	
	setRelatedMetrics : function ( metrics, exclusions ) {
	
		if (exclusions) {
			// filter the dim list
		}
		
		this.relatedMetrics = metrics;
	},
	
	combineRelatedMetricsWithDimensions : function() {
		
		var metrics = false;
		var dimensions = false;
		
		if ( OWA.util.countObjectProperties(this.relatedDimensions) > 0 ) {
			
			dimensions = true;
		}
		
		if ( OWA.util.countObjectProperties(this.relatedMetrics) > 0 ) {
			
			metrics = true;
		}
				
		if ( metrics && dimensions ) {
			
			var n = this.relatedDimensions;
			
			for ( metric in this.relatedMetrics ) {
				
				if ( this.relatedMetrics.hasOwnProperty( metric ) ) {
					n[metric] = this.relatedMetrics[metric];
				}
			}
			
			return n;
			
		} else if ( metrics ) {
		
			return this.relatedMetrics;
		
		} else if ( dimensions ) {
			
			return this.relatedDimensions;
		}
	},
	
	addNewConstraintRow : function(selector, name, operator, value) {
	
		// generate container
		
		// generate the dim/metric chooser button
		jQuery( selector ).append(
			'<LI class="constraintRow"><span class="constraintDimensionPicker"></span> <span class="constraintOperatorPicker"></span><input class="constraintValueField" type="text" size="30"><span class="constraintRemoveButton">X</span></LI>'
		);
		
		// create constraint dimension picker
		var dimpicker_selector = selector + ' > li:last > .constraintDimensionPicker';
		var cdp = new OWA.dimensionPicker(dimpicker_selector);
		cdp.setDimensions( this.combineRelatedMetricsWithDimensions() );
		cdp.display(name); 
		
		// generate operatior picker
		this.makeOperatorPicker(selector + ' > li:last > .constraintOperatorPicker', operator);
		
		if (value) {
			jQuery(selector + ' > li:last > .constraintValueField').val(value);
		}
		
		// setup add button
		jQuery( selector + '> li:last > .constraintRemoveButton' )
			.button({ 
				
				label: OWA.l('X')
			})
			.click(function() {
				jQuery( this ).parent().remove();
		});

		
		
		
	},
	
	makeOperatorPicker : function( selector, selected ) {
		
		// append the container
		var c = ''
		//c += '<label for="operator-list">Select Operator:</label>';
		c += '<select name="operator-list" class="operator-list">';
		
		// build the list of operators
		for (operator in this.operators) {
			
			if ( this.operators.hasOwnProperty( operator ) ) {
				
				c += OWA.util.sprintf(
						'<option value="%s">%s</option>', 
						operator, 
						this.operators[operator]
				);				
			}
		}
		
		c += '</select>';
		c += '';
	
		jQuery(selector).append(c);
		jQuery(selector + ' > .operator-list').selectmenu({width:200});
		
		// set select value
		if ( selected ) {
			jQuery(selector + ' > .operator-list').selectmenu("value", selected);
		}
	
	}
	
};