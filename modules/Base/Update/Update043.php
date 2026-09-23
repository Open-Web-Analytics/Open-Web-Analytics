<?php

namespace OWA\Module\Base\Update;

/**
 * Unpack owa_configuration into rows, and retire it.
 *
 * Install-wide settings were one row holding a PHP-serialized nested array.
 * Scoped settings -- Organization, Property, Profile -- were already rows in
 * owa_setting. This makes install one more scope in that table, so there is
 * one store, one resolution query and one way to write a setting.
 *
 * What the blob could not do, and why this is worth a migration:
 *
 *   - Two writers to one row lose one of the two writes. Every setting shared
 *     a row, so two admins saving different screens at the same time was a
 *     lost update.
 *   - A key's ABSENCE is how a blob says "not set", so absent and false were
 *     the same statement. See CoreAPI::setScopedSetting().
 *   - Nothing could be read in part, so every setting was loaded on every
 *     request. The autoload column added here is what makes that a choice.
 *
 * The entity is used directly rather than through Settings, which is
 * deliberate: a migration writes the shape it is creating, and must keep
 * working when the application code above it moves on.
 *
 * NOT CLI-ONLY. It rewrites one small table -- 15 keys on the install this was
 * measured against -- and drops another.
 */
class Update043 extends \OWA\Core\Update {

    var $schema_version = 43;

    var $is_cli_mode_required = false;

    /**
     * The scope every unpacked value lands at.
     *
     * '1' rather than '0' because Entity::set() skips a falsy value on a string
     * column -- see Settings::INSTALL_SCOPE_ID, which must hold the same value
     * or a migrated install reads none of its own settings.
     */
    const INSTALL_SCOPE_ID = '1';

    function up( $force = false ) {

        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        if ( ! $this->addColumnIfMissing( $setting, 'autoload' ) ) {

            $this->e->notice( sprintf(
                'Add column autoload to %s failed', $setting->getTableName() ) );

            return false;
        }

        $legacy = \OWA\Core\CoreAPI::entityFactory( 'base.configuration' );

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $db->tableExists( $legacy->getTableName() ) ) {

            /*
             * Already unpacked, or a fresh install that never had the table.
             * Either way there is nothing to move and the end state is the one
             * this update is for, so it is a success and it can run twice.
             */
            $this->e->notice( sprintf(
                '%s is already gone; nothing to unpack.', $legacy->getTableName() ) );

            return true;
        }

        foreach ( $this->readBlob( $legacy ) as $module => $values ) {

            if ( ! is_array( $values ) ) {

                /*
                 * A scalar under a module key. Update012 records that
                 * db_settings has been seen holding one; there is no key to
                 * name a row after, so it is reported and dropped rather than
                 * guessed at.
                 */
                $this->e->notice( sprintf(
                    'Skipping non-array settings entry for module %s', $module ) );

                continue;
            }

            foreach ( $values as $name => $value ) {

                if ( ! $this->writeRow( $module, $name, $value ) ) {

                    $this->e->notice( sprintf(
                        'Writing setting %s.%s failed; leaving %s in place.',
                        $module, $name, $legacy->getTableName() ) );

                    return false;
                }
            }
        }

        if ( $legacy->dropTable() === false ) {

            $this->e->notice( sprintf(
                'Drop table %s failed', $legacy->getTableName() ) );

            return false;
        }

        /*
         * The store of record just changed under a running request, and
         * Settings memoises which one it is -- including for the save() that
         * apply() makes right after this returns, to persist schema_version.
         */
        $this->c->settingStoreRecheck();

        return true;
    }

    /**
     * Put the blob back, exactly.
     *
     * Recreate the table, repack every install row into it, delete those rows,
     * and drop the column. In that order: the rows are the only copy until the
     * blob is written, and the column cannot go while a row still needs it.
     *
     * Scoped rows -- organization, property, profile -- are NOT touched. They
     * predate this update and the blob has no place to put them.
     */
    function down() {

        $legacy = \OWA\Core\CoreAPI::entityFactory( 'base.configuration' );

        if ( $legacy->createTable() === false ) {

            $this->e->notice( sprintf(
                'Create table %s failed', $legacy->getTableName() ) );

            return false;
        }

        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $rows = (array) $db->get_results( sprintf(
            "SELECT module, name, value FROM %s WHERE scope_type = 'install'",
            $setting->getTableName() ) );

        $settings = array();

        foreach ( $rows as $row ) {

            $settings[ $row['module'] ][ $row['name'] ] =
                unserialize( (string) $row['value'], array( 'allowed_classes' => false ) );
        }

        if ( $settings && ! $this->writeBlob( $legacy, $settings ) ) {

            $this->e->notice( sprintf(
                'Writing %s failed; install rows left in place.',
                $legacy->getTableName() ) );

            return false;
        }

        /*
         * Before the rows go, so a save() landing between here and the end of
         * the rollback writes the blob rather than rows whose column is about
         * to be dropped. Update::rollback() makes exactly that save.
         */
        $this->c->settingStoreRecheck();

        foreach ( $rows as $row ) {

            $setting->delete( $setting->makeId(
                'install', self::INSTALL_SCOPE_ID, $row['module'], $row['name'] ) );
        }

        if ( ! $this->dropColumnIfPresent( $setting, 'autoload' ) ) {

            $this->e->notice( sprintf(
                'Drop column autoload from %s failed', $setting->getTableName() ) );

            return false;
        }

        return true;
    }

    /**
     * The blob's contents, or an empty array when it holds nothing usable.
     *
     * Array data only, as every other read of this row is: the row holds
     * scalars and arrays of them, and refusing objects means a tampered-with
     * row cannot instantiate a class during unserialize.
     *
     * @return array
     */
    private function readBlob( $legacy ) {

        $legacy->getByPk( 'id', $this->c->get( 'base', 'configuration_id' ) );

        $settings = unserialize(
            (string) $legacy->get( 'settings' ), array( 'allowed_classes' => false ) );

        return is_array( $settings ) ? $settings : array();
    }

    /**
     * @return boolean
     */
    private function writeBlob( $legacy, $settings ) {

        $id = $this->c->get( 'base', 'configuration_id' );

        $legacy->getByPk( 'id', $id );

        $legacy->set( 'settings', serialize( $settings ) );

        if ( $legacy->get( 'id' ) ) {

            return $legacy->update() !== false;
        }

        $legacy->set( 'id', $id );

        return $legacy->create() !== false;
    }

    /**
     * Upsert one install-scope row.
     *
     * Idempotent by construction: the id is derived from the scope, module and
     * name, so running this update twice writes the same rows rather than
     * duplicating them.
     *
     * autoload is 1 for everything moved out of the blob, because the blob was
     * read whole on every request. Opting a key out is a later, deliberate act
     * and not something a migration should decide.
     *
     * @return boolean
     */
    private function writeRow( $module, $name, $value ) {

        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        $id = $setting->makeId( 'install', self::INSTALL_SCOPE_ID, $module, $name );

        $setting->load( $id );

        $setting->set( 'scope_type', 'install' );
        $setting->set( 'scope_id', self::INSTALL_SCOPE_ID );
        $setting->set( 'module', $module );
        $setting->set( 'name', $name );
        $setting->set( 'value', serialize( $value ) );
        $setting->set( 'autoload', 1 );

        if ( $setting->wasPersisted() ) {

            return $setting->update() !== false;
        }

        $setting->set( 'id', $id );
        $setting->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

        return $setting->create() !== false;
    }
}

?>
