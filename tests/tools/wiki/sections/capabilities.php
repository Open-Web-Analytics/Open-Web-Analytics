<?php
/**
 * Every capability, the roles that have it by default, and whether a user
 * holds it only on the Profiles they have been given access to.
 *
 * Read from the defaults of the `capabilities` and
 * `capabilitiesThatRequireSiteAccess` settings (modules/Base/settings.php). An
 * install can change either in its config file; the table is what ships.
 */

use OWA\Core\Module;

return function () {

    $fields = Module::settingsRegistry()['fields'];
    $roles  = (array) ( $fields['base|capabilities']['default'] ?? array() );
    $scoped = (array) ( $fields['base|capabilitiesThatRequireSiteAccess']['default'] ?? array() );

    $holders = array();

    foreach ( $roles as $role => $capabilities ) {

        foreach ( (array) $capabilities as $capability ) {
            $holders[ $capability ][] = '`' . $role . '`';
        }
    }

    $out = "| Capability | Roles that have it by default | Needs access to the Profile |\n|---|---|---|\n";

    foreach ( $holders as $capability => $by ) {

        $out .= sprintf( "| `%s` | %s | %s |\n", $capability, implode( ', ', $by ),
            in_array( $capability, $scoped, true ) ? 'Yes' : 'No' );
    }

    return $out;
};
