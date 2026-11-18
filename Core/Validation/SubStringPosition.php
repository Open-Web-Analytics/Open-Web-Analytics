<?php
namespace OWA\Core\Validation;

/**
 * Sub String Position Validation
 */
 
 class SubStringPosition extends \OWA\Core\Validation\Validation {
     
     /**
      * @return void
      */
     public function validate() {
         
         $value = $this->getValues();
         
         $substring = $this->getConfig('substring');
         
         $pos = strpos((string) $value, (string) $substring);
         
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
            $this->setErrorMessage( sprintf( $verb === 'was'
                ? \OWA\Core\CoreAPI::t( 'The value contains "%s" at position %d.' )
                : \OWA\Core\CoreAPI::t( 'The value does not contain "%s" at position %d.' ), $substring, $pos ) );
        }
       
     }
     
 }
