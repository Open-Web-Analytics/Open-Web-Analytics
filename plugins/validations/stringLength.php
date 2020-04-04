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
 * Required Validation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
 
 class owa_stringLengthValidation extends owa_validation {

     function validate() {

         $value = $this->getValues();
         $length = $this->getConfig('length');
         $operator = $this->getConfig('operator');

         // default error msg
         $errorMsg = $this->getErrorMsg();
         if (empty($errorMsg)) {

             $this->setErrorMessage(sprintf("Must be %s %d character in length.", $operator, $length));
         }

         switch ($operator) {

             case '<':
                 if (strlen($value) >= $length) {
                    
                    $this->hasError();
                }
                 break;

             case '>':
                 if (strlen($value) <= $length) {
                    
                    $this->hasError();
                }
                 break;

             case '<=':
                 if (strlen($value) > $length) {
                    
                    $this->hasError();
                }
                 break;

             case '>=':
                 if (strlen($value) < $length) {
                    
                    $this->hasError();
                }
                 break;
         }
     }
 }
 
 
?>