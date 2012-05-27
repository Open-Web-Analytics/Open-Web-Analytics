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
 * OWA Sparkline Implementation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @web			<a href="http://www.openwebanalytcs.com">Open Web Analytics</a>
 * @copyright   Copyright &copy; 2006-2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */
 
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
	
	mergeOptions: function ( options ) {
	
		for (option in options) {
			
			if ( options.hasOwnProperty( option ) ) {
				this.options[ option ] = options[ option ];
			}
		}
	},
	
	setDomId: function( dom_id ) {
		
		this.dom_id = dom_id;
		this.domSelector = this.dom_id + ' > .sparkline';
		// listen for data change events
		var that = this;
		jQuery( '#' + that.dom_id ).bind( 'new_result_set', function( event, resultSet ) {
			jQuery( that.domSelector ).remove();
			that.generate( resultSet );
		});
		
	},
	
	getOption: function( name ) {
		
		if ( this.options.hasOwnProperty( name ) ) {
			return this.options[ name ];
		}
	},
	
	setOption: function ( name, value ) {
		
		this.options[name] = value;
	},
	
	generate : function(resultSet, dom_id, options) {
	
		if ( dom_id ) {
     		
     		this.setDomId( dom_id );
     	}
     	
     	dom_id = this.dom_id;
     	
     	if ( options ) {
     		
     		this.mergeOptions( options );
     	}
     	
     	
     	var data = resultSet.getSeries(this.options.metric, '', this.options.filter);
     	
     	if ( ! data) {
			data = [0,0,0];
		}
		var selector = this.domSelector;
		jQuery('#' + dom_id ).append('<p class="sparkline"></p>');
		
		this.loadFromArray(data);
    },

	render: function() {
		
		 jQuery('#' + this.dom_id).sparkline('html', this.options);
	},
	
	loadFromArray :function(data) {
		var selector = '#' + this.domSelector;
		jQuery( selector ).sparkline(data, this.options);
	},
		
	setHeight: function(height) {
		
		this.options.height = height;
	},
	
	setWidth: function(width) {
		
		this.options.width = width;
	}	
}
