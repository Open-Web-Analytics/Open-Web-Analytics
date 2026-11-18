<?php
namespace OWA\Core\Validation;

/**
 * User Name Validation
 */
 
 class UserName extends \OWA\Core\Validation\Validation {

     /**
      * @return void
      */
     public function validate() {

         $error = $this->getErrorMsg();

         if (empty($error)) {
             $this->setErrorMessage( \OWA\Core\CoreAPI::t( 'The user name contains characters that are not allowed.' ) );
         }

         $u = $this->getValues();

         $illegals = \OWA\Core\CoreAPI::getSetting('base', 'user_id_illegal_chars');

         foreach ( $illegals as $k => $char ) {

             if ( strpos( $u, $char ) ) {

                 $this->hasError();
                 break;
             }
         }
     }
 }
