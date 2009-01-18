OWA.report = function() {

	this.config = OWA.config;
	
	return;	
}

OWA.report.prototype = {

	properties: new Object,
	
	config: '',
	
	dom_id: '',
	
	reload: function() {
		
		var url = OWA.util.makeUrl(OWA.config.link_template, OWA.config.main_url, this.properties);
		window.location.href = url;
		return; 
	},
		
	_parseDate: function (date) {
		
		
	},
	
	setDateRange: function (date) {
		
		this.properties.startDate = jQuery.datepicker.formatDate('yymmdd', jQuery("#owa_report-datepicker-start").datepicker("getDate"));
		this.properties.endDate = jQuery.datepicker.formatDate('yymmdd', jQuery("#owa_report-datepicker-end").datepicker("getDate"));
		if (this.properties.startDate != null && this.properties.endDate != null) {
			this.setPeriod('date_range');
		}
		
		return;
		
	},
	
	setPeriod: function(period) {
	
		this.properties.period = period;
		return;
	}
	

}

// Bind event handlers
jQuery(document).ready(function(){   
	jQuery('#owa_reportSiteFilterSelect').change(owa_report_reload);
	jQuery("#owa_reportPeriodFilterSubmit").click(owa_report_reload);
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
	jQuery.tablesorter.defaults.widgets = ['zebra'];
	jQuery('.tablesorter').tablesorter();
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
	
	return;
}

function owa_reportSetPeriod() {

	var period = jQuery("#owa_reportPeriodFilter option:selected").val();
	var reportname = jQuery(this).parents(".owa_reportContainer").get(0).id;
	OWA.items[reportname].setPeriod(period);
	OWA.items[reportname].reload();
	
	return;

}

function owa_report_reload() {

	// add new site_id to properties
	var site_id = jQuery("#owa_reportSiteFilterSelect option:selected").val(); 
	//alert(site_id);
	
	// reload the report
	var reportname = jQuery(this).parents(".owa_reportContainer").get(0).id;
	//alert(reportname);
	OWA.items[reportname].properties.site_id = site_id;
	OWA.items[reportname].reload();
	
	return;

}