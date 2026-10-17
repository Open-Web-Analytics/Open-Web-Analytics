<?php
namespace OWA\Module\Base\Entity;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * One application password: a named credential a user makes for a REST API
 * client, revoked by deleting its row.
 *
 * Read and written by Classes\AppPasswords with its own SQL, so a revoked
 * password is never served from the entity cache. This declares the table so
 * install and the update create it.
 *
 * The secret itself is not stored: only an HMAC of the whole token.
 */
class AppPassword extends \OWA\Core\Entity {

    /** The index a request's token is looked up through. */
    const LOOKUP_INDEX = 'lookup_id_unique';

    /** The index a user's list is read through. */
    const USER_INDEX = 'user_id';

    protected $private_properties = [ 'secret_hash' ];

    function __construct() {

        $this->setTableName( 'app_password' );

        // Random, assigned at creation.
        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        // The token's first characters, which say which row to check it against.
        $lookup = new \OWA\Module\Base\Classes\DbColumn( 'lookup_id', OWA_DTD_VARCHAR16 );
        $lookup->setNotNull();
        $this->setProperty( $lookup );
        $this->addUniqueIndex( self::LOOKUP_INDEX, array( 'lookup_id' ) );

        // owa_user.user_id: the password acts as this user, with their role at
        // the time of each request.
        $user = new \OWA\Module\Base\Classes\DbColumn( 'user_id', OWA_DTD_VARCHAR255 );
        $user->setNotNull();
        $this->setProperty( $user );
        $this->addCompositeIndex( self::USER_INDEX, array( 'user_id' ) );

        // What the user called it, so they can tell which client it belongs to.
        $name = new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR255 );
        $name->setNotNull();
        $this->setProperty( $name );

        // HMAC-SHA256 of the whole token, hex.
        $hash = new \OWA\Module\Base\Classes\DbColumn( 'secret_hash', OWA_DTD_VARCHAR64 );
        $hash->setNotNull();
        $this->setProperty( $hash );

        // read | full
        $scope = new \OWA\Module\Base\Classes\DbColumn( 'scope', OWA_DTD_VARCHAR16 );
        $scope->setNotNull();
        $this->setProperty( $scope );

        foreach ( array( 'created_at', 'last_used_at' ) as $name ) {
            $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( $name, OWA_DTD_INT ) );
        }

        // Long enough for an IPv6 address in its longest written form.
        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'last_used_ip', OWA_DTD_VARCHAR45 ) );
    }
}

?>
