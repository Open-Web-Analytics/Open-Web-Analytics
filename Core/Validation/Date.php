<?php
namespace OWA\Core\Validation;

/**
 * Date Validation
 *
 * A date that exists, as yyyymmdd. With `ago`, also a period back from
 * today: a number and days, months or years (90days, 18m, 2years).
 */
class Date extends \OWA\Core\Validation\Validation {

    /**
     * @return void
     */
    public function validate() {

        $value = $this->getValues();
        $ago   = (bool) $this->getConfig( 'ago' );

        if ( ! $this->getErrorMsg() ) {

            $this->setErrorMessage( $ago
                ? \OWA\Core\CoreAPI::t( 'Not a date or a period. Use yyyymmdd, or a period back from today such as 90days, 18m or 2years.' )
                : \OWA\Core\CoreAPI::t( 'Not a date. Dates are yyyymmdd.' ) );
        }

        if ( self::resolve( $value, $ago ) === null ) {

            $this->hasError();
        }
    }

    /**
     * $value as yyyymmdd, or null when it is not a date (or, with $ago, a
     * period back from today).
     *
     * @param  mixed $value
     * @param  bool  $ago
     * @return string|null
     */
    public static function resolve( $value, $ago = false ) {

        if ( ! is_string( $value ) && ! is_int( $value ) ) {

            return null;
        }

        $value = trim( (string) $value );

        if ( preg_match( '/^\d{8}$/', $value ) ) {

            $d = \DateTimeImmutable::createFromFormat( 'Ymd|', $value );

            return ( $d && $d->format( 'Ymd' ) === $value ) ? $value : null;
        }

        if ( $ago && preg_match( '/^(\d+)\s*(day|days|d|month|months|m|year|years|y)$/i', $value, $m ) && (int) $m[1] >= 1 ) {

            $unit = strtolower( $m[2] )[0];
            $unit = $unit === 'd' ? 'day' : ( $unit === 'm' ? 'month' : 'year' );

            return ( new \DateTimeImmutable( 'today' ) )->modify( sprintf( '-%d %s', (int) $m[1], $unit ) )->format( 'Ymd' );
        }

        return null;
    }
}
