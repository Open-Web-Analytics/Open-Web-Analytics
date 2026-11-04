<?php
/**
 * The campaign parameters OWA reads off a landing URL: each role's own
 * parameter (the `ns` setting's default plus the role,
 * TrackingEventHelpers::CAMPAIGN_KEYS), its utm_ counterpart (UTM_KEYS), and
 * the raw column the value is stored in, from the property registry
 * (modules/Base/config/tracking_properties.json).
 */

use OWA\Core\Module;
use OWA\Module\Base\Classes\TrackingEventHelpers;

return function () {

    $ns       = (string) ( Module::settingsRegistry()['fields']['base|ns']['default'] ?? '' );
    $registry = json_decode( (string) file_get_contents( OWA_DIR . 'modules/Base/config/tracking_properties.json' ), true );

    if ( $ns === '' || ! is_array( $registry ) ) {
        throw new RuntimeException( 'Could not read the ns default or the property registry.' );
    }

    $out = "| Role | OWA parameter | `utm_` parameter | Stored in |\n|---|---|---|---|\n";

    foreach ( TrackingEventHelpers::CAMPAIGN_KEYS as $role => $property ) {

        $utm    = array_keys( TrackingEventHelpers::UTM_KEYS, $property, true );
        $column = $registry[ $property ]['column'] ?? null;

        $out .= sprintf( "| `%s` | `%s` | %s | %s |\n", $role, $ns . $role,
            $utm ? '`' . implode( '`, `', $utm ) . '`' : '—',
            $column ? '`' . $column . '`' : 'not stored' );
    }

    return $out;
};
