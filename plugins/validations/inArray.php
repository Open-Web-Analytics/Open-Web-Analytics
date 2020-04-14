<?php

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
 * Sub String Validation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
 
 class owa_inArrayValidation extends owa_validation {

     function validate() {

        $value = $this->getValues();
        $possible_values = $this->getConfig('possible_values');
        
        if ( ! in_array( $value, $possible_values ) ) {
	        
	        $this->hasError();
        }
        
        $error = $this->getErrorMsg();

        if ( empty( $error ) ) {
	        
            $error = $this->setErrorMessage( sprintf(
            	
            	'"%s" is not not a possible value. Choices are: %s', 
            	$value, 
            	implode( ' | ', $possible_values )
            	 
            ) );
        }
     }

 }
 
 
?>