<?php
namespace OWA\Core\Validation;

/**
 * Sub String Position Validation
 */
 
class IsNotCurrentUser extends \OWA\Core\Validation\Validation {
     
     /**
      * @return void
      */
     public function validate() {
         
         $value = $this->getValues();
         $cu = \OWA\Core\CoreAPI::getCurrentUser();
         $user_id = $cu->getUserData( 'user_id' );
         
         if ( $user_id === $value ) {
         
             $this->hasError();
         }
         
         // check to see if an error msg is passed from the controller
         if ( ! $this->getErrorMsg() ) {
            // if not set this default msg.
            $this->setErrorMessage( sprintf( \OWA\Core\CoreAPI::t( '"%s" is the current user.' ), $value ) );
        }
       
    }
     
}
