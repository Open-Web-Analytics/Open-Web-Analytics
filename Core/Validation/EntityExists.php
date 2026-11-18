<?php
namespace OWA\Core\Validation;

/**
 * Sub String Position Validation
 */
 
 class EntityExists extends \OWA\Core\Validation\Validation {
          
     /**
      * @return void
      */
     public function validate() {
         
         $entity = \OWA\Core\CoreAPI::entityFactory($this->getConfig('entity'));
         $entity->getByColumn($this->getConfig('column'), $this->getValues());
                  
         $error = $this->getErrorMsg();
         
         if (empty($error)) {
             $this->setErrorMessage( \OWA\Core\CoreAPI::t( 'That value does not exist.' ) );
         }

        $id = $entity->get('id');
        
        // validation logic
         if (empty($id)) {
             $this->hasError();
         }
     }
 }
