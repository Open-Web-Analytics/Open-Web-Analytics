<?php
namespace OWA\Core\View;

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

class Cli extends \OWA\Core\View {

    function __construct( $params ) {
	   	
		parent::__construct($params);
    }
    
    function pre() {
	    
	    $this->t->set_template('wrapper_blank.php');
        $this->body->set_template('msgsCli.php');
	    
	    $error = array();
	    
	    // set error msgs
        if ( array_key_exists( 'error_msg', $this->data ) ) {
	        
            $error[] = $this->data['error_msg'];
        }
        
        if ( array_key_exists( 'validation_errors', $this->data ) ) {
	        
            $error[] = $this->data['validation_errors'];
        }
        
		$this->body->set('response_data', '');
		$this->body->set('error', $error);
    }
    
    /**
	 * Sets the data payload of the response
	 */
    function setResponseData( $data ) {
	    
	    $this->body->set( 'response_data', $data );
    }
}
