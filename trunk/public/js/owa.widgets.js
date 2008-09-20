// OWA Widgets

OWA.widget = function() {

	this.config = OWA.config;
	
	return;	
}

OWA.widget.prototype = {

	properties: new Object,
	
	config: '',
	
	dom_id: '',
	
	name: '',
	
	current_view: '',
	
	minimized: false,
	
	_makeUrl: function(values) {},
			
	changeView: function(view) {
			
			var widgetcontentid = "#"+this.dom_id+"_widget-content";
			
			this.properties.format = view;
			
			jQuery(widgetcontentid).slideUp("fast"); 
				 
			jQuery.ajax({ 
				method: "get",
				url: this.config.action_url,
				data: OWA.util.nsAll(this.properties), 
				//show loading just when link is clicked 
				beforeSend: function(){ jQuery(".owa_widget-status").show("slow");}, 
				//stop showing loading when the process is complete 
				complete: function(){ jQuery(".owa_widget-status").hide("slow");}, 
				//so, if data is retrieved, store it in html
				success: function(html){  
					jQuery(widgetcontentid).show("slow"); //animation 
					jQuery(widgetcontentid).html(html); //show the html inside .content div 
					this.current_view = view;
					
				} 
			}); //close $.ajax( 
				
			return true;
		
	}

}

// Bind event handlers
jQuery(document).ready(function(){   
	jQuery('.owa_widget-control').click(owa_widget_changeView);
	jQuery('.owa_widget-close').click(owa_widget_close);
	jQuery('.owa_widget-status').hide("slow");
	jQuery('.owa_widget-toggle').click(owa_widget_toggle);
});

// Event handler for changeing views
function owa_widget_changeView() {

	var view = jQuery(this).attr("name");
	var widgetname = jQuery("div").parent(".owa_widget-container").attr("id");	
	return OWA.items[widgetname].changeView(view);
	
}

function owa_widget_close() {
	
	return jQuery("div").parent(".owa_widget-container").hide("slow");	
}

function owa_widget_toggle() {

	jQuery("div").parent(".owa_widget-innercontainer").toggle("slow");
	jQuery(this).html("Show");
	var widgetname = jQuery("div").parent(".owa_widget-container").attr("id");	
	OWA.items[widgetname].minimized = true;

	return;
}

