<?php
namespace OWA\Core\Validation;

/**
 * Compare Range Validation
 *
 * Refuses a request whose comparison is not usable: a `compare` value that is
 * not one of TimePeriod::getValidComparePeriods(), or compare dates that do not
 * make a date range. Given the whole request map, like DateRange, and asks
 * TimePeriod so the web and REST paths share one rule.
 */
class CompareRange extends \OWA\Core\Validation\Validation {

    /**
     * @return void
     */
    public function validate() {

        $error = \OWA\Module\Base\Classes\TimePeriod::getCompareError(
            (array) $this->getValues() );

        if ( $error === '' ) {

            return;
        }

        $this->hasError();

        if ( ! $this->getErrorMsg() ) {

            $this->setErrorMessage( $error );
        }
    }
}
