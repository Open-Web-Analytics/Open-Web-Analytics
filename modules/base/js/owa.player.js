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
 * @web            <a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.2.1
 */

OWA.player = function() {

    OWA.registerStateStore('overlay', '', '', 'json');
    //OWA.state.load('overlay');

    OWA.util.loadScript(OWA.getSetting('baseUrl')+'/modules/base/js/includes/jquery/jquery-1.6.4.min.js', function(){});
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
    animateInterval : 250,
    stream : null,
    lock : false,

    block : function() {
        this.lock = true;
    },

    unblock : function() {
        this.lock = false;
    },

    load : function(data) {
		
        this.stream = data.data;
        // count the events in the queue
        this.queue_count = this.stream.events.length;
    },

    /**
     * Fetches data via ajax request
     */
    fetchData: function() {

        var p = unescape(OWA.state.getStateFromCookie('overlay'));
        var params = JSON.parse(p);
        var url = params.api_url;
        
        //closure
        var that = this;

        jQuery.ajax({
            url:  url,
        
            dataType: 'jsonp',
            jsonp: 'owa_jsonpCallback',
            success: function(data) {
                that.load(data);
            }
        });

        //OWA.debug(data.page);
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
        if (!this.timer) {
	        return false;
	    }
        
        clearInterval(this.timer);
        
        this.setStatus('Ready.');

    },

    play : function() {
        OWA.debug("Now playing Domstream.");

        if ((this.queue_step = this.queue_count)) {
            this.queue_step = 1;
        }

        this.start();
        this.setStatus('Playing...');
    },

    showPlayerControls : function() {

        //create player control bar
        var player = '<div id="owa_overlay"></div>';
        jQuery('body').append(player);
        jQuery('#owa_overlay').append('<div id="owa_overlay_logo"></div>'); //logo
        var startlink = '<div class="owa_overlay_control" id="owa_player_start">Play</div>';
        jQuery('#owa_overlay').append(startlink);
        var pauselink = '<div class="owa_overlay_control" id="owa_player_stop">Pause</div>';
        jQuery('#owa_overlay').append(pauselink);
        var closelink = '<div class="owa_overlay_control" id="owa_player_close">Hide</div>';
        jQuery('#owa_overlay').append(closelink);
        var status_msg = '<div id="owa-overlay-status">...</div>';
        jQuery('#owa_overlay').append(status_msg);

        //create hidden player controls container
        var hiddenplayer = '<div id="owa_overlay_hidden"></div>';
        jQuery('body').append(hiddenplayer);
        jQuery("#owa_overlay_hidden").hide();

        //add cursor
        var cursor = '<div id="owa-cursor"><img src="'+OWA.getSetting('baseUrl')+'/modules/base/i/cursor2.png"></div>';
        jQuery('body').append(cursor);

        jQuery('#owa_overlay_start').toggleClass('active');

        // set active color. not sure this works right....
        jQuery('.owa_overlay_control').click( function(){
            jQuery(".owa_overlay_control").removeClass('active');
            jQuery(this).addClass('active');
        });

        //hide toolbar and make visible the 'show' button
        jQuery("#owa_overlay_logo").click(function() {
            jQuery("#owa_overlay").slideToggle("fast");
            jQuery("#owa_overlay_hidden").fadeIn("slow");
        });

        //show toolbar and hide the 'show' button
        jQuery("#owa_overlay_hidden").click(function() {
            jQuery("#owa_overlay").slideToggle("fast");
            jQuery("#owa_overlay_hidden").fadeOut();
        });

        //closure
        var that = this;

        // start player
        jQuery('#owa_player_start').bind('click', function(e) {that.play(e)});

        // pause player
        jQuery('#owa_player_stop').bind('click', function(e) {that.stop(e)});

        // eliminate overlay cookie when close button is pressed.
        jQuery('#owa_player_close').click( function() {
            jQuery("#owa_overlay").slideToggle("fast");
            jQuery("#owa_overlay_hidden").fadeIn("slow");
        });

        // eliminate overlay cookie when window closes.
        jQuery(window).unload(function() {OWA.endOverlaySession()});
    },

    setStatus : function(msg) {

        jQuery('#owa-overlay-status').html(msg);

    },

    showNotification : function(msg, header) {
        jQuery.jGrowl.defaults.position = 'center';
        jQuery.jGrowl.defaults.closer = false;
        jQuery.jGrowl.defaults.pool = 1;
        jQuery.jGrowl(msg, {
            life: 250,
            speed: 25,
            position: "center",
            closer: false,
            header: header
        });

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
        this.showNotification(event.key_value, "Key Press:");
        this.setStatus("Key Press: " + event.key_value);
    },

    clickEventHandler : function(event) {

        var accessor = '';

        if (event.dom_element_id != "" && event.dom_element_id != "(not set)" ) {
            accessor = '#'+event.dom_element_id;
            var accessor_msg = accessor;
        } else if (event.dom_element_name != "" && event.dom_element_name != "(not set)" ) {
            accessor = event.dom_element_tag+"[name="+event.dom_element_name+"]";
            var accessor_msg = accessor;
            //console.log("accessor: %s", accessor);
        } else if(event.dom_element_class != "" && event.dom_element_class != "(not set)") {
            var accessor_msg = event.dom_element_tag+"."+event.dom_element_class;
        } else {
            var accessor_msg = event.dom_element_tag;
        }

        var d = new Date();
        var id = 'owa-click-marker' + '_' + d.getTime()+1;
        var marker = '<div id="'+id+'" class="owa-click-marker"></div>';
        jQuery('body').append(marker);
        jQuery('#'+id).css({'position': 'absolute','left': event.click_x +'px', 'top': event.click_y +'px', 'z-index' : 89});

        if (accessor) {
            jQuery(accessor).click();
            jQuery(accessor).focus();
        }
        //jQuery('#owa-latest-click').slideToggle('normal');
        //console.log("Clicking: %s", accessor);
        //this.setStatus("Clicking: "+accessor);
        this.setStatus("Click @ "+event.click_x+", "+event.click_y);
        this.showNotification(accessor_msg, "Clicked On DOM Element:");


    }

}