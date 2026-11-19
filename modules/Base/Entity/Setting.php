<?php

namespace OWA\Module\Base\Entity;

/**
 * One setting, at one scope.
 *
 * Every setting is a row here, at whichever scope owns it.
 *
 * A row per setting, not a serialized blob per scope: a blob cannot say
 * "explicitly off". A key's ABSENCE is how it says "not set",
 * so absent and false are the same statement -- and "inherit from my Property"
 * versus "override my Property to false" is precisely the distinction an
 * inheritance model exists to make. One row per setting can say it: a row means
 * this value, no row means ask my parent.
 *
 * It also makes settings queryable ("which Profiles log named users?"), which
 * unserializing every blob does not, and lets one key be written without
 * rewriting every other setting at that scope -- two concurrent writers to one
 * blob lose one of the two.
 */
class Setting extends \OWA\Core\Entity {

    public function __construct() {

        $this->setTableName( 'setting' );
        $this->setCachable();

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        /*
         * 'install', 'organization', 'property' or 'profile'.
         *
         * Install-wide values are here too (Update043). The default-equivalence
         * pruning is right install-wide and wrong below it, and lives in
         * Settings::persistSetting(). See CoreAPI::getSetting().
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
         * which had no module. The column lets a module hold a per-Profile
         * setting without colliding with a Base key of the same name.
         */
        $module = new \OWA\Module\Base\Classes\DbColumn( 'module', OWA_DTD_VARCHAR255 );
        $this->setProperty( $module );

        $name = new \OWA\Module\Base\Classes\DbColumn( 'name', OWA_DTD_VARCHAR255 );
        $this->setProperty( $name );

        /*
         * Serialized, not typed. A setting is whatever the code default says it
         * is -- bool, int, string, array -- and typing the column would mean
         * deciding a type per key.
         */
        $value = new \OWA\Module\Base\Classes\DbColumn( 'value', OWA_DTD_TEXT );
        $this->setProperty( $value );

        /*
         * Written 1 by both write paths, and read by nothing: what boot
         * fetches is decided by the settings registry, by name
         * (Settings::eagerPredicate()). Not indexed, as no query filters on it.
         *
         * NOT NULL default 1, so a row written by code that has never heard of
         * this column holds what every other row holds.
         */
        $autoload = new \OWA\Module\Base\Classes\DbColumn( 'autoload', OWA_DTD_BOOLEAN );
        $autoload->setDefaultValue( 1 );
        $autoload->setNotNull();
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
     *
     * @param  string     $scope_type
     * @param  int|string $scope_id
     * @param  string     $module
     * @param  string     $name
     * @return int|string|null see Entity::generateId()
     */
    public function makeId( $scope_type, $scope_id, $module, $name ) {

        return $this->generateId( $scope_type . '|' . $scope_id . '|' . $module . '|' . $name );
    }
}
