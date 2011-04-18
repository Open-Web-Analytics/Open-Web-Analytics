OWA.report = function(dom_id) {
	
	this.dom_id = dom_id;
	this.config = OWA.config;
	this.properties = {};
	this.tabs = {};
	this.timePeriodControl = '';
}

OWA.report.prototype = {

	options: {},
	
	config: '',
	
	displayTimePeriodPicker : function(dom_id) {
		
		dom_id = dom_id || '#owa_reportPeriodLabelContainer';
		
		if ( ! this.timePeriodControl ) {
			this.timePeriodControl = new OWA.report.timePeriodControl(dom_id, {startDate: this.getStartDate(), endDate: this.getEndDate() } );
		}
	},
	
	showSiteFilter : function(dom_id) {
		
		// create dom elements
		// ...
		
		// bind event handlers
		var that = this;
		jQuery('#owa_reportPeriodFilter').change( function() { that.reportSetTimePeriod(); } );
		jQuery('#owa_reportSiteFilterSelect').change( function() { that.reload(); } );
		jQuery("#owa_reportPeriodFilterSubmit").click( function() { that.reload(); } );
		jQuery('#' + this.dom_id).bind('owa_reportPeriodChange', function() {that.setDateRange(); } );
	},
	
	reportSetTimePeriod : function() {

		var period = jQuery("#owa_reportPeriodFilter option:selected").val();
		this.setPeriod(period);
		this.reload();
	},
	
	reload: function() {
	
		// add new site_id to properties
		var siteId = jQuery("#owa_reportSiteFilterSelect option:selected").val(); 
		OWA.debug(this.properties['action']);
		this.properties['siteId'] = siteId;
		// reload report	
		var url = OWA.util.makeUrl(OWA.config.link_template, OWA.config.main_url, this.properties);
		window.location.href = url;
	},
		
	_parseDate: function (date) {
		
		
	},
	
	setDateRange: function (date) {
		
		this.setProperty( 'startDate', 
			jQuery.datepicker.formatDate(
				'yymmdd', 
				jQuery("#owa_report-datepicker-start").datepicker( "getDate" ) 
			) 
		);
		
		this.setProperty( 'endDate', 
			jQuery.datepicker.formatDate(
				'yymmdd', 
				jQuery("#owa_report-datepicker-end").datepicker( "getDate" ) 
			) 
		);
		
		this.removeProperty('period');
	},
	
	setPeriod: function(period) {
	
		this.properties.period = period;
		
		if ( this.properties.hasOwnProperty( 'startDate' ) ) {
			delete this.properties[ 'startDate' ];
		}
		
		if ( this.properties.hasOwnProperty( 'endDate' ) ) {
			delete this.properties[ 'endDate' ];
		}
	},
	
	addTab : function(obj) {
	
		if (obj.dom_id.length > 0 ) {
			this.tabs[obj.dom_id] = obj;
		} else {
			OWA.debug('tab cannot be added with no dom_id set.');
		}
	},
	
	createTabs : function() {
		
		var that = this;
		
		jQuery("#report-tabs").prepend('<ul class="report-tabs-nav-list"></ul>');
		for (tab in this.tabs) {
	
			if ( this.tabs.hasOwnProperty(tab) ) {	
				jQuery("#report-tabs > .report-tabs-nav-list").append(OWA.util.sprintf( '<li><a href="#%s">%s</a></li>', tab, that.tabs[tab].label ) );
				
			}
		}
		
		jQuery("#report-tabs").tabs({
			show: function(event, ui) {
				OWA.debug('tab selected is: %s', ui.panel.id);
				that.tabs[ui.panel.id].load();
			}
		});

	},
	
	getSiteId : function() {
		
		return this.getProperty('siteId');
	},
	
	getPeriod : function() {
		
		return this.getProperty('period');
	},
	
	getStartDate : function() {
	
		return this.getProperty('startDate');
	},
	
	getEndDate : function() {
	
		return this.getProperty('endDate');
	},
	
	setRequestProperty : function(name, value) {
		
		this.setProperty(name, value);
	},
	
	setProperty : function (name, value) {
		
		this.properties[name] = value;
	},
	
	removeProperty : function( name ) {
		
		if ( this.properties.hasOwnProperty( name ) ) {
			
			delete this.properties[name];
		}
	},
	
	getProperty : function ( name ) {
		
		if ( this.properties.hasOwnProperty( name ) ) {
			
			return this.properties[name];
		}
	}
}

OWA.report.tab = function(dom_id) {
	this.dom_id = dom_id;
	this.resultSetExplorers = {};
	this.label = 'Default label';
	this.isLoaded = false;
	this.load = function() {
		if ( ! this.isLoaded ) {
			for (rse in this.resultSetExplorers) {
				
				if (this.resultSetExplorers.hasOwnProperty(rse)) {
			
					this.resultSetExplorers[rse].load();
				}
				
			}
			
			this.isLoaded = true;
		}
	}
}

OWA.report.tab.prototype = {
	
	addRse : function (name, rse) {
		
		this.resultSetExplorers[name] = rse;
	},
	
	setLabel : function (label) {
		this.label = label;
	},
	
	setDomId : function (dom_id) {
		this.dom_id = dom_id;
	}
}

OWA.report.timePeriodControl = function( dom_id, options ) {
	
	var options = options || {};
	this.dom_id = dom_id || 'owa_reportPeriodControl';
	this.startDate = '';
	this.endDate = '';
	this.label = '';
	
	if (options.hasOwnProperty('startDate')) {
		this.setStartDate( options.startDate );
	}
	
	if (options.hasOwnProperty('endDate')) {
		this.setEndDate( options.endDate );
	}

	if (OWA.isJsLoaded( 'jquery-ui') ) {
	
		OWA.requireJs( 
			'jquery-ui', 
			OWA.getOption('modules_url') + 'base/js/includes/jquery/jquery-ui-1.8.1.custom.min.js', 
			this.setupDomElements() 
		);
		
	} else {
			this.setupDomElements();
	}
};

OWA.report.timePeriodControl.prototype = {
	
	fixedPeriods: {
	
		today:					'Today',
		yesterday: 				'Yesterday',
		this_week: 				'This Week',
		this_month: 			'This Month',
		this_year: 				'This Year',
		last_week: 				'Last Week',
		last_month:				'Last Month',
		last_year:				'Last Year',
		last_seven_days:		'Last Seven Days',
		last_thirty_days:		'Last Thirty Days',
		same_day_last_week:		'Same Day Last Week',
		same_week_last_year: 	'Same Week Last Year',
		same_month_last_year:	'Same Month Last Year'
	},
	
	formatYyyymmdd : function( yyyymmdd ) {
		
		var year = yyyymmdd.substr(2,2);
		var month = yyyymmdd.substr(4,2);
		var day = yyyymmdd.substr(6,2);
		
		return month + '-' + day + '-' + year;
	},
	
	setStartDate : function( yyyymmdd ) {
		
		this.startDate = yyyymmdd;
	},
	
	setEndDate : function( yyyymmdd ) {
		
		this.endDate = yyyymmdd;
	},
	
	getStartDate : function() {
		
		return this.startDate;
	},
	
	getEndDate : function() {
		
		return this.endDate;
	},
	
	setupDomElements : function() {
		
		var that = this;
		//inject dom elements
		
		// register event handlers
		jQuery("#owa_reportPeriodLabelContainer").click(function() {
			jQuery("#owa_reportPeriodFiltersContainer").toggle();
		});
		
		/*
var dates = jQuery("#owa_report-datepicker-start, #owa_report-datepicker-end").datepicker({
		
			dateFormat: 'mm-dd-yy',
			onSelect: function( selectedDate ) {
			
				var option = this.id == "#owa_report-datepicker-end" ? "minDate" : "maxDate",
					instance = jQuery( this ).data( "datepicker" ),
					date = jQuery.datepicker.parseDate(
						instance.settings.dateFormat ||
						jQuery.datepicker._defaults.dateFormat,
						selectedDate, instance.settings );
						
				if (this.id === "#owa_report-datepicker-end" ) {
					//jQuery("#owa_report-datepicker-start").datepicker( "option", 'maxDate', jQuery("#owa_report-datepicker-end").datepicker( "getDate" ) );
					jQuery("#owa_report-datepicker-start").datepicker( "option", 'minDate', '' );
					
				} else {
					alert('hi');
					var start_date = jQuery("#owa_report-datepicker-start").datepicker( "getDate" );
					jQuery("#owa_report-datepicker-start").datepicker( "option", 'maxDate', date );
					jQuery("#owa_report-datepicker-end").datepicker( "option", 'minDate', start_date);
					//jQuery("#owa_report-datepicker-start").datepicker( "option", 'minDate', 0 );
				}
				//jQuery("#owa_report-datepicker-end").datepicker( "option", option, date );
				
				jQuery.event.trigger('owa_reportPeriodChange');
			}
		});
		
		jQuery("#owa_report-datepicker-start").datepicker( "option", "altField", '#owa_report-datepicker-start-display');
		jQuery("#owa_report-datepicker-end").datepicker( "option", "altField", '#owa_report-datepicker-end-display');
		
		jQuery("#owa_report-datepicker-start").datepicker( "setDate", that.formatYyyymmdd( that.getStartDate() ) );
		jQuery("#owa_report-datepicker-end").datepicker( "setDate", that.formatYyyymmdd( that.getEndDate() ) );
		
*/
		
		jQuery("#owa_report-datepicker-start").datepicker({
			
			//showOn: "both", 
			//maxDate: 0,
			dateFormat: 'mm-dd-yy',
			altField: "#owa_report-datepicker-start-display",
			onSelect: function(selectedDate) { 
				
				// parse date
				var instance = jQuery( this ).data( "datepicker" );
				var date = jQuery.datepicker.parseDate(
						instance.settings.dateFormat ||
						jQuery.datepicker._defaults.dateFormat,
						selectedDate, instance.settings );
				
				// constrain min date
				jQuery("#owa_report-datepicker-end").datepicker( "option", 'minDate', date);		
				// constrain new max date using value from end date picker
				jQuery("#owa_report-datepicker-start").datepicker( "option", 'maxDate',
					jQuery("#owa_report-datepicker-end").datepicker( "getDate" )	
				
				);
				// trigger change event
				jQuery.event.trigger('owa_reportPeriodChange'); 
				
			},
			defaultDate: that.formatYyyymmdd( that.getStartDate() )
		});	
		
		jQuery("#owa_report-datepicker-end").datepicker({
			
			//showOn: "both", 
			//maxDate: 0,
			dateFormat: 'mm-dd-yy',
			//minDate: min_date,
			altField: "#owa_report-datepicker-end-display",
			onSelect: function(selectedDate) { 
			
				// parse date
				var instance = jQuery( this ).data( "datepicker" );
				var date = jQuery.datepicker.parseDate(
						instance.settings.dateFormat ||
						jQuery.datepicker._defaults.dateFormat,
						selectedDate, instance.settings );
				
				// constrain min date using value from start date picker
				jQuery("#owa_report-datepicker-end").datepicker( "option", 'minDate', 
					jQuery("#owa_report-datepicker-start").datepicker( "getDate" )
				
				);		
				// constrain new max date 
				jQuery("#owa_report-datepicker-start").datepicker( "option", 'maxDate', date);
				// trigger change event
				jQuery.event.trigger('owa_reportPeriodChange'); 
			},
			defaultDate: that.formatYyyymmdd( that.getEndDate() )
		});	
		
		// set hilighting
		//jQuery("#owa_report-datepicker-end").datepicker( "option", "onSelect", min_date);
		

	},
	
	customRange : function (input) {

		return {minDate: (input.id == "owa_report-datepicker-end-display" ? jQuery("#owa_report-datepicker-start").datepicker("getDate") : null), 
        	maxDate: (input.id == "owa_report-datepicker-start-display" ? jQuery("#owa_report-datepicker-end").datepicker("getDate") : null)}; 
	
	}
};

// Bind event handlers
jQuery(document).ready(function(){   
	
	// report side navigaion panels - toggle
	jQuery('.owa_admin_nav_topmenu_toggle').click(function () { 
      jQuery(this).parent().siblings('.owa_admin_nav_subgroup').toggle(); 
    });
});

//depricated
function customRange(input) {

	return {minDate: (input.id == "owa_report-datepicker-end" ? jQuery("#owa_report-datepicker-start").datepicker("getDate") : null), 
        maxDate: (input.id == "owa_report-datepicker-start" ? jQuery("#owa_report-datepicker-end").datepicker("getDate") : null)}; 
	
}