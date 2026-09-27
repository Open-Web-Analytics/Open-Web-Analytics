<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * ISO 4217 currency codes and their minor units.
 *
 * Money is stored in MINOR units, and how many a major unit holds depends on the
 * currency: 100 cents to the dollar, but no minor unit of the yen and 1000 fils
 * to the Kuwaiti dinar. Every conversion was a multiplication by 100, so ¥1500
 * was stored as 150000 and read back as ¥1500.00 only because the report divided
 * by the same wrong 100 -- and summed against dollars it was wrong outright.
 *
 * Only the currencies whose exponent is NOT 2 are listed; every other valid
 * code has two decimal places.
 */
class Currency {

    /** ISO 4217 exponents other than 2. */
    const EXPONENTS = array(
        // No minor unit.
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0,
        'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        // Three decimal places.
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3,
        'TND' => 3,
        // Four.
        'CLF' => 4, 'UYW' => 4,
    );

    /** The exponent for currencies not listed above, and for an unknown code. */
    const DEFAULT_EXPONENT = 2;

    /**
     * A currency code in canonical form, or '' if the value is not one.
     *
     * Three letters, upper-cased. Whether the code is ASSIGNED is not checked:
     * the list of live codes changes, and a well-formed code a site sends is
     * recorded as sent.
     *
     * @param  mixed $value
     * @return string
     */
    public static function normalize( $value ) {

        $code = strtoupper( trim( (string) $value ) );

        return preg_match( '/^[A-Z]{3}$/', $code ) ? $code : '';
    }

    /** How many decimal places a major unit of this currency has. */
    public static function exponent( $code ) {

        $code = self::normalize( $code );

        return isset( self::EXPONENTS[ $code ] ) ? self::EXPONENTS[ $code ] : self::DEFAULT_EXPONENT;
    }

    /**
     * A major-unit amount in minor units, rounded -- NULL for anything that is
     * not a number, since a store that sent something unparseable did not sell
     * nothing.
     *
     * round() before the cast: (int) truncates, and 12.49 through floating point
     * is 1248.99999..., which a cast turns into 1248.
     *
     * @param  mixed  $value major units
     * @param  string $code
     * @return int|null
     */
    public static function toMinorUnits( $value, $code ) {

        if ( $value === null || $value === false || $value === '' || ! is_numeric( $value ) ) {

            return null;
        }

        return (int) round( ( (float) $value ) * pow( 10, self::exponent( $code ) ) );
    }

    /**
     * Minor units back to a major-unit amount.
     *
     * @param  int|float $minor
     * @param  string    $code
     * @return float
     */
    public static function toMajorUnits( $minor, $code ) {

        return ( (float) $minor ) / pow( 10, self::exponent( $code ) );
    }
}

?>
