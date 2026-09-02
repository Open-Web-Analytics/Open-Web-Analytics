<?php

namespace OWA\Module\Base\Entity;

/**
 * One setting, at one scope.
 *
 * Settings lived in two serialized blobs: owa_configuration held every module's
 * install-wide values in a single row, and owa_site.settings held a flat map
 * per site. Neither can express what a hierarchy needs.
 *
 * A blob cannot say "explicitly off". A key's ABSENCE is how it says "not set",
 * so absent and false are the same statement -- and "inherit from my Property"
 * versus "override my Property to false" is precisely the distinction an
 * inheritance model exists to make. One row per setting can say it: a row means
 * this value, no row means ask my parent.
 *
 * It also makes settings queryable ("which Profiles log robots?"), which
 * unserializing every blob does not, and lets one key be written without
 * rewriting every other setting at that scope -- two concurrent writers to one
 * blob lose one of the two.
 */
class Setting extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'setting' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        /*
         * 'organization', 'property' or 'profile'. Install-wide values are NOT
         * here -- they stay in owa_configuration, whose default-equivalence
         * pruning is correct at that level and wrong at every other. See
         * CoreAPI::getSetting().
         */
        $scope_type = new \OWA\Module\Base\Classes\DbColumn( 'scope_type', OWA_DTD_VARCHAR255 );
        $scope_type->setIndex();
        $this->setProperty( $scope_type );

        $scope_id = new \OWA\Module\Base\Classes\DbColumn( 'scope_id', OWA_DTD_VARCHAR255 );
        $scope_id->setIndex();
        $this->setProperty( $scope_id );

        /*
         * Defaults to 'base' for values migrated out of owa_site.settings,
         * which was flat and had no module. Carrying the column means a module
         * can hold a per-Profile setting at all -- in the flat blob it would
         * have collided with a Base key of the same name.
         */
        $module = new \OWA\Module\Base\Classes\DbColumn( 'module', OWA_DTD_VARCHAR255 );
        $this->setProperty( $module );

        $name = new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR255 );
        $this->setProperty( $name );

        /*
         * Serialized, not typed. A setting is already whatever the code default
         * says it is -- bool, int, string, array -- and the blob it replaces
         * stored all of those. Typing the column would mean deciding a type per
         * key, which is a bigger change than this one.
         */
        $value = new \OWA\Module\Base\Classes\DbColumn( 'value', OWA_DTD_TEXT );
        $this->setProperty( $value );

        $creation_date = new \OWA\Module\Base\Classes\DbColumn( 'creation_date', OWA_DTD_BIGINT );
        $this->setProperty( $creation_date );
    }

    /**
     * The id a scope/module/name triple always hashes to.
     *
     * Deterministic so a write is an upsert rather than an insert-or-search:
     * the same setting at the same scope is always the same row, which is what
     * keeps two writers from producing two rows that disagree.
     */
    public function makeId( $scope_type, $scope_id, $module, $name ) {

        return $this->generateId( $scope_type . '|' . $scope_id . '|' . $module . '|' . $name );
    }
}

?>
