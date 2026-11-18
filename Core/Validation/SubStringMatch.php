<?php
namespace OWA\Core\Validation;

/**
 * Sub String Validation
 */
 
 class SubStringMatch extends \OWA\Core\Validation\Validation {

     /**
      * @return void
      */
     public function validate() {

         $value = $this->getValues();
         $length = strlen($this->getConfig('match'));
         $str = substr($value, $this->getConfig('position'), $length);

         switch ($this->getConfig('operator')) {

             case "=":

                 if ($str != $this->getConfig('match')) {
                     $this->hasError();
                 }

             break;

             case "!=":

                 if ($str === $this->getConfig('match')) {
                     $this->hasError();
                 }

             break;
         }

        $error = $this->getErrorMsg();

        if (empty($error)) {
            $this->setErrorMessage( sprintf( \OWA\Core\CoreAPI::t( 'The value contains "%s" at position %d.' ), $this->getConfig('match'), $this->getConfig('position') ) );
        }
     }

 }
