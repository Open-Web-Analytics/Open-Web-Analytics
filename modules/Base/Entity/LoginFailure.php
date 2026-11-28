<?php
namespace OWA\Module\Base\Entity;

/**
 * Failed sign-ins, counted per user name and per client address
 * (Classes\LoginThrottle). One row per name or address that has failed
 * recently; a row is deleted once its failures no longer count.
 *
 * `subject` is a SHA-256 of what was counted, prefixed by which: a user name
 * field sometimes holds a mistyped password.
 *
 * Read and written by Classes\LoginThrottle with its own SQL.
 */
class LoginFailure extends \OWA\Core\Entity {

    public function __construct() {

        $this->setTableName( 'login_failure' );

        $subject = new \OWA\Module\Base\Classes\DbColumn( 'subject', OWA_DTD_VARCHAR64 );
        $subject->setPrimaryKey();
        $this->setProperty( $subject );

        $failures = new \OWA\Module\Base\Classes\DbColumn( 'failures', OWA_DTD_INT );
        $failures->setNotNull();
        $failures->setDefaultValue( 0 );
        $this->setProperty( $failures );

        // Unix times of the first failure counted and of the latest.
        $first = new \OWA\Module\Base\Classes\DbColumn( 'first_failure_at', OWA_DTD_INT );
        $first->setNotNull();
        $first->setDefaultValue( 0 );
        $first->setIndex();
        $this->setProperty( $first );

        $last = new \OWA\Module\Base\Classes\DbColumn( 'last_failure_at', OWA_DTD_INT );
        $last->setNotNull();
        $last->setDefaultValue( 0 );
        $this->setProperty( $last );
    }
}
