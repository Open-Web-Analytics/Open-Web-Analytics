<?php
/**
 * The fixed limits on custom dimensions, each read from the constant the code
 * enforces:
 *
 *   Cube\Dimensions::KEY_PATTERN, MAX_PER_PROPERTY, DIMENSION_LENGTH
 *   Entity\CustomDimension::PREFIX, SET_TS_SUFFIX
 *   Handler\EventRawHandlers::MAX_CUSTOM_PROPERTIES
 */

use OWA\Module\Base\Classes\Cube\Dimensions;
use OWA\Module\Base\Entity\CustomDimension;

return static function () {

    // The longest key the pattern takes: a first character, then {0,N} more.
    if ( ! preg_match( '/\{0,(\d+)\}\$\/$/', Dimensions::KEY_PATTERN, $m ) ) {
        throw new RuntimeException( 'Dimensions::KEY_PATTERN no longer ends in {0,N}; say its length here another way.' );
    }

    $pattern = substr( Dimensions::KEY_PATTERN, 1, -1 );

    $rows = array(
        'A key'
            => sprintf( 'matches `%s`: a letter, then letters, digits and underscores, up to %d characters',
                $pattern, (int) $m[1] + 1 ),
        'Its column'
            => sprintf( '`%s<key>`, lowercased', CustomDimension::PREFIX ),
        'The companion column of a `' . CustomDimension::SCOPE_USER . '` dimension'
            => sprintf( '`%s<key>%s`, when the value was set', CustomDimension::PREFIX, CustomDimension::SET_TS_SUFFIX ),
        'Dimensions per Property'
            => sprintf( '%d, or fewer if the database server\'s row size limit allows fewer', Dimensions::MAX_PER_PROPERTY ),
        'A `' . CustomDimension::TYPE_STRING . '` value'
            => sprintf( 'up to %d characters; a build truncates a longer one', Dimensions::DIMENSION_LENGTH ),
        'Custom properties kept on one event, per scope'
            => sprintf( '%d; ingest drops any beyond that and still records the event',
                \OWA\Module\Base\Handler\EventRawHandlers::MAX_CUSTOM_PROPERTIES ),
    );

    $out = "| Limit | Value |\n|---|---|\n";

    foreach ( $rows as $limit => $value ) {
        $out .= "| $limit | " . str_replace( '|', '\\|', $value ) . " |\n";
    }

    return $out;
};
