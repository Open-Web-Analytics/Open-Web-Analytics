OWA.areaChart = function( options ) {

	// config options
	this.options = {
		
		series: [],
		height: 125,
		width:	'99%', // needed for flot resize plugin
		xaxis: {
			mode: 'time'
			
		},
		timeformat: "%m/%d",
		showGrid: true,
		showLegend: true,
		showDots: true,
		showLegend: true,
		lineWidth: 4,
		autoResizeCharts: true,
		fillColor: "rgba(202,225,255, 0.6)",
		colors: ["#1874CD", "#dba255", "#919733"]
	};
	
	// merge passed options with defaults.
	if ( options ) {
		
		for (option in options) {
			
			if ( options.hasOwnProperty( option ) ) {
				this.options[ option ] = options[ option ];
			}
		}
	}
	
	this.dom_id = '';
	this.domSelector = '';
	this.init = false;
}

OWA.areaChart.prototype = {
		
	setDomId: function(dom_id) {
		
		this.dom_id = dom_id;
		this.domSelector = "#" + dom_id + ' > .owa_areaChart';
		
		// listen for data change events
		var that = this;
		jQuery( '#' + that.dom_id ).bind( 'new_result_set', function( event, resultSet ) {
			//jQuery( that.domSelector ).remove();
			that.generate( resultSet );
		});
		
	},
	
	getOption: function( name ) {
		
		if ( this.options.hasOwnProperty( name ) ) {
			return this.options[ name ];
		}
	},
	
	setOption: function ( name, value ) {
		
		this.options[name] = value;
	},
	
	getContainerHeight : function() {
		
		var that = this;
		var h =  jQuery("#"+that.dom_id).height();
		//alert(h);
		return h;	
	},
	
	// move to OWA.util
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
	generate : function( resultSet, series, dom_id ) {
		OWA.debug('generating area chart for ' + dom_id);
		// set dom_id just in case.
		if ( dom_id ) {
		
			this.setDomId( dom_id );
		}
		
		dom_id = this.dom_id;
		
		// set series just in case.
		if ( series ) {
		
			this.options.series = series;
		}
		
		var selector = this.domSelector;
		
		// remove in case the chart is already there.
		// this is kind of a hack as it mean that only one area chart can be placed in a dom_id at a time.
		// this is needed so that charts can be over riden when report
		// tabs change.
		jQuery( selector ).remove();
		
		// if there is data, plot it.
		if ( resultSet.resultsRows.length > 0 ) {
				
			// create data array for flot.
			var dataseries = [];
			
			series = this.options.series;
			
			var data = [];
			
			for( var ii = 0; ii <= series.length -1; ii++ ) {
			
				var x_series_name = series[ii].x;
				var y_series_name = series[ii].y;
			
				//create data array
				for( var i=0; i <= resultSet.resultsRows.length -1; i++ ) {
					data_type_x = resultSet.resultsRows[i][x_series_name].data_type;
					data_type_y = resultSet.resultsRows[i][y_series_name].data_type;
					var item =[this.formatValue(data_type_x, resultSet.resultsRows[i][x_series_name].value), this.formatValue(data_type_y, resultSet.resultsRows[i][y_series_name].value)];
					data.push(item);
				}
				//alert(this.resultSet.resultsRows[i][series[ii].x].value);
				var l = resultSet.getMetricLabel(y_series_name);
				dataseries.push({ label: l,  data: data});
				
			}
			
			//if ( ! this.init ) {
				OWA.debug('ac init not set');
				this.setupAreaChart(series, dom_id);
			//}
			
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
				grid: {show: this.options.showGrid, hoverable: true, autoHilight:true, borderWidth:0, borderColor: null},
				series: {
					points: { show: this.options.showDots, fill: this.options.showDots},
					lines: { show: true, fill: true, fillColor: this.options.fillColor, lineWidth: this.options.lineWidth}
					
				},
				colors: this.options.colors,
				legend: {
					position: 'ne',
					margin: [0,-10],
					show:this.options.showLegend
				}
			};
			
			if (data_type_x === 'yyyymmdd') {
				
				options.xaxis.mode = "time";
				//options.xaxis.timeformat = "%m/%d/%y";
				options.xaxis.timeformat = this.options.timeformat;
			}
			
			//this.options.areaChart.flot = options;
			OWA.debug('Plotting area graph in ' + selector);
			var selector_dom = jQuery(selector);
			jQuery.plot(selector_dom, dataseries, options);
				
			this.init = true;		
			
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

	
	setupAreaChart : function(series, dom_id) {
		
		dom_id = dom_id || this.dom_id;
		
		var that = this;
		
		//var w = this.getContainerWidth();
		var w = jQuery("#"+dom_id).css('width');
		//alert(w);
		var h = this.getContainerHeight() || this.getOption('height');
		//var h = this.getOption('height');
		
		jQuery("#"+dom_id).html('<div class="owa_areaChart"></div>');
		
		jQuery(that.domSelector).css('width', this.getOption('width'));
		jQuery(that.domSelector).css('height', h);
		
		// binds a tooltip to plot points
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
                    
                    that.showTooltip(item.pageX -75, item.pageY -50,
                                x+'<BR><B>'+item.series.label + ":</B> " + y);
                }
            } else {
                jQuery("#tooltip").remove();
                previousPoint = null;            
            }
		});  
	}	
}