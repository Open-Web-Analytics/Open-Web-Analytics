<?php
namespace OWA\Module\Base\Classes;

/**
 * Data Validator Class
 */
 
 class Validator extends \OWA\Core\Base {
     
     /**
      * Flag for whether or not a validation run produces errors
      * 
      * @var boolean
      */
     public $hasErrors;
     
     /**
      * Error Msgs produced by Validations
      * 
      * @var array<string, string|array<string, string>|null>
      */
     public $errorMsgs;
     
     /**
      * Validations to be performed in next validation run
      * 
      * @var list<array{name: string, obj: \OWA\Core\Validation\Validation}>
      */
     public $validations;
          
     /**
      * Adds a validation to be performed in next run
      * 
      * @param string    $name         the name to be given to the validation and its results
      * @param mixed      $value        the data value that is to be validated
      * @param string     $validation the name of the validation to run
      * @param array<string, mixed> $conf configuration array for the object being created
      * @return null
      */
     public function addValidation($name, $value, $validation, $conf) {
                
        // Construct validatation obj
        $obj = $this->validationFactory($validation, $conf);
        $obj->setValues($value);
        $obj->setConfigArray($conf);
        
        $this->setValidation( $name, $obj );

        return null;
    }
    
    /**
     * @param  string $name
     * @param  \OWA\Core\Validation\Validation $obj
     * @return null
     */
    public function setValidation($name, $obj) {
        
        // add name to validator
        $obj->setName($name);
        $this->validations[] = ['name' => $name, 'obj' => $obj];

        return null;
    }
    
    /**
     * Factory method for producing validation objects
     * 
     * @param  string $class_file
     * @param  array<string, mixed> $conf
     * @return Object
     */
    public function validationFactory($class_file, $conf = []) {
        
        return \OWA\Core\CoreAPI::validationFactory($class_file, $conf);
    }
    
    /**
     * Performs a validation run
     * 
     * @return void
     */
    public function doValidations() {
        
        foreach ($this->validations as $k) {
            \OWA\Core\CoreAPI::debug('Validating '.$k['name']. ' with '. get_class( $k['obj'] ));
            $k['obj']->validate();
            
            if ($k['obj']->hasError === true) {
                \OWA\Core\CoreAPI::debug('Validation failed.');
                $this->hasErrors = true;
                $this->errorMsgs[$k['name']] = $k['obj']->getErrorMsg();
             
                if ( isset( $k['obj']->conf['stopOnError'] ) && $k['obj']->conf['stopOnError'] === true ) {
                      
                    break;
                }
                
            } else {
                \OWA\Core\CoreAPI::debug('Validation succeeded.');
            }
        }
          
    }
    
    /**
     * Check to see if the validation run was successful.
     * 
     * @return boolean
     */
    public function isValid() {
        
        if ($this->hasErrors == true):
            return false;
        else:
            return true;
        endif;
    }
    
    /**
     * Accessor method for retrieving the error msgs produced by a validation run
     * 
     * @return array<string, string|array<string, string>|null>
     */
    public function getErrorMsgs() {
        
        return $this->errorMsgs;
    }
     
    
 }
