OWA.map = function() {
	
	return;	
}

OWA.map.prototype = {

	markers: new Object,
	
	config: '',
	
	dom_id: 'map',
		
	height: "100%",
	
	width: "100%",
	
	mapType: '',
	
	placeMarkers: function() {
	
		var lvmarkers = this.markers;
		var dom_id = this.dom_id;
		var mType = this.getMapSettings();
		
		
		jQuery(document).ready(function(){
			
			jQuery('#'+ dom_id).jmap('init', mType);
			
			for(k in lvmarkers) {
				
				jQuery('#'+ dom_id).jmap('AddMarker', lvmarkers[k]);
					
			}
		
		});
	
		return;
	
	},
	
	getMapSettings: function() {
	
		switch(this.mapType) {
		
			case 'earth':
				return {'mapType': G_SATELLITE_3D_MAP,'mapZoom': 3,'mapCenter':[30.958639, -90.162516], 'mapShowjMapsIcon': false, 'mapEnableType': true, 'mapEnableOverview': true};

				break;
				
			default:
				
				return {'mapType': G_NORMAL_MAP,'mapZoom': 2,'mapCenter':[8.958639, -3.162516], 'mapShowjMapsIcon': false, 'mapEnableType': true, 'mapEnableOverview': true};

		
		}
		
	},
	
	reloadMap: function(t) {
		
		this.mapType = t;
		this.placeMarkers();
		return;
	}
		
}

// Bind event handlers
jQuery(document).ready(function(){  
	//jQuery.getScript(OWA.config.js_url + "includes/jquery/tablesorter/jquery.tablesorter.js");	 
	jQuery('.owa_map-type-control').click(owa_map_changeView);
	
});

function owa_map_changeView() {

	// get the map id
	var dom_id = jQuery(this).siblings('.jmap').get(0).id;
	var type = jQuery(this).attr('maptype');
	OWA.items[dom_id].reloadMap(type);
	return;

}



