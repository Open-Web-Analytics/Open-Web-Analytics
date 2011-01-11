OWA.report = function(dom_id) {
	
	this.dom_id = dom_id;
	this.config = OWA.config;
	this.properties = {};
	this.tabs = {};	
}

OWA.report.prototype = {

	options: {},
	
	config: '',
	
	showSiteFilter : function(dom_id) {
		
		// create dom elements
		// ...
		
		// bind event handlers
		that = this;
		jQuery('#owa_reportSiteFilterSelect').change( function() { that.reload(); } );
		jQuery("#owa_reportPeriodFilterSubmit").click( function() { that.reload(); } );
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
	
	setRequestProperty : function(name, value) {
		
		this.properties[name] = value;
	},
		
	_parseDate: function (date) {
		
		
	},
	
	setDateRange: function (date) {
		
		this.properties.startDate = jQuery.datepicker.formatDate('yymmdd', jQuery("#owa_report-datepicker-start").datepicker("getDate"));
		this.properties.endDate = jQuery.datepicker.formatDate('yymmdd', jQuery("#owa_report-datepicker-end").datepicker("getDate"));
		if (this.properties.startDate != null && this.properties.endDate != null) {
			this.setPeriod('date_range');
		}
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
		
		if (this.properties.hasOwnProperty('siteId')) {
			
			return this.properties.siteId;
		}
	},
	
	getPeriod : function() {
		
		if (this.properties.hasOwnProperty('period')) {
			
			return this.properties.period;
		}
	},
	
	getStartDate : function() {
	
		if (this.properties.hasOwnProperty('startDate')) {
			
			return this.properties.startDate;
		}
	
	},
	
	getEndDate : function() {
	
		if (this.properties.hasOwnProperty('endDate')) {
			
			return this.properties.endDate;
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

// Bind event handlers
jQuery(document).ready(function(){   
	
	jQuery('#owa_reportPeriodFilter').change(owa_reportSetPeriod);
	jQuery("#owa_reportPeriodLabelContainer").click(function() { 
		jQuery("#owa_reportPeriodFiltersContainer").toggle();
	});
	jQuery("#owa_report-datepicker-start, #owa_report-datepicker-end").datepicker({
		beforeShow: customRange,  
		showOn: "both", 
		dateFormat: 'mm-dd-yy',
		onSelect: function(date) {owa_reportSetDateRange(date);}
	
		//buttonImage: "templates/images/calendar.gif", 
		//buttonImageOnly: true
	});	
	// make tables sortable
	//jQuery.tablesorter.defaults.widgets = ['zebra'];
	//jQuery('.tablesorter').tablesorter();
	// report side navigaion panels - toggle
	jQuery('.owa_admin_nav_topmenu_toggle').click(function () { 
      jQuery(this).parent().siblings('.owa_admin_nav_subgroup').toggle(); 
    });
});


function customRange(input) {

	return {minDate: (input.id == "owa_report-datepicker-end" ? jQuery("#owa_report-datepicker-start").datepicker("getDate") : null), 
        maxDate: (input.id == "owa_report-datepicker-start" ? jQuery("#owa_report-datepicker-end").datepicker("getDate") : null)}; 
	
}

function owa_reportSetDateRange(date) {

	if (date != null) {
		var reportname = jQuery('.owa_reportContainer').get(0).id;
		OWA.items[reportname].setDateRange();
		OWA.items[reportname].setPeriod('date_range');
		// toggle the drop down to custom data range label
		jQuery("#owa_reportPeriodFilter option:contains('Custom Date Range')").attr("selected", true);	
	}
}

function owa_reportSetPeriod() {

	var period = jQuery("#owa_reportPeriodFilter option:selected").val();
	var reportname = jQuery(this).parents(".owa_reportContainer").get(0).id;
	OWA.items[reportname].setPeriod(period);
	OWA.items[reportname].reload();
}