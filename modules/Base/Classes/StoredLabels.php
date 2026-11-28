<?php
namespace OWA\Module\Base\Classes;

/**
 * Words the cube build stores as a dimension's value, shown in the locale in
 * use and accepted in it as a filter value.
 *
 * A dimension names its set with `label_set` in config/dimensions.php. The
 * stored value stays English: a report translates it where it is shown
 * (ResultSetManager::applyMetaDataToSingleResultRow()), and a constraint maps
 * a translated word back to the English it stands for
 * (ResultSetManager::applyConstraint()). A value outside the set (a channel
 * named in a site's own conf/channels.php) is shown and filtered as stored.
 */
class StoredLabels {

    /** Cube\NewVsReturningStep's two values. */
    const NEW_VS_RETURNING = [ 'New', 'Returning' ];

    /**
     * The shipped channel names (conf/channels.php), then Unassigned, as
     * Cube\ChannelStep::channels() lists them. Listed here so the string
     * catalogue holds them; a test keeps the two lists the same.
     */
    const CHANNELS = [
        'Direct', 'Cross-network', 'Paid Shopping', 'Paid Search', 'Paid Social',
        'Paid Video', 'Display', 'Paid Other', 'AI Agent', 'Organic Shopping',
        'Organic Social', 'Organic Video', 'Organic Search', 'Referral', 'Email',
        'Affiliates', 'Audio', 'SMS', 'Mobile Push Notifications', 'Unassigned',
    ];

    /** @var array<string, list<string>> set => its English labels, once read */
    private static $sets = [];

    /**
     * A set's English labels.
     *
     * @param  string $set  channel or newVsReturning
     * @return list<string>  none for a set that does not exist
     */
    public static function labels( $set ) {

        if ( ! isset( self::$sets[ $set ] ) ) {

            switch ( $set ) {

                case 'newVsReturning':
                    self::$sets[ $set ] = self::NEW_VS_RETURNING;
                    break;

                case 'channel':
                    // The configured rules' names, which a site's own file may change.
                    try {

                        self::$sets[ $set ] = Cube\ChannelStep::channels();

                    } catch ( \Throwable $e ) {

                        self::$sets[ $set ] = self::CHANNELS;
                    }
                    break;

                default:
                    self::$sets[ $set ] = [];
            }
        }

        return self::$sets[ $set ];
    }

    /**
     * A stored value as shown: translated when it is one of the set's labels.
     *
     * @param  string $set
     * @param  mixed  $value
     * @return mixed
     */
    public static function shown( $set, $value ) {

        if ( ! is_string( $value ) || ! in_array( $value, self::labels( $set ), true ) ) {

            return $value;
        }

        return \OWA\Core\Strings::translate( $value );
    }

    /**
     * A filter value as stored: the English label a translated (or English)
     * label stands for, in any case; any other value as given.
     *
     * @param  string $set
     * @param  mixed  $value
     * @return mixed
     */
    public static function stored( $set, $value ) {

        if ( ! is_string( $value ) ) {

            return $value;
        }

        $wanted = mb_strtolower( trim( $value ) );

        foreach ( self::labels( $set ) as $label ) {

            if ( mb_strtolower( $label ) === $wanted
                 || mb_strtolower( \OWA\Core\Strings::translate( $label ) ) === $wanted ) {

                return $label;
            }
        }

        return $value;
    }

    /**
     * Forget the sets read, so a changed conf/channels.php is read again. For
     * tests.
     *
     * @return void
     */
    public static function forget() {

        self::$sets = [];
    }
}
