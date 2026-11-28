<?php
namespace OWA\Module\Base\Controller;

/**
 * Make an application password for a user, and print it once.
 *
 *   cmd=app-password-create user=<user id> name="Nightly export"
 *   cmd=app-password-create user=<user id> name="Deploy hook" scope=full
 *
 * THE TOKEN GOES TO STDOUT AND NOWHERE ELSE. notice() also appends to OWA's
 * log file, so the record of what was made goes through it and the token is
 * written separately, as the last line, so `| tail -1` captures it.
 */
class AppPasswordCreateCli extends AppPasswordsCli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Makes an application password for a user and prints it. It is shown once and cannot be retrieved later.',
            'arguments'   => [
                'user' => [
                    'type'        => 'user',
                    'required'    => true,
                    'description' => 'The user the password authenticates as.',
                ],
                'name' => [
                    'type'        => 'string',
                    'value'       => '<name>',
                    'required'    => true,
                    'description' => 'A name for what will use it, shown in the user\'s list of application passwords.',
                ],
                'scope' => [
                    'type'        => 'select',
                    'options'     => [ \OWA\Module\Base\Classes\AppPasswords::SCOPE_READ, \OWA\Module\Base\Classes\AppPasswords::SCOPE_FULL ],
                    'default'     => \OWA\Module\Base\Classes\AppPasswords::SCOPE_READ,
                    'description' => 'read allows GET and HEAD requests only; full allows everything the user\'s role can do.',
                ],
            ],
        ];
    }

    public function action() {

        $user_id = (string) $this->param( 'user' );
        $name    = trim( (string) $this->param( 'name' ) );
        $scope   = (string) $this->param( 'scope' );

        $made = \OWA\Module\Base\Classes\AppPasswords::create( $user_id, $name, $scope );

        if ( ! $made ) {

            return $this->fail( 'The application password could not be created.' );
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Application password %s (%s, %s) created for %s.',
            $made['lookup_id'], $made['name'], $made['scope'], $user_id ) );

        if ( ! \OWA\Module\Base\Classes\AppPasswords::announce( $made, $user_id, '' ) ) {

            \OWA\Core\CoreAPI::notice( 'The account was not e-mailed; a notice is in its bell instead.' );
        }

        $this->write( [
            'Copy it now; it cannot be shown again:',
            $made['token'],
        ] );
    }
}
