<?php
namespace OWA\Core\Validation;

/**
 * Pattern Validation
 *
 * A string the regular expression `pattern` matches.
 */
class Pattern extends \OWA\Core\Validation\Validation {

    /**
     * @return void
     */
    public function validate() {

        $value = $this->getValues();

        if ( ! $this->getErrorMsg() ) {

            $this->setErrorMessage( \OWA\Core\CoreAPI::t( 'The value is not in the form this takes.' ) );
        }

        if ( ! is_string( $value ) || preg_match( (string) $this->getConfig( 'pattern' ), $value ) !== 1 ) {

            $this->hasError();
        }
    }
}
