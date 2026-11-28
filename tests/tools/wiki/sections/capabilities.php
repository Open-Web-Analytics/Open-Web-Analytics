<?php
/**
 * Every capability and the roles that have it by default.
 *
 * Read from the default of the `capabilities` setting
 * (modules/Base/settings.php). An install can add to it in its config file;
 * the table is what ships.
 */

use OWA\Core\Module;

return function () {

    $fields = Module::settingsRegistry()['fields'];
    $roles  = (array) ( $fields['base|capabilities']['default'] ?? [] );

    $holders = [];

    foreach ( $roles as $role => $capabilities ) {

        foreach ( (array) $capabilities as $capability ) {
            $holders[ $capability ][] = '`' . $role . '`';
        }
    }

    $out = "| Capability | Roles that have it by default |\n|---|---|\n";

    foreach ( $holders as $capability => $by ) {

        $out .= sprintf( "| `%s` | %s |\n", $capability, implode( ', ', $by ) );
    }

    return $out;
};
