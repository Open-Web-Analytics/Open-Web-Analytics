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

	if (owa_click.init == 1) {
		
		owa_click.log_ajax();
   	
	}
    
	//setTimeout("wait()", 500);
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
    var init = null
    
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
    
    log_url = '%s&';
    properties = this.properties;
    
    for(param in properties) {  // print out the params
       
  		 get = get + param + "=" + properties[param] + "&";
  
	}
	
	get = get + Math.round(100*Math.random());

   
   bug = log_url + get ;
   
	if (window.XMLHttpRequest){

		// If IE7, Mozilla, Safari, etc: Use native object
		var ajax = new XMLHttpRequest()

	} 
	
	else {
		
		if (window.ActiveXObject){
	
	          // ...otherwise, use the ActiveX control for IE5.x and IE6
	          var ajax = new ActiveXObject("Microsoft.XMLHTTP"); 
		}

	}
    
	
	ajax.open("GET", bug, false); 
	ajax.send(null);
	
	// Uninitialize click object.
	this.init = null;
	
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

    this.properties["dom_element_name"] = this.targ.name;
    this.properties["dom_element_value"] = this.targ.value;
    this.properties["dom_element_id"] = this.targ.id;
    this.properties["page_url"] = owa_base64_encode(window.location.href);
    this.init = 1;
    
    return true;
}

/**
 * Sets coordinates of where in the browser the user clicked
 *
 */
function owa_setCoords() {
	
	var windowWidth = window.innerWidth ? window.innerWidth : document.body.offsetWidth;
	var windowHeight = window.innerHeight ? window.innerHeight : document.body.offsetHeight;
	
      if( typeof( this.e.pageX ) == 'number' ) {
      	
          this.properties["click_x"] = this.e.pageX;
          this.properties["click_y"] = this.e.pageY;
      }
      else {
      	 
          this.properties["click_x"] = this.e.clientX;
          this.properties["click_y"] = this.e.clientY;
      }
	
    //this.properties["click_x"] = this.e.clientX;
   
    //this.properties["click_x"] = Math.round(this.e.clientX / windowWidth * 100);
    //this.properties["click_y"] = this.e.clientY;
    this.properties["dom_element_x"] = findPosX(this.targ);
    this.properties["dom_element_y"] = findPosY(this.targ);
    this.properties["page_width"] = windowWidth;
	this.properties["page_height"] = windowHeight;

    return;
}

function findPosX(obj)
{
	var curleft = 0;
	if (obj.offsetParent)
	{
		while (obj.offsetParent)
		{
			curleft += obj.offsetLeft
			obj = obj.offsetParent;
		}
	}
	else if (obj.x)
		curleft += obj.x;
	return curleft;
}

function findPosY(obj)
{
	var curtop = 0;
	if (obj.offsetParent)
	{
		while (obj.offsetParent)
		{
			curtop += obj.offsetTop
			obj = obj.offsetParent;
		}
	}
	else if (obj.y)
		curtop += obj.y;
	return curtop;
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
    this.properties["dom_element_tag"] = this.targ.tagName;
    
    if (this.properties["dom_element_tag"] == "A") {
    
        if (this.targ.textContent != undefined) {
             this.properties["dom_element_text"] = this.targ.textContent;
        } else {
             this.properties["dom_element_text"] = this.targ.innerText;
        }
        
        this.properties["target_url"] = owa_base64_encode(this.targ.href);
        
    }
    else if (this.properties["dom_element_tag"] == "INPUT") {
    
        this.properties["dom_element_text"] = this.targ.value;
    }
    
    else if (this.properties["html_element_tag"] == "IMG") {
    
        this.properties["target_url"] = owa_base64_encode(this.targ.parentNode.href);
        this.properties["dom_element_text"] = this.targ.alt;
    }
    
    else {
    
    	this.properties["target_url"] = owa_base64_encode(this.targ.parentNode.href);
    	
        if (this.targ.textContent != undefined) {
             this.properties["html_element_text"] = this.targ.textContent;
        } else {
             this.properties["html_element_text"] = this.targ.innerText;
        }
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
        + " // dom_element_x: " + this.properties["dom_element_x"] 
        + " // dom_element_y: " + this.properties["dom_element_y"]
        + " // dom_element_name: " + this.properties["dom_element_name"] 
        + " // dom_element_text: " + this.properties["dom_element_text"] 
        + " // dom_element_value: " + this.properties["dom_element_value"] 
        + " // dom_element_id: " + this.properties["dom_element_id"]
        + " // dom_element_tag: " + this.properties["dom_element_tag"]
        + " // page_url: " + this.properties["page_url"]
        + " // target_url: " + this.properties["target_url"]
    );

    return;
}

// Base64 encodes strings
// Taken from http://www.jan-winkler.de/hw/artikel/art_j02.htm
function owa_base64_encode(decStr) {
  var base64s = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
  var bits;
  var dual;
  var i = 0;
  var encOut = '';

  while(decStr.length >= i + 3) {
    bits = (decStr.charCodeAt(i++) & 0xff) <<16 |
           (decStr.charCodeAt(i++) & 0xff) <<8 |
            decStr.charCodeAt(i++) & 0xff;

    encOut += base64s.charAt((bits & 0x00fc0000) >>18) +
              base64s.charAt((bits & 0x0003f000) >>12) +
              base64s.charAt((bits & 0x00000fc0) >> 6) +
              base64s.charAt((bits & 0x0000003f));
  }

  if(decStr.length -i > 0 && decStr.length -i < 3) {
    dual = Boolean(decStr.length -i -1);

    bits = ((decStr.charCodeAt(i++) & 0xff) <<16) |
           (dual ? (decStr.charCodeAt(i) & 0xff) <<8 : 0);

    encOut += base64s.charAt((bits & 0x00fc0000) >>18) +
              base64s.charAt((bits & 0x0003f000) >>12) +
              (dual ? base64s.charAt((bits & 0x00000fc0) >>6) : '=') +
              '=';
  }

  return(encOut);
}
