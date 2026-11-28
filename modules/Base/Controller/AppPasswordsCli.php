<?php
namespace OWA\Module\Base\Controller;

/**
 * What the three app-password commands have in common.
 *
 * The CLI runs as an administrator and acts on whichever user it is told,
 * so these are the one place a password is made for someone else: the person
 * at the shell can already read the database.
 */
abstract class AppPasswordsCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::serverRoles(). app-password-create, -list and -revoke: the same administration the console's screens offer. */
    public static function serverRoles() {

        return [ \OWA\Core\ServerRole::FULL, \OWA\Core\ServerRole::WORKER, \OWA\Core\ServerRole::CONSOLE ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_users' );

        parent::__construct( $params );
    }
}
