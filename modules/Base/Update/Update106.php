<?php
namespace OWA\Module\Base\Update;

use OWA\Module\Base\Classes\ConfigSecrets;

/**
 * OWA_SECRET in owa-config.php: where it is not defined, a generated one is
 * added (ConfigSecrets::addInstanceSecret()), through cmd=reset-secrets'
 * rewrite path: a temporary file readable by its owner only, given the old
 * file's mode, renamed over it. A file that cannot be written fails the
 * update with the line to add by hand, without a value.
 *
 * From this schema version every role with a database needs OWA_SECRET
 * (ServerRole::SECRET_SCHEMA_VERSION). The private files (the error log,
 * the file queue's inbox) are named from it, so they have new names from
 * the next run; the queue takes in its inbox files under any name, and
 * System Health names the error logs under the old one.
 *
 * THE INVERSE. Before the file changes, the SHA-256 of the lines added is
 * kept in the owa_secret_before_106 setting; never the value. down() takes
 * out those lines only while the file holds them exactly, and removes the
 * record. A defined OWA_SECRET changes nothing and keeps no record, so
 * down() leaves it.
 *
 * CLI-ONLY. It writes owa-config.php, which the web server should not be
 * able to.
 */
class Update106 extends \OWA\Core\Update {

    use SettingRows;

    public $schema_version = 106;

    /** @var bool */
    public $is_cli_mode_required = true;

    /** What up() added: { lines: SHA-256 of ConfigSecrets::instanceSecretLines() }. */
    const BEFORE = 'owa_secret_before_106';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /** @var string|null the config file instead of base.config_file. TESTS ONLY. */
    public $config_file = null;

    /** @var bool|null whether OWA_SECRET is defined, instead of this process's answer. TESTS ONLY. */
    public $secret_defined = null;

    /** @var callable|null fn () => string, the value written. TESTS ONLY. */
    public $random = null;

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        if ( $this->secret_defined ?? defined( \OWA\Core\Requirements::INSTANCE_SECRET ) ) {

            return true;
        }

        $path  = $this->configFile();
        $value = (string) ( $this->random ? call_user_func( $this->random ) : \OWA\Core\CoreAPI::secureRandomString( ConfigSecrets::LENGTH ) );

        // Kept before the file changes, so a run that stops part way can still be undone.
        if ( ! $this->writeRecord( serialize( [ 'lines' => hash( 'sha256', ConfigSecrets::instanceSecretLines( $value ) ) ] ) ) ) {

            $this->e->notice( sprintf( 'Keeping a record of the OWA_SECRET lines in %s failed.', self::BEFORE ) );

            return false;
        }

        $result = ConfigSecrets::addInstanceSecret( $path, static fn () => $value );

        foreach ( $result['warnings'] as $warning ) {

            $this->e->notice( $warning );
        }

        if ( $result['error'] !== null ) {

            $this->e->notice( $result['error'] );

            return false;
        }

        if ( $result['written'] === null ) {

            $this->e->notice( sprintf( '%s has a define() of OWA_SECRET, but OWA_SECRET was not defined when it was read. It is left as it is.', $result['path'] ) );

            return $this->deleteRecord();
        }

        $this->e->notice( sprintf( 'Added a generated OWA_SECRET to %s. The error log and the file queue\'s inbox files are named from it from the next run.', $result['path'] ) );

        return true;
    }

    /** The exact inverse: the lines up() added, while the file holds them as added. */
    public function down() {

        $record = $this->record();

        if ( ! $record ) {

            return true;
        }

        $result = ConfigSecrets::removeInstanceSecret( $this->configFile(), (string) ( $record['lines'] ?? '' ) );

        foreach ( $result['warnings'] as $warning ) {

            $this->e->notice( $warning );
        }

        if ( $result['error'] !== null ) {

            $this->e->notice( $result['error'] );

            return false;
        }

        if ( ! $result['removed'] ) {

            $this->e->notice( sprintf( '%s no longer holds the OWA_SECRET lines Update106 added, so it is left as it is.', $result['path'] ) );
        }

        if ( ! $this->deleteRecord() ) {

            $this->e->notice( sprintf( 'Removing %s failed.', self::BEFORE ) );

            return false;
        }

        return true;
    }

    /**
     * @return string
     */
    private function configFile() {

        return $this->config_file ?? (string) \OWA\Core\CoreAPI::getSetting( 'base', 'config_file' );
    }
}
