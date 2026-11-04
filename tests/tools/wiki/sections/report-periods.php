<?php
/**
 * The predefined reporting periods, in the order the picker offers them.
 *
 * Read from TimePeriod::getPeriodLabels(), the list TimePeriod::isValid()
 * accepts: its label, the `owa_period` value and what each covers.
 */

use OWA\Module\Base\Classes\TimePeriod;

return function () {

    $cell = fn ( $v ) => str_replace( array( '|', "\n" ), array( '\\|', ' ' ), trim( (string) $v ) );

    $out = "| Predefined period | `owa_period` | Covers |\n|---|---|---|\n";

    foreach ( ( new TimePeriod() )->getPeriodLabels() as $period => $p ) {

        $out .= sprintf( "| %s | `%s` | %s |\n",
            $cell( $p['label'] ?? '' ) ?: '—', $period, $cell( $p['covers'] ?? '' ) ?: '—' );
    }

    return $out;
};
