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
	
	getOption : function(name) {
		
		return this.options[name];
	},
	
	setView : function(name) {
		
		this.view = name;
	},
	
	getAggregates : function() {
		
		return this.resultSet.aggregates;
	},
	
	// called after data is rendered for a view
	setCurrentView : function(name) {
		
		jQuery(that.domSelectors[that.currentView]).toggle();
		this.currentView = name;
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
	
	// makesa unqiue idfor each row
	makeRowGuid : function(row) {
		
	},
	
	loadFromArray : function(json, view) {
	
		if (view) {
			this.view = view;
		}
		
		this.loader(json);
		
	},
	
	load : function(url) {
		
		url = url || this.loadUrl;
		this.getResultSet(url);
	},
	
	displayGrid : function () {
		this.injectDomElements();
		this.setGridOptions();
		this.addAllRowsToGrid();
		this.makeGridPagination();
		this.gridInit = true;
		this.currentView = 'grid';
		
	},
	
	makeGrid : function(dom_id) {
		
		dom_id = dom_id || this.dom_id;
		
		//if (typeof this.viewObjects[dom_id] != 'undefined') {
			this.viewObjects[dom_id] = new OWA.dataGrid(dom_id);
		//}
	
		this.viewObjects[dom_id].makeGrid(this.resultSet);
	},
		
	refreshGrid : function() {
			
		var that = this;
		
		// custom formattter functions.
		jQuery.extend(jQuery.fn.fmatter , {
			// urlFormatter allows for a single param substitution.
			urlFormatter : function(cellvalue, options, rowdata) {
				var sub_value = options.rowId;
				//alert(options.rowId);
				var name = options.colModel.realColName;
				//var name = 'actionName';
				//alert(that.columnLinks[name].template);
				OWA.debug(options.rowId-1+' '+name);
				//var new_url = that.columnLinks[name].template.replace('%s', escape(sub_value)); 
				var new_url = that.resultSet.resultsRows[options.rowId-1][name].link;
				var link =  '<a href="' + new_url + '">' + cellvalue + '</a>';
				return link;
			},
			
			useServerFormatter : function(cellvalue, options, rowdata) {
				var name = options.colModel.realColName; 
				return that.resultSet.resultsRows[options.rowId-1][name].formatted_value;
			}
			
		});
		
		
		
		if (this.resultSet.resultsReturned > 0) {
		
			// happens with first results set when loading from URL.
			if (this.gridInit !== true) {
				this.displayGrid();
			}
			
			jQuery("#"+that.dom_id + ' _grid').jqGrid('clearGridData',true);
			this.addAllRowsToGrid();	
			
			// hide the built in jqgrid loading divs. 
			jQuery("#load_"+that.dom_id+"_grid").hide(); 
			jQuery("#load_"+that.dom_id+"_grid").css("z-index", 101);
			
			// check to see if we need ot hide the previous page control.
			if (this.resultSet.page == 1) {
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').hide();
			} else if (this.resultSet.page == this.resultSet.total_pages) {
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').hide();
			} else {
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').show();
			}
			
		} else {
			jQuery("#"+that.dom_id).html("No data is available for this time period.");
		}		
	},
	
	loader : function(data) {
		
		if (data) {
			// check to see if resultSet is new 
			if (this.resultSet.length > 0) {
				// if not new then return. nothing to do.
				if (data.resultSet.guid === this.resultSet.guid) {
					return;
				}
					
			}
			
			this.setResultSet(data);
				
			if (this.view) {
				var method_name = this.viewMethods[this.view];
				this[method_name]();	
			}
			
			if (this.asyncQueue.length > 0) {
				
				for(var i=0;i< this.asyncQueue.length;i++) {
					
					this.dynamicFunc(this.asyncQueue[i]);
				}
			}	
		}	
	},
	
	dynamicFunc : function (func){
		//alert(func[0]);
		var args = Array.prototype.slice.call(func, 1);
		//alert(args);
		this[func[0]].apply(this, args);
	},
	
	// fetch the result set from the server
	getResultSet : function(url) {
		
		var that = this;
		
		// uses the built in jqgrid loading divs. just giveit a message and show it.
		jQuery("#load_"+that.dom_id+"_grid").html('Loading...');
		jQuery("#load_"+that.dom_id+"_grid").show(); 
		jQuery("#load_"+that.dom_id+"_grid").css("z-index", 1000);
		jQuery.getJSON(url, '', function (data) {that.loader(data);});
	},
	
	injectDomElements : function() {
	
		var p = '';
		
		p += '<table id="'+ this.dom_id + '_grid"></table>';
		p += '<div class="owa_genericHorizontalList owa_resultsExplorerBottomControls"><ul></ul></div>';
		p += '<div style="clear:both;"></div>';
		
		var that = this;
		jQuery('#'+that.dom_id).append(p);
	},
	
	setGridOptions : function() {
	
		var that = this;
		
		var columns = [];
		
		var columnDef = '';
		
		for (var column in this.resultSet.resultsRows[0]) {
			
			// check to see if we should exclude any columns
			if (this.options.grid.excludeColumns.length > 0) {
				
				for (var i=0;i<=this.options.grid.excludeColumns.length -1;i++) {
					// if column name is not on the exclude list then add it.
					if (this.options.grid.excludeColumns[i] != column) {
						// add column	
						columnDef = this.makeGridColumnDef(this.resultSet.resultsRows[0][column]);
						columns.push(columnDef);			
					}
				}
				
			} else {
				// add column
				columnDef = this.makeGridColumnDef(this.resultSet.resultsRows[0][column]);
				columns.push(columnDef);
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
			rownumbers: that.options.grid.showRowNumbers,
			viewrecords: true,
			rowNum: that.resultSet.resultsReturned,
			height: '100%',
			autowidth: true,
			hoverrows: false,
			sortname: that.resultSet.sortColumn + '.value',
			sortorder: that.resultSet.sortOrder
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
			if (this.resultSet.sortColumn +'.value' === columns[y].name) {
				//css.fontWeight = 'bold';
			}
			// set the css. no way to just set a class...
			jQuery('#' + that.dom_id + '_grid').jqGrid('setLabel', columns[y].name, '',css);
		}
		
		/*
		// custom formattter functions.
		jQuery.extend(jQuery.fn.fmatter , {
			// urlFormatter allows for a single param substitution.
			urlFormatter : function(cellvalue, options, rowdata) {
				var sub_value = options.rowId;
				//alert(options.rowId);
				var name = options.colModel.realColName;
				//var name = 'actionName';
				//alert(that.columnLinks[name].template);
				OWA.debug(options.rowId-1+' '+name);
				//var new_url = that.columnLinks[name].template.replace('%s', escape(sub_value)); 
				var new_url = that.resultSet.resultsRows[options.rowId-1][name].link;
				var link =  '<a href="' + new_url + '">' + cellvalue + '</a>';
				return link;
			}
		});
		*/
	},
	
	makeGridColumnDef : function(column) {
		
		var _sort_type = '';
		var _align = '';
		var _format = '';
		var _class = '';
		var _width = '';
		var _resizable = true;
		var _fixed = false;
		var _datefmt = '';
		
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
		if (this.options.grid.columnFormatters.hasOwnProperty(column.name)) {
			_format = this.options.grid.columnFormatters[column.name];
		}
				
		var columnDef = {
			name: column.name +'.value', 
			index: column.name +'.value', 
			label: column.label, 
			sorttype: _sort_type, 
			align: _align, 
			formatter: _format, 
			classes: _class, 
			width: _width, 
			resizable: _resizable,
			fixed: _fixed,
			realColName: column.name,
			datefmt: _datefmt
		};
		
		return columnDef;
		
	},
	
	makeColumnDefinitions : function() {
	
	},
	
		
	addRowToGrid : function(row, id) {
	
		var that = this;
		
		rowid = id || that.makeRowGuid(row);
		
		jQuery("#"+that.dom_id + '_grid').jqGrid('addRowData',rowid,that.getRowValues(row));
	},
	
	addAllRowsToGrid :function() {
		
		var that = this; 
		jQuery("#"+that.dom_id + '_grid')[0].addJSONData(that.resultSet);
		this.displayRowCount();
	},
	
	displayRowCount : function() {
		
		if (this.resultSet.total_pages > 1) {

			var start = '';
			var end = '';
			if (this.resultSet.page === 1) {
				start = 1;
				end = this.resultSet.resultsReturned;
			} else {
				start = ((this.resultSet.page -1)  * this.resultSet.resultsPerPage) + 1;
				end = ((this.resultSet.page -1) * this.resultSet.resultsPerPage) + this.resultSet.resultsReturned;
			}
			
			var p = '<li class="owa_rowCount">';
			p += 'Results: '+ start + ' - ' + end;
			p = p + '</li>';
		
			var that = this;
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
		
	makeGridPagination : function() {
		
		if (this.resultSet.more) {
			
			var that = this;
			
			var p = '';
			p = p + '<LI class="owa_previousPageControl">';
			p = p + '<span>Previous Page</span></LI>';
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL').append(p);
			jQuery(".owa_previousPageControl").bind('click', function() {that.pageGrid(that.resultSet.previous);});	
			
			var pn = '';
			pn = pn + '<LI class="owa_nextPageControl">';
			pn = pn + '<span>Next Page</span></LI>';
			
			jQuery("#"+that.dom_id + ' > .owa_resultsExplorerBottomControls > UL').append(pn);
			jQuery("#"+that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_nextPageControl').bind('click', function() {that.pageGrid(that.resultSet.next);});
			
			if (this.resultSet.page == 1) {
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').hide();
			}
			
		}
	},
	
	pageGrid : function (url) {
	
		this.getResultSet(url);
		var that = this;
			
	},
	
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
		//alert(this.dom_id);
	},
	
	setResultSet : function(rs) {
		this.resultSet = rs;
		this.applyLinks();
	},
	
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
	
	getContainerWidth : function() {
		
		var that = this;
		
		if (this.getOption('autoResizeCharts')) {
			return jQuery("#"+that.dom_id).width();
		} else {
			return this.getOption('chartWidth');
		}
	},
	
	getContainerHeight : function() {
		var that = this;
		var h =  jQuery("#"+that.dom_id).height();
		//alert(h);
		return h;
		
	},
	
	setupAreaChart : function(series, dom_id) {
		
		dom_id = dom_id || this.dom_id;
		this.domSelectors.areaChart = "#"+dom_id + ' > .owa_areaChart';
		
		var that = this;
	
		//var w = this.getContainerWidth();
		var w = jQuery("#"+dom_id).css('width');
		//alert(w);
		var h = this.getContainerHeight() || this.getOption('chartHeight');
		
		
		jQuery("#"+dom_id).append('<div class="owa_areaChart"></div>');
		
		jQuery(that.domSelectors.areaChart).css('width', w);
		jQuery(that.domSelectors.areaChart).css('height', h);
		
		// binds a tooltip to plot points
		var previousPoint = null;
		jQuery(that.domSelectors.areaChart).bind("plothover", function (event, pos, item) {

	        jQuery("#x").text(pos.x.toFixed(2));
	        jQuery("#y").text(pos.y.toFixed(2));
	        
            if (item) {
                if (previousPoint != item.datapoint) {
                    
                    previousPoint = item.datapoint;
                    
                    jQuery("#tooltip").remove();
                    var x = item.datapoint[0].toFixed(0),
                        y = item.datapoint[1].toFixed(0);
                        
                    if (that.options.areaChart.flot.xaxis.mode === 'time') {
                    
						x = that.timestampFormatter(x);
                    }
                    
                    that.showTooltip(item.pageX -75, item.pageY -50,
                                x+'<BR><B>'+item.series.label + ":</B> " + y);
                }
            } else {
                jQuery("#tooltip").remove();
                previousPoint = null;            
            }
       
		});
       
	},
	
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
	
	timestampFormatter : function(timestamp) {
		
		var d = new Date(timestamp*1);
		var curr_date = d.getDate();
		var curr_month = d.getMonth() + 1;
		var curr_year = d.getFullYear();
		//alert(d+' date: '+curr_month);
		var date =  curr_month + "/" + curr_date + "/" + curr_year;
		//var date =  curr_month + "/" + curr_date;
		return date;
	},
	
	
	/**
	 * Main method for displaying an area chart
	 */
	makeAreaChart : function(series, dom_id) {
		
		dom_id = dom_id || this.dom_id;
		var selector = "#"+dom_id + ' > .owa_areaChart';
		
		if (this.resultSet.resultsRows.length > 0) {
				
			
			var dataseries = [];
			series = series || this.options.areaChart.series;
			var data = [];
			for(var ii=0;ii<=series.length -1;ii++) {
			
				var x_series_name = series[ii].x;
				var y_series_name = series[ii].y;
			
				
				
				//create data array
				for(var i=0;i<=this.resultSet.resultsRows.length -1;i++) {
					data_type_x = this.resultSet.resultsRows[i][x_series_name].data_type;
					data_type_y = this.resultSet.resultsRows[i][y_series_name].data_type;
					var item =[this.formatValue(data_type_x, this.resultSet.resultsRows[i][x_series_name].value), this.formatValue(data_type_y, this.resultSet.resultsRows[i][y_series_name].value)];
					data.push(item);
				}
				//alert(this.resultSet.resultsRows[i][series[ii].x].value);
				var l = this.getMetricLabel(y_series_name);
				dataseries.push({ label: l,  data: data});
				
			}
			
			//var that = this;
			
			
			
			if(jQuery("#"+dom_id + ' > .owa_areaChart').length === 0) {
			
				this.setupAreaChart(series, dom_id);
			}
			
			var num_ticks = data.length;
			// reduce number of x axis ticks if data set has too many points.
			if (data.length > 10) {
			
				num_ticks = 10;
			}
			
			var options = { 
				
				yaxis: { 
					tickDecimals:0 }, 
				xaxis:{
					ticks: num_ticks,
					tickDecimals: null
				},
				grid: {show: this.options.chart.showGrid, hoverable: true, autoHilight:true, borderWidth:0, borderColor: null},
				series: {
					points: { show: this.options.areaChart.showDots, fill: this.options.areaChart.showDots},
					lines: { show: true, fill: true, fillColor: "rgba(202,225,255, 0.6)", lineWidth: this.options.areaChart.lineWidth}
					
				},
				colors: ["#1874CD", "#dba255", "#919733"],
				legend: {
					position: 'ne',
					margin: [0,-10],
					show:this.options.areaChart.showLegend
				}
			};
			
			if (data_type_x === 'yyyymmdd') {
				
				options.xaxis.mode = "time";
				//options.xaxis.timeformat = "%m/%d/%y";
				options.xaxis.timeformat = "%m/%d";
			}
			
			this.options.areaChart.flot = options;
			jQuery.plot(jQuery(selector), dataseries, options);
			this.currentContainerWidth = jQuery("#"+dom_id).width();
			this.currentWindowWidth = jQuery(window).width();
			
			// resize window handler
			var that = this;
			jQuery(window).resize(function () {
				var sel = that.domSelectors.areaChart;
				//alert(sel);
				//var that = this;
				var chartw =jQuery(sel).width();
				var containerw = jQuery("#"+dom_id).width();
				var ccontainerw = that.currentContainerWidth;
				var ww = jQuery(window).width();
				OWA.debug('cur-container-w: '+ccontainerw);
				OWA.debug('new-container-w: '+containerw);
				
				// check to see if the container or the window width has changed
				// redraw the graph if it has.
				if ((containerw != ccontainerw) || (ww != that.currentWindowWidth)) {
					
					//var d = that.currentWindowWidth - ww;
					var d =  ww - that.currentWindowWidth;
					//alert(d);
					jQuery(sel).css('width', chartw + d);
					that.makeAreaChart(series, dom_id);
				}
				that.currentContainerWidth = containerw;
				that.currentWindowWidth = ww;
			});			
			

		} else {
			jQuery('#'+ dom_id).html("No data is available for this time period");
			jQuery('#'+ dom_id).css('height', '50px');

		}
			
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
		//alert(this.resultSet.aggregates[name].label);
		if (this.resultSet.aggregates[name].label.length > 0) {
			return this.resultSet.aggregates[name].label;
		} else {
			return 'unknown';
		}
	},
	
    getMetricValue : function(name) {
		//alert(this.resultSet.aggregates[name].label);
		if (this.resultSet.aggregates[name].value.length > 0) {
			return this.resultSet.aggregates[name].value;
		} else {
			return 0;
		}
	},
	
	setupPieChart : function() {
	
		var that = this;
		var w = this.getContainerWidth();
		//alert(w);
		var h = this.getContainerWidth(); //this.getOption('chartHeight');
		//alert(h);
		jQuery("#"+that.dom_id).append('<div class="owa_pieChart"></div>');
		jQuery(that.domSelectors.pieChart).css('width', w);
		jQuery(that.domSelectors.pieChart).css('height', h);
    },
    
    makePieChart : function () {
     
	    this.domSelectors.pieChart = "#"+this.dom_id + ' > .owa_pieChart';
	    var selector = this.domSelectors.pieChart;
		var that = this;			
		//create data array
		var data = [];
		var count = 0;

		if (this.options.pieChart.dimension.length > 0) {
		// plots a dimensional set of data
		
			if (this.resultSet.resultsRows.length > 0) {
				
				var dimension = this.options.pieChart.dimension;
				var numSlices = this.options.pieChart.numSlices;
				var metric = this.options.pieChart.metric;
				
				//create data array
				var iterations = 0; 
				if (numSlices > this.resultSet.resultsRows.length) {
					iterations = this.resultSet.resultsRows.length;
				} else {
					iterations = numSlices;
				}
				
				
				for(var i=0;i<=iterations -1;i++) {
					
					var item = {label: this.resultSet.resultsRows[i][dimension].value, data: this.resultSet.resultsRows[i][metric].value * 1};
					data.push(item);
					count = count + this.resultSet.resultsRows[i][metric].value;
				}
				
				// if there are extra slices then lump into other bucket.
				if (this.resultSet.resultsRows.length > iterations) {
					var others = this.resultSet.aggregates[metric] - count;
					data.push({label: 'others', data: others});
				}
				
			} else {
				//no results
				jQuery('#'+ that.dom_id).append("No data is available for this time period");
				jQuery('#'+ that.dom_id).css('height', '50px');

			}
		} else {
			
			 if (!jQuery.isEmptyObject(that.resultSet.aggregates)) {
				// plots a set of values taken from the aggregrate metrics array
				var metrics = this.options.pieChart.metrics;
				for(var ii=0;ii<=metrics.length -1 ;ii++) {
					var value = this.resultSet.aggregates[metrics[ii]].value * 1; 
					data.push({label: this.getMetricLabel(metrics[ii]), data: value});
				}
			} else {
				//OWA.setSetting('debug', true);
				//OWA.debug('there was no data');
				//alert('hi');
				jQuery('#'+ that.dom_id).append("No data is available for this time period");
				jQuery('#'+ that.dom_id).css('height', '50px');
				
			}			
			
		}
		
		if (this.init.pieInit !== true) {
		
			this.setupPieChart();
		}
	    
	    // options
	    var options = {
			series: {
				pie: { 
					show: true,
					//showLabel: true,
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
				}
			},
			
			legend: {
				show: true,
				position: "ne",
				margin: [-160,50]
			},
			colors: ["#6BAED6", "#FD8D3C", "#dba255", "#919733"]
		};
		
		//GRAPH
		jQuery.plot(jQuery(selector), data, options);
		this.init.pieChart = true;
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
	
	makeSparkline : function(metric_name, dom_id, filter) {
		metric_name = metric_name || this.options.sparkline.metric;
		dom_id = dom_id || this.dom_id;
		var sl = new OWA.sparkline(dom_id);
		var data = this.getSeries(metric_name, '',filter);
		
		if (!data) {
			data = [0,0,0];
		}
		
		sl.loadFromArray(data);
		
		this.currentView = 'sparkline';
	},
	
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
		dom_id = dom_id || this.dom_id;
		template = template || '#metricInfobox';
		
		jQuery('#' + dom_id).append('<div class="metricInfoboxesContainer" style="width:auto;">');	
		for(var i in this.resultSet.aggregates) {
		
			if (this.resultSet.aggregates.hasOwnProperty(i)) {
				var item = this.resultSet.aggregates[i];
				item.dom_id = dom_id+'-'+this.resultSet.aggregates[i].name+'-'+this.resultSet.guid;
				if (label) {
					item.label = label;
				}
				
				if (this.options.metricBoxes.width) {
					item.width = this.options.metricBoxes.width;
				}
				
				
				// set alt tag for jqote. needed to avoid problem with php's asp_tags ini directive
				jQuery.jqotetag('*');
				jQuery('#' + dom_id).jqoteapp(template, item);		
				
				this.makeSparkline(this.resultSet.aggregates[i].name, item.dom_id+'-sparkline', filter);
				
			}	
		}
		jQuery('#' + dom_id).append('</div>');	
		jQuery('#' + dom_id).append('<div style="clear:both;"></div>');	
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