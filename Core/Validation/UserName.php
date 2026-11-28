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

         // None given is for 'required' to answer.
         if ( $u === null ) {

             return;
         }

         if ( ! is_scalar( $u ) ) {

             $this->hasError();

             return;
         }

         $u = (string) $u;

         $illegals = \OWA\Core\CoreAPI::getSetting('base', 'user_id_illegal_chars');

         foreach ( $illegals as $k => $char ) {

             // !== false: a character in the first position is at offset 0.
             if ( strpos( $u, (string) $char ) !== false ) {

                 $this->hasError();
                 break;
             }
         }
     }
 }
