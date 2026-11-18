<?php
namespace OWA\Core\Validation;

/**
 * Required Validation
 */
 
 class StringLength extends \OWA\Core\Validation\Validation {

     /**
      * @return void
      */
     public function validate() {

         $value = $this->getValues();
         $length = $this->getConfig('length');
         $operator = $this->getConfig('operator');

         // default error msg
         $errorMsg = $this->getErrorMsg();
         if (empty($errorMsg)) {

             $templates = [
                 '<'  => \OWA\Core\CoreAPI::t( 'Must be fewer than %d characters.' ),
                 '<=' => \OWA\Core\CoreAPI::t( 'Must be at most %d characters.' ),
                 '>'  => \OWA\Core\CoreAPI::t( 'Must be more than %d characters.' ),
                 '>=' => \OWA\Core\CoreAPI::t( 'Must be at least %d characters.' ),
             ];

             $this->setErrorMessage( sprintf( $templates[ $operator ] ?? \OWA\Core\CoreAPI::t( 'Must be %d characters.' ), $length ) );
         }

         switch ($operator) {

             case '<':
                 if (strlen($value) >= $length) {
                    
                    $this->hasError();
                }
                 break;

             case '>':
                 if (strlen($value) <= $length) {
                    
                    $this->hasError();
                }
                 break;

             case '<=':
                 if (strlen($value) > $length) {
                    
                    $this->hasError();
                }
                 break;

             case '>=':
                 if (strlen($value) < $length) {
                    
                    $this->hasError();
                }
                 break;
         }
     }
 }
