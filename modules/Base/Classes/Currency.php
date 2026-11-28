<?php
namespace OWA\Module\Base\Classes;

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
    const EXPONENTS = [
        // No minor unit.
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0,
        'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        // Three decimal places.
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3,
        'TND' => 3,
        // Four.
        'CLF' => 4, 'UYW' => 4,
    ];

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

    /**
     * How many decimal places a major unit of this currency has.
     *
     * @param  string $code
     * @return int
     */
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
     * The currency a Property's reports format money in: its currencyISO3
     * setting, else the install's. ResultSetManager::formatCurrency() reads it,
     * and so does Classes\GoalMarking for a goal event carrying its definition's
     * value, so the value is in the currency it is shown in.
     *
     * @param  string $property_id  '' for the install's
     * @return string as the setting holds it
     */
    public static function ofProperty( $property_id ) {

        $property_id = (string) $property_id;

        $currency = $property_id !== ''
            ? \OWA\Core\CoreAPI::getSetting( 'base', 'currencyISO3', 'property', $property_id )
            : \OWA\Core\CoreAPI::getSetting( 'base', 'currencyISO3' );

        return (string) ( $currency ?: \OWA\Core\CoreAPI::getSetting( 'base', 'currencyISO3' ) );
    }

    /**
     * SQL for one row's amount as reported: the amount in its own currency's
     * major units, expressed in minor units of the Property's currency.
     *
     * ¥1000 is stored as 1000. In a USD Property it reads as 1000.00, which
     * is 100000 minor units, so the row is multiplied by 10^(2 - 0). A row with
     * no currency is in the Property's currency and is multiplied by 1. The
     * product is rounded to a whole minor unit of the Property's currency.
     *
     * The CASE lists only the codes in EXPONENTS, grouped by exponent; every
     * other code takes DEFAULT_EXPONENT, as exponent() does. CASE, IN and
     * round() are the same on MySQL 8 and MariaDB 10.11.
     *
     * @param  string $amount_column   qualified column holding minor units
     * @param  string $currency_column qualified column holding the row's code
     * @param  string $property_currency
     * @return string
     */
    public static function asReportedSql( $amount_column, $currency_column, $property_currency ) {

        $target = self::exponent( $property_currency );

        $by_exponent = [];

        foreach ( self::EXPONENTS as $code => $exponent ) {

            $by_exponent[ $exponent ][] = "'" . $code . "'";
        }

        ksort( $by_exponent );

        $when = [ sprintf( "WHEN %1\$s IS NULL OR %1\$s = '' THEN 1", $currency_column ) ];

        foreach ( $by_exponent as $exponent => $codes ) {

            $when[] = sprintf( 'WHEN %s IN (%s) THEN %s',
                $currency_column, implode( ', ', $codes ), self::powerOfTen( $target - $exponent ) );
        }

        return sprintf( 'round(%s * CASE %s ELSE %s END)',
            $amount_column, implode( ' ', $when ), self::powerOfTen( $target - self::DEFAULT_EXPONENT ) );
    }

    /**
     * 10^n as an exact SQL numeric literal: 100, 1, 0.01.
     *
     * @param  int $n
     * @return string
     */
    private static function powerOfTen( $n ) {

        return $n >= 0 ? '1' . str_repeat( '0', $n ) : '0.' . str_repeat( '0', -$n - 1 ) . '1';
    }

    /**
     * The note under a currency setting: what happens to revenue in other
     * currencies.
     *
     * A module answers through the `currency_conversion_status` filter, which
     * is given null and the chosen currency. With no answer, Base's: such
     * revenue is added as reported.
     *
     * @param  mixed $currency the currency chosen
     * @return string
     */
    public static function conversionStatus( $currency ) {

        $status = \OWA\Core\CoreAPI::filter( 'currency_conversion_status', null, (string) $currency );

        if ( is_string( $status ) && trim( $status ) !== '' ) {

            return $status;
        }

        return \OWA\Core\CoreAPI::t( 'Revenue in other currencies is added as reported, without conversion.' );
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
