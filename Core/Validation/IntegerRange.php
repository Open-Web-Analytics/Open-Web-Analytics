<?php
namespace OWA\Core\Validation;

/**
 * Integer Range Validation
 *
 * A whole number, written as digits with an optional minus sign, between the
 * `min` and `max` configured (either may be left out).
 */
class IntegerRange extends \OWA\Core\Validation\Validation {

    /**
     * @return void
     */
    public function validate() {

        $value = $this->getValues();
        $min   = $this->getConfig( 'min' );
        $max   = $this->getConfig( 'max' );

        if ( ! $this->getErrorMsg() ) {

            if ( $min !== null && $max !== null ) {

                $this->setErrorMessage( sprintf( \OWA\Core\CoreAPI::t( 'Must be a whole number from %1$d to %2$d.' ), $min, $max ) );

            } elseif ( $min !== null ) {

                $this->setErrorMessage( sprintf( \OWA\Core\CoreAPI::t( 'Must be a whole number of at least %d.' ), $min ) );

            } elseif ( $max !== null ) {

                $this->setErrorMessage( sprintf( \OWA\Core\CoreAPI::t( 'Must be a whole number of at most %d.' ), $max ) );

            } else {

                $this->setErrorMessage( \OWA\Core\CoreAPI::t( 'Must be a whole number.' ) );
            }
        }

        if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) ) {

            $this->hasError();

            return;
        }

        $value = (int) $value;

        if ( ( $min !== null && $value < $min ) || ( $max !== null && $value > $max ) ) {

            $this->hasError();
        }
    }
}
