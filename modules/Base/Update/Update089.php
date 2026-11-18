<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_user.api_key is dropped. The legacy API key signs nothing in (a request
 * carrying one is refused, see Auth::carriesLegacyApiKey()) and no user is
 * given one.
 *
 * Idempotent: with no api_key column up() changes nothing.
 *
 * down() adds the column back, empty, where 1.x had it.
 *
 * NOT CLI-ONLY. One small table.
 */
class Update089 extends \OWA\Core\Update {

    public $schema_version = 89;

    public $down_does_not_restore = 'the users\' API keys: the api_key column comes back empty.';

    const COLUMN = 'api_key';

    /** The column 1.x placed it after. */
    const AFTER = 'last_update_date';

    /**
     * The user table's columns, in order.
     *
     * @return list<string>
     */
    private function columns() {

        $names = [];

        foreach ( (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf( 'SHOW COLUMNS FROM %s', $this->tableFor( 'base.user' ) ) ) as $row ) {

            $names[] = (string) ( (array) $row )['Field'];
        }

        return $names;
    }

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        if ( ! in_array( self::COLUMN, $this->columns(), true ) ) {

            return true;
        }

        if ( \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'ALTER TABLE %s DROP COLUMN %s', $this->tableFor( 'base.user' ), self::COLUMN ) ) === false ) {

            $this->e->notice( sprintf( 'Removing %s from %s failed.', self::COLUMN, $this->tableFor( 'base.user' ) ) );

            return false;
        }

        return true;
    }

    public function down() {

        $columns = $this->columns();

        if ( in_array( self::COLUMN, $columns, true ) ) {

            return true;
        }

        if ( \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'ALTER TABLE %s ADD COLUMN %s %s DEFAULT NULL%s',
                $this->tableFor( 'base.user' ), self::COLUMN, OWA_DTD_VARCHAR255,
                in_array( self::AFTER, $columns, true ) ? ' AFTER ' . self::AFTER : '' ) ) === false ) {

            $this->e->notice( sprintf( 'Adding %s to %s failed.', self::COLUMN, $this->tableFor( 'base.user' ) ) );

            return false;
        }

        return true;
    }
}
