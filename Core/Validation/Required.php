<?php
namespace OWA\Core\Validation;

/**
 * Required Validation
 */
 
 class Required extends \OWA\Core\Validation\Validation {
          
     /**
      * @return void
      */
     public function validate() {
         
         $value = $this->getValues();
         
         $error = $this->getErrorMsg();
         
         if (empty($error)) {
             $this->setErrorMessage( sprintf( \OWA\Core\CoreAPI::t( '%s is required.' ), $this->getName() ) );
         }
         
         if (empty($value)):
             $this->hasError();
         endif;
         
         return;
     }
     
 }
