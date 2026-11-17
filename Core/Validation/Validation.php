<?php
namespace OWA\Core\Validation;


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
 * Abstract Validation Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
 
 class Validation {
 	 
 	 // name of param being validated.
 	 public $name;
     // hold config
     public $conf;

     // hold values to validate
     public $values;

     public $hasError;

     public $errorMsg;

     public $errorMsgTemplate;

     public function __construct($conf = array()) {

         if (array_key_exists('errorMsgTemplate', $conf)) {
             $this->errorMsgTemplate = $conf['errorMsgTemplate'];
         }
         
         if (array_key_exists('errorMsg', $conf)) {
             $this->setErrorMessage( $conf['errorMsg'] );
         }
         
         $this->conf = $conf;

     }
	 
	 public function setName( $name ) {
		 
	 	$this->name = $name;	
	 }
	 
	 public function getName() {
		 
		 return $this->name;
	 }
	 
     public function validate() {

         return false;
     }

     public function getErrorMsg() {

         return $this->errorMsg;
     }

     public function setErrorMessage($msg) {
         $this->errorMsg = $msg;
     }

     public function isValid() {

         if ($this->hasError == true):
             return false;
         else:
             return true;
         endif;
     }

     public function setConfigArray($array) {

         $this->conf = $array;
         return;
     }

     public function getConfig($name) {

         if (isset( $this->conf[$name] ) ) {
             return $this->conf[$name];
         }
     }

     public function setValues($values) {

         $this->values = $values;
         return;
     }

     public function getValues() {

         return $this->values;

     }

     public function hasError() {

         $this->hasError = true;
         return;
     }


 }
 
?>