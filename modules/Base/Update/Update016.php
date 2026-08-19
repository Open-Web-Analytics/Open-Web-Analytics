<?php
namespace OWA\Module\Base\Update;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Mark this installation's dimension ids as the old 32-bit ones.
 *
 * Content-derived ids are 63-bit from this version on. A NEW installation gets
 * that for free: Core\Module::install() builds the schema from the entity
 * definitions and stamps the required version directly, without running any
 * update class, so it never reaches this code and never carries the flag.
 *
 * An EXISTING installation is the case this exists for. Its dimension tables are
 * full of crc32 ids and its fact rows point at them, so it must keep deriving
 * crc32 until those have been re-derived. Writing the flag makes that explicit
 * and, more importantly, makes it REMOVABLE: the migration command clears it
 * when the last id has been converted, and the installation then falls through
 * to the 63-bit default like any new one. Nothing is left behind to explain.
 *
 * Note this update is not what protects the gap between new files landing and
 * cmd=update being run -- tracking keeps ingesting during that window and this
 * has not executed yet. Lib::useNarrowGuid() covers it by reading the stored
 * schema version, which is already loaded at boot.
 *
 * Writing one setting takes no locks worth scheduling, so this does not require
 * CLI mode. The expensive part is the migration, which is deliberately a command
 * an administrator runs when it suits them, exactly as partition-init is.
 */
class Update016 extends \OWA\Core\Update {

    var $schema_version = 16;

    var $is_cli_mode_required = false;

    function up($force = false) {

        \OWA\Core\CoreAPI::persistSetting( 'base', 'use_32bit_hash', true );

        $this->e->notice(
            'This installation keeps 32-bit dimension ids until they are migrated. '
          . 'Run cmd=rederive-dimension-ids when it suits you; ids stay consistent until then.'
        );

        return true;
    }

    function down() {

        \OWA\Core\CoreAPI::persistSetting( 'base', 'use_32bit_hash', false );

        return true;
    }
}
