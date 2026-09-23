<?php

namespace OWA\Module\Base\Entity;

/**
 * One setting, at one scope.
 *
 * Settings lived in three places: owa_configuration held every module's
 * install-wide values in a single serialized row, owa_site.settings held a
 * flat map per site, and this table held the scoped values. Every setting is
 * now a row here, at whichever scope owns it.
 *
 * A blob cannot express what a hierarchy needs, and it could not express
 * install-wide state either -- two concurrent writers to one row lose one of
 * the two writes, whatever scope they were writing at.
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
         * 'install', 'organization', 'property' or 'profile'.
         *
         * Install-wide values are here too, since Update043 unpacked
         * owa_configuration into rows. The pruning rule that kept them apart
         * did not go away -- it is still right install-wide and still wrong
         * below it -- it just lives in Settings::persistSetting() rather than
         * being implied by which table a value landed in. See
         * CoreAPI::getSetting().
         *
         * Install rows carry scope_id '1': there is one install, and giving it
         * an id keeps every row's key shape the same so the resolution query
         * needs no special case for the top of the chain. Not '0' -- set()
         * drops a falsy value on a string column, so that id would store as ''
         * and never match. See Settings::INSTALL_SCOPE_ID.
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

        /*
         * Whether load() fetches this row at boot.
         *
         * Every setting the blob held was read on every request, because the
         * blob was one row and reading part of it was not a thing you could
         * do. Rows make it a choice, and WordPress's wp_options.autoload is
         * the same choice for the same reason. Indexed because the boot query
         * filters on it and nothing else narrows the install scope.
         *
         * NOT NULL default 1, so a row written by code that has never heard of
         * this column is loaded at boot -- which is what it would have got
         * from the blob. Opting OUT is the deliberate act.
         */
        $autoload = new \OWA\Module\Base\Classes\DbColumn( 'autoload', OWA_DTD_BOOLEAN );
        $autoload->setDefaultValue( 1 );
        $autoload->setNotNull();
        $autoload->setIndex();
        $this->setProperty( $autoload );

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
