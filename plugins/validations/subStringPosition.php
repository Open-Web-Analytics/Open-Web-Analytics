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
 * Sub String Position Validation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.0.0
 */
 
 class owa_subStringPositionValidation extends owa_validation {
     
     function validate() {
         
         $value = $this->getValues();
         
         $substring = $this->getConfig('substring');
         
         $pos = strpos($value, $substring);
         
         $operator = $this->getConfig('operator');
         $position = $this->getConfig('position');
         $verb = '';
         switch ($operator) {
             
             case "=":
                 
                 if ($pos === $position) {
                     ;
                 } else {
                     $this->hasError();
                     $verb = 'was not';
                 }
                     
                         
                 break;
             
             case "!=":
                 
                 if ($pos === $position) {
                     $this->hasError();
                     $verb = 'was';
                 }
             
                 break;
         }
        
        // check to see if an error msg is passed from the controller
        if ( ! $this->getErrorMsg() ) {
            // if not set this default msg.
            $this->setErrorMessage(sprintf('The string "%s" %s found within the value at position %d', $substring, $verb, $pos));
        }         
       
     }
     
 }
 
 
?>