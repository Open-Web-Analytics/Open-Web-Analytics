<?php

namespace OWA\Module\Base\Update;

/**
 * The scoped settings table, and the site blobs unpacked into it.
 *
 * owa_site.settings was a flat serialized map per site. It cannot express
 * "explicitly off": a key's absence is how it says "not set", so absent and
 * false are the same statement -- and "inherit" versus "override to false" is
 * exactly what a hierarchy needs to distinguish.
 *
 * The install blob (owa_configuration) is deliberately NOT migrated. Its
 * default-equivalence pruning is correct at that level -- it keeps installs
 * tracking code defaults rather than pinning stale copies -- and wrong at every
 * scoped level, where the thing a value is compared against is its parent, not
 * the code. Consolidating it is a separate decision with its own risk.
 *
 * The site blobs are COPIED, not moved. owa_site.settings is left intact, so a
 * rollback to the previous release still reads its own values.
 */
class Update022 extends \OWA\Core\Update {

    var $schema_version = 22;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        if ( ! $entity->createTable() ) {

            $this->e->notice( 'Creating owa_setting failed' );

            return false;
        }

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $site->getTableName() );
        $db->selectColumn( 'site_id, settings' );

        $copied = 0;

        foreach ( (array) $db->getAllRows() as $row ) {

            foreach ( self::planForSite( $row ) as $planned ) {

                $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

                $setting->set( 'id', $setting->makeId(
                    'profile', $planned['scope_id'], $planned['module'], $planned['name'] ) );
                $setting->set( 'scope_type', 'profile' );
                $setting->set( 'scope_id', $planned['scope_id'] );
                $setting->set( 'module', $planned['module'] );
                $setting->set( 'name', $planned['name'] );
                $setting->set( 'value', serialize( $planned['value'] ) );
                $setting->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
                $setting->create();

                $copied++;
            }
        }

        $this->e->notice( sprintf( 'Copied %d site setting(s) into owa_setting.', $copied ) );

        return true;
    }

    /**
     * What one site row becomes. Pure, so the unpacking is testable without a
     * database -- the same reason Update021's planner is.
     *
     * @param array $row site_id and its serialized settings blob
     * @return array
     */
    public static function planForSite( array $row ) {

        $siteId = isset( $row['site_id'] ) ? trim( (string) $row['site_id'] ) : '';

        if ( $siteId === '' ) {

            return array();
        }

        $settings = isset( $row['settings'] ) ? $row['settings'] : '';

        if ( is_string( $settings ) && $settings !== '' ) {

            /*
             * Guarded: a blob that will not unserialize is skipped rather than
             * allowed to fatal the migration. One unreadable row must not stop
             * every other site from being migrated.
             */
            $settings = @unserialize( $settings );
        }

        if ( ! is_array( $settings ) ) {

            return array();
        }

        $planned = array();

        foreach ( $settings as $name => $value ) {

            /*
             * 'base' because owa_site.settings was FLAT -- it had no module, so
             * every key in it is a Base key by construction. The column exists
             * so a module can hold a per-Profile setting from now on, which the
             * flat blob could not express without colliding with Base.
             */
            $planned[] = array(
                'scope_id' => $siteId,
                'module'   => 'base',
                'name'     => (string) $name,
                'value'    => $value,
            );
        }

        return $planned;
    }

    function down() {

        return false;
    }
}

?>
