<?php
namespace OWA\Core\Validation;

/**
 * Sub String Validation
 */
 
 class InArray extends \OWA\Core\Validation\Validation {

     /**
      * @return void
      */
     public function validate() {

        $value = $this->getValues();
        $possible_values = $this->getConfig('possible_values');
        
        if ( ! in_array( $value, $possible_values ) ) {
            
            $this->hasError();
        }
        
        $error = $this->getErrorMsg();

        if ( empty( $error ) ) {
            
            $this->setErrorMessage( sprintf(
                
                \OWA\Core\CoreAPI::t( '"%s" is not an allowed value. Allowed: %s' ),
                $value,
                implode( ' | ', $possible_values )
                 
            ) );
        }
     }

 }
