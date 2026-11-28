<?php

/**
 * Runs a callable with the current user as an administrator, who has every
 * Property on their access list, and puts the user's role back after.
 */
trait AsAdministrator
{
    private static function asAdministrator( callable $fn )
    {
        $user = \OWA\Core\CoreAPI::getCurrentUser();
        $role = $user->getRole();
        $user->setRole( 'admin' );

        try {
            return $fn();
        } finally {
            $user->setRole( $role );
        }
    }
}
