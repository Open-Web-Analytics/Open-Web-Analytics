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
	
	this.domSelectors = {
		areaChart: '', 
		grid: ''
	};
	
	this.options = {
		defaultView: 'grid', 
		areaChart: {
			series:[]
		}, 
		pieChart: { 
			metric: '',
			dimension: '',
			metrics: [],
			numSlices: 5
		},
		grid: '',
		chartHeight: 200, 
		chartWidth:700,
		autoResizeCharts: true,
		views:['grid', 'areaChart','pie']
	};
};

OWA.resultSetExplorer.prototype = {
	
	viewMethods: {
		grid: 'refreshGrid', 
		areaChart: 'makeAreaChart', 
		pie: 'makePieChart'
	},
	
	getOption : function(name) {
		
		return this.options[name];
	},
	
	setView : function(name) {
		
		this.view = name;
	},
	
	// called after data is rendered for a view
	setCurrentView : function(name) {
		
		jQuery(that.domSelectors[that.currentView]).toggle();
		this.currentView = name;
	},
	
	getRowValues : function(old) {
		
		var row = {};
		
		for (var item in old) {
			row[item] = old[item].value;
		}
	
		return row;
	},
	
	// makesa unqiue idfor each row
	makeRowGuid : function(row) {
		
	},
	
	loadFromArray : function(json, view) {
	
		view = view || 'grid';
		
		this.injectDomElements();
		this.setResultSet(json);
		this.displayGrid();
	},
	
	load : function(url, view) {
	
		if (view) {
			this.view = view;
		}
		
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
		
	refreshGrid : function() {
			
		var that = this;
		
		if (this.resultSet.resultsReturned > 0) {
		
			// happens with first results set when loading from URL.
			if (this.gridInit != true) {
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
			} else {
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').show();
			}
			
		} else {
			jQuery("#"+that.dom_id).html("No data is available for this time period.");
		}		
	},
	
	loader : function(data) {
		
		if (data) {
			this.setResultSet(data);
		}
		
		if (!this.view) {
			this.view = this.getOption('defaultView');
		} 
		
		var method_name = this.viewMethods[this.view];
		
		this[method_name]();		
	},
	
	// fetch the result set from the server
	getResultSet : function(url) {
		
		var that = this;
		
		// uses the built in jqgrid loading divs. just giveit a message and show it.
		jQuery("#load_"+that.dom_id+"_grid").html('Loading...');
		jQuery("#load_"+that.dom_id+"_grid").show(); 
		jQuery("#load_"+that.dom_id+"_grid").css("z-index", 1000);
		jQuery.getJSON(url, '', function (data) {that.loader(data)});
	},
	
	injectDomElements : function() {
	
		var p = '';
		
		p += '<table id="'+ this.dom_id + '_grid"></table>';
		p += '<div class="owa_genericHorizontalList owa_resultsExplorerBottomControls"><ul></ul></div>';
		
		var that = this;
		jQuery('#'+that.dom_id).append(p);
	},
	
	setGridOptions : function() {
	
		var that = this;
		
		var columns = [];
		
		for (var column in this.resultSet.resultsRows[0]) {
		
			var _sort_type = '';
			var _align = '';
			var _format = '';
			var _class = '';
			var _width = '';
			var _resizable = true;
			var _fixed = false;
			
			if (this.resultSet.resultsRows[0][column].result_type === 'dimension') {
				_align = 'left';
				_class = 'owa_dimensionGridCell';
			} else {
				_align = 'right';
				_class = 'owa_metricGridCell';
				_width = 100;
				_resizable = false;
				_fixed = true;
			}
			
			if (this.resultSet.resultsRows[0][column].data_type === 'string') {
				_sort_type = 'text';
			} else {
				_sort_type = 'number';
			}
			
			if (this.resultSet.resultsRows[0][column].link) {
				_format = 'urlFormatter';
			}
					
		 	columns.push({
		 		name: this.resultSet.resultsRows[0][column].name +'.value', 
 				index: this.resultSet.resultsRows[0][column].name +'.value', 
 				label: this.resultSet.resultsRows[0][column].label, 
 				sorttype: _sort_type, 
 				align: _align, 
 				formatter: _format, 
 				classes: _class, 
 				width: _width, 
 				resizable: _resizable,
 				fixed: _fixed,
 				realColName: this.resultSet.resultsRows[0][column].name
 			});
		}
		
		jQuery('#' + that.dom_id + '_grid').jqGrid({
			jsonReader: {
				repeatitems: false,
		   		root: "resultsRows",
		   		cell: '',
		   		id: 0,
		   		page: 'page',
		   		total: 'total_pages',
		   		records: 'resultsReturned'
			},
			afterInsertRow: function(rowid, rowdata, rowelem) {return;},
			datatype: 'local',
			colModel: columns,
			rownumbers: true,
			viewrecords: true,
			rowNum: that.resultSet.resultsReturned,
			height: '100%',
			autowidth: true,
			hoverrows: false
		});
		
		// custom formattter functions.
		jQuery.extend(jQuery.fn.fmatter , {
			// urlFormatter allows for a single param substitution.
    		urlFormatter : function(cellvalue, options, rowdata) {
    			var sub_value = options.rowId;
    			var name = options.colModel.realColName; 
    			//var name = 'actionName';
    			//alert(that.columnLinks[name].template);
    			var new_url = that.columnLinks[name].template.replace('%s', escape(sub_value)); 
    			var link =  '<a href="' + new_url + '">' + cellvalue + '</a>';
    			return link;
			}
		});
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
		
		if (this.resultSet.more) {

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
			jQuery(".owa_previousPageControl").bind('click', function() {that.pageGrid(that.resultSet.previous)});	
			
			var p = '';
			p = p + '<LI class="owa_nextPageControl">';
			p = p + '<span>Next Page</span></LI>';
			
			jQuery("#"+that.dom_id + ' > .owa_resultsExplorerBottomControls > UL').append(p);
			jQuery(".owa_nextPageControl").bind('click', function() {that.pageGrid(that.resultSet.next)});
			
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
			item.name = col_name
			item.template = link_template
			item.params = sub_params;
			
			this.columnLinks[col_name] = item;
			item = '';
		}
		
		this._columnLinksCount++
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
						
						//alert(this.dom_id + ' : '+y);
						//alert(this.columnLinks[y]);
						if (this.resultSet.resultsRows[i][y].name.length > 0) {
							//if (this.resultSet.resultsRows[i][this.columnLinks[y]].name.length > 0) {
							for (var z in this.columnLinks[y].params) {
								
								var template = this.columnLinks[y].template.replace(this.columnLinks[y].params[z] + '=%s', this.columnLinks[y].params[z] + '=' + this.resultSet.resultsRows[i][this.columnLinks[y].params[z]].value); 
							}
							
							
							this.resultSet.resultsRows[i][this.columnLinks[y].name].link = template;
						}						
						
					}
				}
			}
		}
	},
	
	getContainerWidth : function() {
		
		var that = this;
		
		if (this.getOption('autoResizeCharts')) {
			var w = jQuery("#"+that.dom_id).width();
		} else {
			var w = this.getOption('chartWidth')
		}
		
		return w;
	
	},
	
	getContainerHeight : function() {
		var that = this;
		var h =  jQuery("#"+that.dom_id).height();
		//alert(h);
		return h;
		
	},
	
	setupAreaChart : function() {
		
		this.domSelectors.areaChart = "#"+this.dom_id + ' > .owa_barChart';
		
		var that = this;
		
		
		var w = this.getContainerWidth();
		var h = this.getContainerHeight() || this.getOption('chartHeight');
		
		jQuery("#"+that.dom_id).append('<div class="owa_barChart"></div>');
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
                    
                    that.showTooltip(item.pageX, item.pageY,
                                x+'<BR><B>'+item.series.label + ":</B> " + y);
                }
            } else {
                jQuery("#tooltip").remove();
                previousPoint = null;            
            }
       
    	});
    	
    	jQuery(window).resize(function () {
    		
    		var sel = that.domSelectors.areaChart;
    		//alert(sel);
    		//var that = this;
    		var chartw =jQuery(sel).width(); 
    		var containerw = jQuery("#"+that.dom_id).width();
    		var ccontainerw = that.currentContainerWidth;
    		var ww = jQuery(window).width();
    		OWA.debug('cur-container-w: '+ccontainerw);
    		OWA.debug('new-container-w: '+containerw);
    		
    		
    		if (containerw != ccontainerw) {
	    		
	    		var d = that.currentWindowWidth - ww;
	    		jQuery(sel).css('width', chartw - d);
    			that.makeAreaChart();
    		}
    		that.currentContainerWidth = containerw;
    		that.currentWindowWidth = ww;
    	});
    	
    	
		
	},
	
	
	/**
	 * Main method for displaying an area chart
	 */
	makeAreaChart : function() {
		
		var dataseries = [];
		var series = this.options.areaChart.series;
		
		for(var ii=0;ii<=series.length -1;ii++) {
		
			var x_series_name = series[ii].x;
			var y_series_name = series[ii].y;
		
			var data = [];
			
			//create data array
			for(var i=0;i<=this.resultSet.resultsRows.length -1;i++) {
				var item =[this.resultSet.resultsRows[i][x_series_name].value, this.resultSet.resultsRows[i][y_series_name].value];
				data.push(item);
			}
			//alert(this.resultSet.resultsRows[i][series[ii].x].value);
			var l = this.getMetricLabel(y_series_name);
			dataseries.push({ label: l,  data: data});
			
		}
		
		var that = this;
		
		var selector = "#"+that.dom_id + ' > .owa_barChart';
		
		if(jQuery("#"+that.dom_id + ' > .owa_barChart').length == 0) {
		
			this.setupAreaChart();
		}
		
		var options = { 
			
			yaxis: { 
				tickDecimals:0 }, 
			xaxis:{
				ticks: data.length/2,
				tickDecimals: null
				//mode: "time",
    			//timeformat: "%y/%m/%d"
    		},
			grid: {show:true, hoverable: true, autoHilight:true, borderWidth:0, borderColor: null},
			series: {
				points: { show: true, fill: true},
				lines: { show: true, fill: true, fillColor: "rgba(202,225,255, 0.6)", lineWidth: 4}
				
			},
			colors: ["#1874CD", "#dba255", "#919733"]
		};
		jQuery.plot(jQuery(selector), dataseries, options);
		this.currentContainerWidth = jQuery("#"+that.dom_id).width();
		this.currentWindowWidth = jQuery(window).width();	
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
    
    setupPieChart : function() {
    	
    	var that = this;
    	var w = this.getContainerWidth();
    	//alert(w);
		var h = this.getOption('chartHeight');
		//alert(h);
    	jQuery("#"+that.dom_id).append('<div class="owa_pieChart"></div>');
		jQuery(that.domSelectors.pieChart).css('width', w);
		jQuery(that.domSelectors.pieChart).css('height', h);
    },
    
    makePieChart : function () {
     
	    this.domSelectors.pieChart = "#"+this.dom_id + ' > .owa_pieChart';
	    var selector = this.domSelectors.pieChart;
						
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
				
				if (numSlices > this.resultSet.resultsRows.length) {
					var iterations = this.resultSet.resultsRows.length;
				} else {
					var iterations = numSlices;
				}
				
				
				for(var i=0;i<=iterations -1;i++) {
					
					var item = {label: this.resultSet.resultsRows[i][dimension].value, data: this.resultSet.resultsRows[i][metric].value * 1};
					data.push(item);
					count = count + this.resultSet.resultsRows[i][metric].value;
				}
				
				// if there are extra slices then lump into other bucket.
				if (this.resultSet.resultsRows.length > iterations) {
					var other = this.resultSet.aggregates[metric] - count;
					data.push({label: 'others', data: others});
				}
				
			} else {
				//no results
			}
		} else {
			// plots a set of values taken from the aggregrate metrics array
			var metrics = this.options.pieChart.metrics;
			for(var i=0;i<=metrics.length -1 ;i++) {
				var value = this.resultSet.aggregates[metrics[i]].value * 1; 
				data.push({label: this.getMetricLabel(metrics[i]), data: value});
			}
			
			
		}
		
		if (this.init.pieInit != true) {
		
			this.setupPieChart();
		}
	    
	    // options
	    var options = {
			series: {
				pie: { 
					show: true
				}
			},
			legend: {
				show: false
			},
			colors: ["#1874CD", "#dba255", "#919733"]
		};
	    
    	// GRAPH
		jQuery.plot(jQuery(selector), data, options);
		this.init.pieChart = true;
    }
};