OWA.player = function() {
	
	this.animate_interval = 150;
	this.showPlayerControls();
	
}

OWA.player.prototype = {
	
	timer : null,
	queue_step : 1,
	queue_count : 0,
	animateInterval : 150,
	stream : null,
	lock : false,
	
	block : function() {
		this.lock = true;
	},
	
	unblock : function() {
		this.lock = false;
	},
	
	load : function(data) {
		this.stream = data;
	},
	
	moveCursor : function(x, y) {
		this.block();
		jQuery('#cursor').animate({top: y +'px', left: x +'px'}, { queue:true, duration:100}, 'swing', this.unblock());	
		//console.log("Moving to X: %s Y: %s", x, y);
		this.setStatus("Mouse Movement to: "+x+", "+y);
	},
	
	scrollViewport : function(x, y) {
	
		//jQuery('html, body').animate({scrollTop: y}, 0);
		window.scroll(0,y)
		//console.log("Scrolling to Y: %s", y);
		this.setStatus("Scrolling to: "+ y);
	},
	
	start : function() {
		
		var that = this;
		this.timer = setInterval(function(){that.step()}, this.animateInterval);
	},
	
	step : function() {
		
		if (this.lock) {
			//console.log("i am locked");
			return;
		}
		
  		if ((this.queue_count > 0) && (this.queue_step >= this.queue_count)) {
    		this.stop();
  		} else {
  			// get the next event in the queue
  			var event = this.getNextEvent();
			// trigger dom stream events 			
 			//jQuery().trigger(event.event_type, [event]);
 			this.playEvent(event.event_type, event);
     	}
	},
	
	playEvent : function(event_type, event) {
		
		switch (event_type) {
			case 'dom.movement':
				return this.movementEventHandler(event);
			case 'dom.scroll':
				return this.scrollEventHandler(event);
			case 'dom.keypress':
				return this.keypressEventHandler(event);
			case 'dom.click':
				return this.clickEventHandler(event);
		}
	},
	
	stop : function() {
	
		if (!this.timer) return false;
	  	clearInterval(this.timer);
	},
	
	play : function() {
		
		this.queue_count = this.stream.length;
		this.start();
	},
	
	getNextEvent : function() {
		
		var event = this.stream[this.queue_step];
		// increment the queue step
    	this.queue_step++;
    	return event;
	},
	
	showPlayerControls : function() {
	
		var player = '<div id="owa_player" style="position:fixed; top:0px; width:400px;right:0px; border:1px; background-color:#efefef; opacity:0.5; z-index:100;"></div>';
		var startlink = '<li id="owa_player_start">Start</li>';
		var pauselink = '<li id="owa_player_pause">Pause</li>';
		var status_msg = '<li><textarea id="owa_player_status" style="width:270px;" rows="1"></textarea></li>';
		var cursor = '<div id="cursor" style="position:absolute; z-index:99;"><img src="'+OWA.config.images_url+'base/i/cursor.png"></div>';
		jQuery('body').append(player);
		jQuery('body').append(cursor);
		var latest_click_class = {'background-color': 'red', 
								  'padding': '5px', 
								  'color': 'white',
								  'display': 'none',
								  'font-weight': 'bold',
								  'position': 'absolute'}
		jQuery('html, body').append('<div id="owa-latest-click" style="display: none">*CLICK*</div>');
		jQuery('#owa-latest-click').css(latest_click_class);
		var that = this;
		jQuery('#owa_player').append("<ul>")
		jQuery('#owa_player').append(startlink);
		jQuery('#owa_player').append(pauselink);
		jQuery('#owa_player').append(status_msg);
		jQuery('#owa_player').append("</ul>")
		jQuery('#owa_player ul').css({'list-style-type': 'none', 'text-align' : 'left'});
		jQuery('#owa_player li').css({'display' : 'inline', 'padding': '10px'});
		jQuery('#owa_player_start').bind('click', function(e) {that.play(e)});
	},
	
	setStatus : function(msg) {
		
		jQuery('#owa_player_status').prepend(msg+'\n');
		
	},
	
	movementEventHandler : function(e) {
		
		return this.moveCursor(e.cursor_x, e.cursor_y);
	},
	
	scrollEventHandler : function(e) {
		
		this.scrollViewport(e.x, e.y);
	},
	
	keypressEventHandler : function(event) {
		
		if (event.dom_element_id != "" || undefined) { 
			var accessor = '#'+event.dom_element_id; 
		} else if (event.dom_element_name) {
			var accessor = event.dom_element_tag+"[name="+event.dom_element_name+"]";
			//console.log("accessor: %s", accessor); 
		}
	
		var element_value = jQuery(accessor).val() || '';
		element_value += event.key_value; 
		jQuery(accessor).val(element_value);
	},
	
	clickEventHandler : function(event) {
	
		if (event.dom_element_id != "" || undefined) { 
			var accessor = '#'+event.dom_element_id; 
		} else if (event.dom_element_name) {
			var accessor = event.dom_element_tag+"[name="+event.dom_element_name+"]";
			//console.log("accessor: %s", accessor); 
		} else if(event.dom_element_class) {
			var accessor = event.dom_element_tag+"."+event.dom_element_class;
		} else {
			var accessor = event.dom_element_tag;
		}
		
		jQuery(accessor).click();
		jQuery('#owa-latest-click').css({'left': event.dom_element_x-10+'px', 'top': event.dom_element_y-10+'px'});
		jQuery('#owa-latest-click').slideToggle('normal');
		jQuery('#owa-latest-click').slideToggle('normal');
		//console.log("Clicking: %s", accessor);
		this.setStatus("Clicking: "+accessor);
	
	}

}