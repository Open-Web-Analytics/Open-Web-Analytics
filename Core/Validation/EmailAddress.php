<?php
namespace OWA\Core\Validation;

/**
 * Email Address Validation
 */
 
 class EmailAddress extends \OWA\Core\Validation\Validation {
     
     /**
      * @return void
      */
     public function validate() {
         
         $error = $this->getErrorMsg();
         
         if (empty($error)) {
             $this->setErrorMessage( \OWA\Core\CoreAPI::t( 'The email address is not valid.' ) );
         }

         $email = $this->getValues();
        
        if ( ! filter_var ( $email, FILTER_VALIDATE_EMAIL ) ) {
                 
             $this->hasError();
         }
     }
 }
