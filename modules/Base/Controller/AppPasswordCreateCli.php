<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

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

        return array(
            'description' => 'Makes an application password for a user and prints it. It is shown once and cannot be retrieved later.',
            'arguments'   => array(
                'user=<user id>'   => 'Required. The user the password authenticates as.',
                'name=<name>'      => 'Required. What will use it, so it can be told apart from the user\'s others.',
                'scope=read|full'  => 'read allows GET requests only; full allows everything the user\'s role can do. Defaults to read.',
            ),
        );
    }

    function action() {

        $user_id = $this->user();

        if ( $user_id === '' ) {

            return;
        }

        $name  = trim( (string) $this->getParam( 'name' ) );
        $scope = trim( (string) $this->getParam( 'scope' ) ) ?: \OWA\Module\Base\Classes\AppPasswords::SCOPE_READ;

        if ( $name === '' ) {

            return $this->refuse( 'name=<name> is required.' );
        }

        if ( ! isset( \OWA\Module\Base\Classes\AppPasswords::scopes()[ $scope ] ) ) {

            return $this->refuse( 'scope= takes read or full.' );
        }

        $made = \OWA\Module\Base\Classes\AppPasswords::create( $user_id, $name, $scope );

        if ( ! $made ) {

            return $this->fail( 'The application password could not be created.' );
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Application password %s (%s, %s) created for %s.',
            $made['lookup_id'], $made['name'], $made['scope'], $user_id ) );

        $this->write( array(
            'Copy it now; it cannot be shown again:',
            $made['token'],
        ) );
    }
}

?>
