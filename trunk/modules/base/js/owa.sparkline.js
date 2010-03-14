OWA.sparkline = function() {

	this.config = OWA.config;
	
	return;	
}

OWA.sparkline.prototype = {

	properties: new Object,
	
	config: '',
	
	dom_id: '',
	
	data: '',
	
	height: "50px",
	
	width: "125px",
	
	options: {width: '125px', height: '20px', spotRadius: 2, lineColor: '#ffffff', fillColor: ''},
	
	render: function() {
		
		 jQuery('#' + this.dom_id).sparkline('html', this.options);
	},
		
	setHeight: function(height) {
		
		this.height = height;
		return;
	},
	
	setWidth: function(width) {
		
		this.width = width;
		return;
	},
	
	setDomId: function(dom_id) {
		
		this.dom_id = dom_id;
		return;
	}
	
}
