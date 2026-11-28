<?php
namespace OWA\Module\Base\Controller;

/**
 * Revoke one of a user's application passwords, or all of them.
 *
 *   cmd=app-password-revoke user=<user id> id=<id>
 *   cmd=app-password-revoke user=<user id> --all
 *
 * The id is the one cmd=app-password-list prints. It has to belong to the
 * user named, so a mistyped user revokes nothing.
 */
class AppPasswordRevokeCli extends AppPasswordsCli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Revokes a user\'s application password, or all of them. Anything using one stops working immediately.',
            'arguments'   => [
                'user' => [
                    'type'        => 'user',
                    'required'    => true,
                    'description' => 'The user the password belongs to.',
                ],
                'id' => [
                    'type'        => 'string',
                    'value'       => '<id>',
                    'description' => 'The password\'s id, from app-password-list. Required unless --all is given.',
                ],
                '--all' => [
                    'type'        => 'flag',
                    'description' => 'Revoke every application password the user has.',
                ],
            ],
        ];
    }

    public function action() {

        $user_id = (string) $this->param( 'user' );

        if ( $this->param( 'all' ) ) {

            $count = count( \OWA\Module\Base\Classes\AppPasswords::forUser( $user_id ) );

            if ( ! \OWA\Module\Base\Classes\AppPasswords::revokeAllForUser( $user_id ) ) {

                return $this->fail( sprintf( 'The application passwords of %s could not be revoked.', $user_id ) );
            }

            return \OWA\Core\CoreAPI::notice( sprintf( '%d application password%s of %s revoked.', $count, $count === 1 ? '' : 's', $user_id ) );
        }

        $lookup_id = trim( (string) $this->param( 'id' ) );

        if ( $lookup_id === '' ) {

            return $this->usageError( 'id=<id> or --all is required. cmd=app-password-list shows the ids.' );
        }

        if ( ! \OWA\Module\Base\Classes\AppPasswords::revoke( $lookup_id, $user_id ) ) {

            return $this->fail( sprintf( '%s has no application password %s.', $user_id, $lookup_id ) );
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Application password %s of %s revoked.', $lookup_id, $user_id ) );
    }
}
