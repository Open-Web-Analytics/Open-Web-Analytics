OWA.spy = function() {

	//this.config = OWA.config;
	
	return;	
}

OWA.spy.prototype = {

	properties: new Object,
	last_start_time: '',
	last_end_time: 0,

	init: function(dom_id, url) {
		
	  	jQuery('#'+ dom_id).spy({
	    'limit': 10,
	    'fadeLast': 5,
	    'ajax': url,
	    'fadeInSpeed': '500',
	    'timeout': 5000
	    //'timestamp': owa_getNow
	     }); 
	
	},
	
	getStartTime: function() {
  
		var d = new Date();
		var ts = '';
	     
		if (this.last_end_time > 0) {
	  
	  		ts = this.last_end_time;
	  		this.last_end_time = this.getNow();
	  	} else {
	  
	  		ts = this.getNow();
	  		this.last_end_time = ts;
	  	}
	  
		return ts;
	  
	},

	getNow: function() {
	
		var d = new Date();
		var now;
		now = Math.round(d.getTime() / 1000);
	
		return now;

	}
	
	
}


function pauseSpy() {
	spyRunning = 0; 
	var temp_time;
	last_end_time = temp_time;
	jQuery('div#_spyTmp').html("");
	jQuery('div#spyContainer').prepend('<div class="status">The spy has been paused...</div>');

	return false;
}

function playSpy() {
	spyRunning = 1; 
	jQuery('div#spyContainer').prepend('<div class="status">The spy has been re-started...</div>');
	return false;
}

function owa_getData() {
	
	spy.properties.startTime = spy.getStartTime();
	spy.properties.endTime = spy.getNow();
	//alert(OWA.util.nsAll(spy.properties));
	return OWA.util.nsAll(spy.properties);
		

	}
