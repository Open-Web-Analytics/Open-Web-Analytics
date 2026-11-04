<?php
/**
 * An application password's access levels: AppPasswords::scopes() for the
 * labels the screens show, and AppPasswords::READ_METHODS, the methods
 * Core\Auth accepts a read-only password on. Any other scope is limited only
 * by the user's role.
 */

use OWA\Module\Base\Classes\AppPasswords;

return static function () {

    $out = "| Access | `scope=` | Allows |\n|---|---|---|\n";

    foreach ( AppPasswords::scopes() as $scope => $label ) {

        $allows = $scope === AppPasswords::SCOPE_READ
            ? '`' . implode( '` and `', AppPasswords::READ_METHODS ) . '` requests'
            : 'Every request the user\'s role allows';

        $out .= sprintf( "| **%s** | `%s` | %s |\n", $label, $scope, $allows );
    }

    return $out;
};
