<?php
namespace OWA\Core\Validation;

/**
 * Entity does not exist Validation
 */
 
 class EntityDoesNotExist extends \OWA\Core\Validation\Validation {

     /**
      * @return void
      */
     public function validate() {

         $entity = \OWA\Core\CoreAPI::entityFactory($this->getConfig('entity'));
         
         $values = $this->getValues();
         
         if ( $values ) {

             $entity->getByColumn($this->getConfig('column'), $values );
         
        } else {
             
            $this->setErrorMessage( \OWA\Core\CoreAPI::t( 'A value is required.' ) );
            $this->hasError();
        }

         $error = $this->getErrorMsg();

         if (empty($error)) {
          
             $this->setErrorMessage( \OWA\Core\CoreAPI::t( 'That value is already in use.' ) );
         }

        $id = $entity->get('id');

        // validation logic
         if (!empty($id)) {
             $this->hasError();
         }
     }
 }
