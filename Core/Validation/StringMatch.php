<?php
namespace OWA\Core\Validation;

/**
 * String Match Validation
 */
 
 class StringMatch extends \OWA\Core\Validation\Validation {

     /**
      * @return void
      */
     public function validate() {

         $values_array = $this->getValues();
         $string1 = $values_array[0];
         $string2 = $values_array[1];

         $error = $this->getErrorMsg();

         if (empty($error)) {
             $this->setErrorMessage( \OWA\Core\CoreAPI::t( 'The values do not match.' ) );
         }

         // validation logic
         if ($string1 === $string2) {
             ;
         } else {
             $this->hasError();
         }
     }

 }
