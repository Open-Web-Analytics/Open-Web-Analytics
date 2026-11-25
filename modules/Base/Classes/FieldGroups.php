<?php
namespace OWA\Module\Base\Classes;

/**
 * What the metric and dimension choosers call a group: a dimension's family
 * (config/dimensions.php `family`) or a metric's group (config/metrics.php
 * `group`). The keys stay what the vocabularies declare, since code groups by
 * them; these are only the names shown, translated (Strings::VALUE_TABLES).
 *
 * A group no module here declares -- a third-party module's own -- is shown
 * as its key with a capital first letter.
 */
class FieldGroups {

    /** key => the name shown. */
    const LABELS = [
        'time'           => 'Time',
        'site'           => 'Site',
        'content'        => 'Content',
        'traffic source' => 'Traffic Source',
        'visitor'        => 'Visitor',
        'visit'          => 'Visit',
        'geography'      => 'Geography',
        'device'         => 'Device',
        'event'          => 'Event',
        'commerce'       => 'Commerce',
        'custom'         => 'Custom',
        'Site Usage'     => 'Site Usage',
        'Goals'          => 'Goals',
        'Ecommerce'      => 'Ecommerce',
    ];

    /**
     * The name shown for a group, in the locale in use.
     *
     * @param  string $key
     * @return string
     */
    public static function label( $key ) {

        $key = (string) $key;

        return isset( self::LABELS[ $key ] )
            ? \OWA\Core\Strings::translate( self::LABELS[ $key ] )
            : ucfirst( $key );
    }
}
