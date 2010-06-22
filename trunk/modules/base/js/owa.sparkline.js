OWA.sparkline = function(dom_id) {

	this.config = OWA.config || '';
	
	this.dom_id = dom_id || '';
	
	this.data = '';

	this.options = {
		type: 'line',
		lineWidth: 2,
		width: '100px', 
		height: '20px', 
		spotRadius: 0, 
		//lineColor: '', 
		//spotColor: '',
		minSpotColor: '#FF0000',
		maxSpotColor: '#00FF00'
	};
	
}

OWA.sparkline.prototype = {
	
	render: function() {
		
		 jQuery('#' + this.dom_id).sparkline('html', this.options);
	},
	
	loadFromArray :function(data) {
		jQuery('#' + this.dom_id).sparkline(data, this.options);
	},
		
	setHeight: function(height) {
		
		this.options.height = height;
		return;
	},
	
	setWidth: function(width) {
		
		this.options.width = width;
	},
	
	setDomId: function(dom_id) {
		
		this.dom_id = dom_id;
	}
	
}
