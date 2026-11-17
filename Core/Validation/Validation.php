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
 	 
 	 /** @var string|null name of param being validated */
 	 public $name;

     /** @var array<string, mixed> errorMsg, stopOnError and each validation's own keys */
     public $conf;

     /** @var mixed the value to validate, any type a controller passes */
     public $values;

     /** @var bool|null true once the validation has failed */
     public $hasError;

     /**
      * A message, or getMsg()'s headline and message.
      *
      * @var string|array<string, string>|null
      */
     public $errorMsg;

     /** @var mixed the errorMsgTemplate configuration key, any type given; nothing reads it */
     public $errorMsgTemplate;

     /**
      * @param array<string, mixed> $conf
      */
     public function __construct($conf = array()) {

         if (array_key_exists('errorMsgTemplate', $conf)) {
             $this->errorMsgTemplate = $conf['errorMsgTemplate'];
         }
         
         if (array_key_exists('errorMsg', $conf)) {
             $this->setErrorMessage( $conf['errorMsg'] );
         }
         
         $this->conf = $conf;

     }
	 
	 /**
	  * @param  string $name
	  * @return void
	  */
	 public function setName( $name ) {
		 
	 	$this->name = $name;	
	 }
	 
	 /**
	  * @return string|null
	  */
	 public function getName() {
		 
		 return $this->name;
	 }
	 
     /**
      * Sets $hasError through hasError() when the value fails. Validator reads
      * that, not the return value.
      *
      * @return bool|void
      */
     public function validate() {

         return false;
     }

     /**
      * @return string|array<string, string>|null
      */
     public function getErrorMsg() {

         return $this->errorMsg;
     }

     /**
      * @param  string|array<string, string> $msg
      * @return void
      */
     public function setErrorMessage($msg) {
         $this->errorMsg = $msg;
     }

     /**
      * @return bool
      */
     public function isValid() {

         if ($this->hasError == true):
             return false;
         else:
             return true;
         endif;
     }

     /**
      * @param  array<string, mixed> $array
      * @return void
      */
     public function setConfigArray($array) {

         $this->conf = $array;
         return;
     }

     /**
      * @param  string $name
      * @return mixed  as the configuration holds it; null when unset
      */
     public function getConfig($name) {

         if (isset( $this->conf[$name] ) ) {
             return $this->conf[$name];
         }
     }

     /**
      * @param  mixed $values any type a controller passes
      * @return void
      */
     public function setValues($values) {

         $this->values = $values;
         return;
     }

     /**
      * @return mixed
      */
     public function getValues() {

         return $this->values;

     }

     /**
      * Marks the validation failed.
      *
      * @return void
      */
     public function hasError() {

         $this->hasError = true;
         return;
     }


 }
 
?>