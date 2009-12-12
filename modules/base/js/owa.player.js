//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2010 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//

/**
 * Javascript Domstream Player Library
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @web			<a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.2.1
 */

OWA.player = function() {
	
	OWA.util.loadScript(OWA.getSetting('baseUrl')+'/modules/base/js/includes/jquery/jquery-1.3.2.min.js', function(){});
	OWA.util.loadScript(OWA.getSetting('baseUrl')+'/modules/base/js/includes/jquery/jquery.jgrowl_minimized.js', function(){});
	OWA.util.loadCss(OWA.getSetting('baseUrl')+'/modules/base/css/jquery.jgrowl.css', function(){});
	OWA.util.loadCss(OWA.getSetting('baseUrl')+'/modules/base/css/owa.overlay.css', function(){});
	this.fetchData();
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
		// count the events in the queue
		this.queue_count = this.stream.events.length;
	},
	
	/**
	 * Fetches data via ajax request
	 */
	fetchData: function() {
	
		var p = OWA.util.readCookie('owa_overlay');
		//alert(unescape(p));
		var params = OWA.util.parseCookieStringToJson(p);
		params.action = 'base.getDomstream';
		
		//closure
		var that = this;
		
		jQuery.get(OWA.getApiEndpoint(), OWA.util.nsParams(params), function(data) { that.load(data); }, 'json');
		
		//OWA.debug(data.page);
		return;
	},

	
	moveCursor : function(x, y) {
		this.block();
		jQuery('#owa-cursor').animate({top: y +'px', left: x +'px'}, { queue:true, duration:100}, 'swing', this.unblock());	
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
			OWA.debug("Can not step as player is locked");
			return;
		}
		
		if (this.queue_count === 0) {
			this.stop();
		} else if ((this.queue_count > 0) && (this.queue_step >= this.queue_count)) {
    		this.stop();
  		} else {
  			// get the next event in the queue
  			var event = this.getNextEvent();
			// trigger dom stream events 			
 			//jQuery().trigger(event.event_type, [event]);
 			this.playEvent(event);
     	}
	},
	
	getNextEvent : function() {
		OWA.debug("Queue step is: "+ this.queue_step);
		var event = this.stream.events[this.queue_step];
		OWA.debug("getting event... " + event.event_type);
		// increment the queue step
    	this.queue_step++;
    	return event;
	},
	
	playEvent : function(event) {
		OWA.debug("playing event of type: " + event.event_type);
		switch (event.event_type) {
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
		
		// change control static color
   		jQuery('#owa_overlay_start').removeClass('active');
		if (!this.timer) return false;
	  	clearInterval(this.timer);
	  	
	},
	
	play : function() {
		OWA.debug("Now playing Domstream.");
		
		if ((this.queue_step = this.queue_count)) {
			this.queue_step = 1;
		}
		
		this.start();
	},
	
	showPlayerControls : function() {
	
		var player = '<div id="owa_overlay"></div>';
		var startlink = '<div class="owa_overlay_control" id="owa_player_start">Play</div>';
		var pauselink = '<div class="owa_overlay_control" id="owa_player_stop">Pause</div>';
		var closelink = '<div class="owa_overlay_control" id="owa_player_close">Close</div>';
		var status_msg = '<div id="owa-overlay-status">...</div>';
		var cursor = '<div id="owa-cursor"><img src="'+OWA.getSetting('baseUrl')+'/modules/base/i/cursor2.png"></div>';
		jQuery('body').append(player);
		jQuery('body').append(cursor);
		jQuery('html, body').append('<div id="owa-latest-click" style="display: none">CLICK</div>');
		var that = this;
		jQuery('#owa_overlay').append('<div id="owa_overlay_logo"></div>');
		jQuery('#owa_overlay').append(startlink);
		jQuery('#owa_overlay').append(pauselink);
		jQuery('#owa_overlay').append(closelink);
		jQuery('#owa_overlay').append(status_msg);
		
		jQuery('#owa_overlay_start').toggleClass('active');
		jQuery('.owa_overlay_control').bind('click', function(){
			jQuery(".owa_overlay_control").removeClass('active');
			jQuery(this).addClass('active');
		});
		
		jQuery('#owa_player_start').bind('click', function(e) {that.play(e)});
		jQuery('#owa_player_stop').bind('click', function(e) {that.stop(e)});
		jQuery('#owa_player_close').bind('click', function(e) {OWA.endOverlaySession(e)});
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
		jQuery.jGrowl(event.key_value, { 
			life: 1500,
			speed: 1000,
			position: "bottom-right",
			closer: false,
			header: "Key Press:"
		});
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