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
	
	page_num: 1,
	
	max_page_num: 2,
	
	more_pages: false,
	
	minimized: false,
	
	displayPagination: function() {
	
		var widget_pagination_div = "#"+this.dom_id+"_widget-pagination";
		var pages = this._makePagination();
		jQuery(widget_pagination_div).show("fast");
		jQuery(widget_pagination_div).html(pages);
		jQuery('.owa_widget-paginationcontrol').click(owa_widget_page_results);
		
		return;
	},
	
		
	hidePagination: function() {
	
		var widget_pagination_div = "#"+this.dom_id+"_widget-pagination";
		jQuery(widget_pagination_div).hide();
		
		return;
	},
			
	changeView: function(view) {
			
			var widgetcontentid = "#"+this.dom_id+"_widget-content";
			
			this.properties.format = view;
			
			jQuery(widgetcontentid).slideUp("fast"); 
				 
			jQuery.ajax({ 
				method: "get",
				url: this.config.action_url,
				data: OWA.util.nsAll(this.properties), 
				//show loading just when link is clicked 
				beforeSend: function(){ jQuery("#"+this.dom_id).children(".owa_widget-status").show("slow");}, 
				//stop showing loading when the process is complete 
				complete: function(){ jQuery("#"+this.dom_id).children(".owa_widget-status").hide("slow");}, 
				//so, if data is retrieved, store it in html
				success: function(html){  
					jQuery(widgetcontentid).show("slow"); //animation 
					jQuery(widgetcontentid).html(html); //show the html inside .content div 
					
					
					
					
				} 
			}); //close $.ajax( 
			
			this.current_view = view;
			
			if (view == "table") {
				this.displayPagination();
			} else {
				this.hidePagination();
			}
			
			return true;
		
	},
	
	_makePagination: function() {
		
		var pagination = '';
		var anchor = this._makeLinkAnchor();
		
		// previous nav link
		if (this.page_num > 1) {
			pagination = 'Pages: ';
			pagination = pagination + this._makePaginationLink(anchor, "<< Previous") + ' ... ';
		}
		
		for (i = 1; i <= this.max_page_num; i++) {
			
			// let's pick a page name for our link
			var page_name = i;
		
			// to link or not to link
			if (i == this.page_num) {
				pagination = pagination + page_name;
			} else {
				pagination = pagination + this._makePaginationLink(anchor, page_name);
			}
			
			// add commas
			if (i != this.max_page_num) {
				pagination = pagination + ', ';
			}

		}
		
		// previous nav link
		if (this.page_num < this.max_page_num) {
			if (this.more_pages == true) {
				pagination = pagination + this._makePaginationLink(anchor, "Next >>") + ' ... ';
			}
		}
		
        
        return pagination;
       	
	},
	
	_makePaginationLink: function(anchor, page_name) {
		
		var link = '<a href="' + anchor + '" class="owa_widget-paginationcontrol">' + page_name + '</a>';
		return link;
	},
	
	_makeLinkAnchor: function() {
		
		return anchor = "#"+this.dom_id+"_widget-header";
	}
	
	

}

// Bind event handlers
jQuery(document).ready(function(){  
	//jQuery.getScript(OWA.config.js_url + "includes/jquery/tablesorter/jquery.tablesorter.js");	 
	jQuery('.owa_widget-control').click(owa_widget_changeView);
	jQuery('.owa_widget-pagecontrol').click(owa_widget_changeView);
	jQuery('.owa_widget-close').click(owa_widget_close);
	jQuery('.owa_widget-status').hide("slow");
	jQuery('.owa_widget-collapsetoggle').click(owa_widget_toggle);
	jQuery('.owa_widget-paginationcontrol').click(owa_widget_page_results);
	
});


// Event handler for changeing views
function owa_widget_changeView() {

	var view = jQuery(this).attr("name");
	var widgetname = jQuery(this).parents(".owa_widget-container").get(0).id;	
	//alert(widgetname);
	return OWA.items[widgetname].changeView(view);
	
}

function owa_widget_close() {
	
	return jQuery(this).parents(".owa_widget-container").hide("slow");	
}

function owa_widget_toggle() {
	
	// get the widget name
	var widgetname = jQuery(this).parents(".owa_widget-container").get(0).id;
	
	// toggle the visibility of the child element
	jQuery('#'+widgetname).children(".owa_widget-innercontainer").toggle("slow");
	
	if (OWA.items[widgetname].minimized != true) {
		// set property on widget object
		OWA.items[widgetname].minimized = true;
		//change the text of the link to reflect new state
		jQuery(this).html("Show");
	} else {
		// set property on widget object
		OWA.items[widgetname].minimized = false;
		//change the text of the link to reflect new state
		jQuery(this).html("Minimize");
	}
	
	return;
}

function owa_widget_page_results() {
	var page = jQuery(this).text();
	alert(page);
	var widgetname = jQuery(this).parents(".owa_widget-container").get(0).id;
	OWA.items[widgetname].page_num = page;
	OWA.items[widgetname].properties.page = page;
	var view = OWA.items[widgetname].current_view;
	return OWA.items[widgetname].changeView(view);

}


