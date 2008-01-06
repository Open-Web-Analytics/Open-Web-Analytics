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


<? include("js_url_encode_lib.tpl");?>

/**
 * Javascript Tracking Library
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

var OWA = {}
	
OWA.log = function() {
	
	this.id = '';
}

OWA.log.prototype = {
    
    // private method for issuing logging request
    _makeAjaxRequest : function (properties) {
    	
    	var bug
    	var get
    	var init
    
    	url = this._assembleRequestUrl(properties);
	   
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
	    
		
		ajax.open("GET", url, false); 
		ajax.send(null);
		
		// Uninitialize variable.
		init = null;
		
		return;
    },

    // private method for issuing logging request
    _makeRequest : function (properties) {
    	
    	var bug
    	var url
    
    	url = this._assembleRequestUrl(properties);

	   	bug = "<img src=\"" + url + "\" height=\"1\" width=\"1\">";
	    
	   	document.write(bug);
	
        return;
    },
    
    _assembleRequestUrl : function(properties) {
    
    	var get
    	var log_url
    	
    	get = '';
    	
   		log_url = '<?=$this->makeAbsoluteLink('', false, $this->config['log_url']);?>';
   		
    	//assemble query string
	    for(param in properties) {  // print out the params
	       

			value = '';
			
	  		if (typeof properties[param] != 'undefined') {
    		
    			value = Url.encode(this._base64_encode(properties[param]+''));
    	
	    	} else {
    	
    			value = '';
    	
    		}
       
    		get = get + "owa_" + param + "=" + value + "&";
		}
		
		// add some radomness for cache busting
		return log_url + get + Math.round(100*Math.random());
    
    },
    
    _base64_encode : function(decStr) {
    
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

}

// OWA Page View object /////////////////////////////////////

OWA.pageView = function(caller_params) {

	this.properties = new Object();
	
	for(param in caller_params) {  // print out the params
	       
		this.properties[param] = caller_params[param];
			
    }

	this._setProperties();
	
	return;
};

OWA.pageView.prototype = {

	// public method for setting logging request properties
    _setProperties : function () {
    
		this.properties["event"] = "base.page_request";	
    	this.properties["action"] = "base.processRequest";
    
		if (typeof this.properties["page_uri"] == 'undefined') {
			this.properties["page_url"] = document.URL;
		}
		if (typeof this.properties["page_title"] == 'undefined') {
			this.properties["page_title"] = document.title;
		}
		
		if (typeof this.properties["referer"] == 'undefined') {
			this.properties["referer"] = document.referrer;
		}
    
        return;
    },
    
    log : function() {
    
    	logger = new OWA.log;
    	return logger._makeRequest(this.properties);
    
    }

}



// OWA click object /////////////////////////////////////////

/**
 * Constructor of the click object
 *
 * @param   e   Event Object
 */
OWA.click = function(caller_params) {

	this.properties = new Object();
	
	for(param in caller_params) {  // print out the params
	       
		this.properties[param] = caller_params[param];
			
    }
    
	this.e = '';
	
	return;

};

OWA.click.prototype = {

	properties : new Object,
	
	e : '',
	
	init: 0,
	
	
	/**
	 * Sets all properties of the click object
	 *
	 * @param   e   Event Object
	 */
	 setProperties : function(e) {
		
		this.e = e;
	    this.properties["event"] = "base.click";
	    this.properties["action"] = "base.processEvent";
	    this._setTarget();
	    this._setTagName();
	    this._setCoords();
	    this.properties["dom_element_name"] = this.targ.name;
	    this.properties["dom_element_value"] = this.targ.value;
	    this.properties["dom_element_id"] = this.targ.id;
	    this.properties["page_url"] = window.location.href;
	    this.init = 1;
	    
	    return true;
	},
	
	log : function() {
		
		//alert(this.properties["site_id"]);
		//alert("hello from click.log");
		
		if (this.init == 1) {
		
			var logger
		
			logger = new OWA.log;
			logger._makeAjaxRequest(this.properties);
		}
			
		this.init = 0;
		return;
	
	},
	
	/**
	 * Sets coordinates of where in the browser the user clicked
	 *
	 */
	_setCoords : function() {
	
		var windowWidth = window.innerWidth ? window.innerWidth : document.body.offsetWidth;
		var windowHeight = window.innerHeight ? window.innerHeight : document.body.offsetHeight;
		
	      if( typeof( this.e.pageX ) == 'number' ) {
	      	
	          this.properties["click_x"] = this.e.pageX + '';
	          this.properties["click_y"] = this.e.pageY + '';
	      }
	      else {
	      	 
	          this.properties["click_x"] = this.e.clientX + '';
	          this.properties["click_y"] = this.e.clientY + '';
	      }
		
	    this.properties["dom_element_x"] = this._findPosX(this.targ) + '';
	    this.properties["dom_element_y"] = this._findPosY(this.targ) + '';
	    this.properties["page_width"] = windowWidth + '';
		this.properties["page_height"] = windowHeight + '';
		
	    return;
	},
	
	/**
	 * Sets the X coordinate of where in the browser the user clicked
	 *
	 */
	_findPosX : function(obj) {
	
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
	},
	
	/**
	 * Sets the Y coordinates of where in the browser the user clicked
	 *
	 */
	_findPosY : function(obj) {
	
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
	},
	
	/**
	 * Sets the HTML element that actually generated the event
	 *
	 */
	_setTarget : function() {
	
	    // Determine the actual html element that generated the event
		//if (this.e.target) {
		//   this.targ = this.e.target;
		   
	    //} else if (this.e.srcElement) {
	    //     this.targ = this.e.srcElement;
	    // }
	    
	    this.targ = this.e.target || this.e.srcElement;
	    
		if (this.targ.nodeType == 3) {
		    // defeat Safari bug
	        this.targ = target.parentNode;
	    }
	    
	    return;
	},
	
	/**
	 * Sets the tag name of html eleemnt that generated the event
	 */
	_setTagName : function() {
	    
	    // Set properties of the owa_click object.
	    this.properties["dom_element_tag"] = this.targ.tagName;
	    
	    if (this.properties["dom_element_tag"] == "A") {
	    
	        if (this.targ.textContent != undefined) {
	             this.properties["dom_element_text"] = this.targ.textContent;
	        } else {
	             this.properties["dom_element_text"] = this.targ.innerText;
	        }
	        
	        this.properties["target_url"] = this.targ.href;
	        
	    }
	    else if (this.properties["dom_element_tag"] == "INPUT") {
	    
	        this.properties["dom_element_text"] = this.targ.value;
	    }
	    
	    else if (this.properties["html_element_tag"] == "IMG") {
	    
	        this.properties["target_url"] = this.targ.parentNode.href;
	        this.properties["dom_element_text"] = this.targ.alt;
	    }
	    
	    else {
	    
	    	this.properties["target_url"] = this.targ.parentNode.href;
	    	
	        if (this.targ.textContent != undefined) {
	             this.properties["html_element_text"] = this.targ.textContent;
	        } else {
	             this.properties["html_element_text"] = this.targ.innerText;
	        }
	    }
	
	    return;
	}	

}




