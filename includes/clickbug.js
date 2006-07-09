//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
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
 * Click Tracking Webbug
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

// Set Global Variables
var owa_click = new click()

document.onclick = owa_clickTrack;
window.addEventListener('beforeunload', owa_clickLogger, false);


/**
 * Controller for tracking a click
 *
 */
function owa_clickTrack(e) {

    owa_click.set(e);
    //owa_click.log();

    return;

}

function wait() {
	
	return;
}

/**
 * Controller for tracking a click
 *
 */
function owa_clickLogger() {

    owa_click.log_ajax();
    
	setTimeout("wait()", 10000);
   // alert("click");
	return;

}

/**
 * Click Object
 *
 */
function click() {
    
    var e = null
    var targ = null
    var properties = null
    
    this.properties = new Object();
    
    this.set = owa_setClickProperties;
    this.setTagName = owa_setTagName;
    this.setCoords = owa_setCoords;
    this.setTarget = owa_setTarget;
    this.log = owa_logClick;
    this.log_ajax = owa_logClickAjax;
    this.debug = owa_debugClick;
}

function owa_logClickAjax() {
	
	var get = ''
    var properties
    var log_url
    var bug
    
    log_url = '%s&'
    properties = this.properties;
    
    for(param in properties) {  // print out the params
       
  		 get = get + param + "=" + properties[param] + "&";
  
	}
	
	get = get + Math.round(100*Math.random());

   //alert(get);
   bug = log_url + get ;
    
	
	
	
	data = new XMLHttpRequest();
	data.open("GET", bug, false); data.send(null);
	
	return;
}

function owa_logClick() {

    var get = ''
    var properties
    var log_url
    var bug
    
    log_url = '%s&'
    properties = this.properties;
    
    for(param in properties) {  // print out the params
       
  		 get = get + param + "=" + properties[param] + "&";
  
	}
	
	get = get + Math.round(100*Math.random());

   //alert(get);
   bug = '<img src=\"' + log_url + get + '\" height=\"1\" width=\"1\">';
    
   document.getElementById("owa_click_bug").innerHTML = bug;
   
   return;
}

/**
 * Sets all properties of the click object
 *
 * @param   e   Event Object
 */
function owa_setClickProperties(e) {

    this.e = e;
    this.setTarget();
    this.setTagName();
    this.setCoords();

    this.properties["html_element_name"] = this.targ.name;
    this.properties["html_element_value"] = this.targ.value;
    this.properties["html_element_id"] = this.targ.id;
    
    return true;
}

/**
 * Sets coordinates of where in the browser the user clicked
 *
 */
function owa_setCoords() {

    this.properties["click_x"] = this.e.clientX;
    this.properties["click_y"] = this.e.clientY;
    this.properties["html_element_x"] = this.targ.pageX;
    this.properties["html_element_y"] = this.targ.pageY;

    return;
}

/**
 * Sets the HTML element that actually generated the event
 *
 */
function owa_setTarget() {

    // Determine the actual html element that generated the event
	if (this.e.target) {
	   this.targ = this.e.target;
	   
	} else if (this.e.srcElement) {
        this.targ = this.e.srcElement;
    }
    
	if (this.targ.nodeType == 3) {
	    // defeat Safari bug
        this.targ = target.parentNode;
    }
    
    return;
}

/**
 * Sets the tag name of html eleemnt that generated the event
 */
function owa_setTagName() {
    
    // Set properties of the owa_click object.
    this.properties["html_element_tag"] = this.targ.tagName;
    
    if (this.properties["html_element_tag"] == "A") {
    
        if (this.targ.textContent != undefined) {
             this.properties["html_element_text"] = this.targ.textContent;
        } else {
             this.properties["html_element_text"] = this.targ.innerText;
        }
    }
    else if (this.properties["html_element_tag"] == "INPUT") {
    
        this.properties["html_element_text"] = this.targ.value;
    }

    return;
}

/**
 * Debug function for checking properties of a click.
 *
 */
function owa_debugClick() {

    alert( 
        " // click_x: " + this.properties["click_x"]
        + " // click_y: " + this.properties["click_y"] 
        + " // html_element_x: " + this.properties["html_element_x"] 
        + " // html_element_y: " + this.properties["html_element_y"]
        + " // html_element_name: " + this.properties["html_element_name"] 
        + " // html_element_text: " + this.properties["html_element_text"] 
        + " // html_element_value: " + this.properties["html_element_value"] 
        + " // html_element_id: " + this.properties["html_element_id"]
        + " // html_element_tag: " + this.properties["html_element_tag"]
    );

    return;
}
