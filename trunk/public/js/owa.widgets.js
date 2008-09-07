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
	
	_makeUrl: function(values) {},
			
	changeView: function(view) {
			
			var widgetcontentid = "#"+this.name+"_widget-content";
			
			this.properties.format = view;
			
			jQuery(widgetcontentid).slideUp("fast"); 
				 
			jQuery.ajax({ 
				method: "get",
				url: this.config.action_url,
				data: OWA.util.nsAll(this.properties), 
				//show loading just when link is clicked 
				beforeSend: function(){ jQuery(".widget-status").show("fast");}, 
				//stop showing loading when the process is complete 
				complete: function(){ jQuery(".widget-status").hide("fast");}, 
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
	jQuery('.widget-control').click(owa_widget_changeView);
});


// Event handler for changeing views
function owa_widget_changeView() {

	var view = jQuery(this).attr("name");
	var parentname = jQuery("div").parent(".widget-container").attr("id");
	//var widgetname2 = parentname.split("_");
	var widgetname = parentname;
	//alert(parentname);
	// add to state object
	OWA.items[widgetname].name = widgetname;
	OWA.items[widgetname].changeView(view);

	return;
}

