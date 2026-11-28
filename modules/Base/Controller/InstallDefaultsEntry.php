<?php
namespace OWA\Module\Base\Controller;

/**
 * Install Configuration Controller
 */

class InstallDefaultsEntry extends \OWA\Core\Controller\Install {

    /** The form, which InstallBase draws again, filled in, when the install fails. */
    const SCREEN = [ 'template' => 'install_defaults_entry.php', 'vars' => [ 'defaults', 'zone_warning' ] ];

    public function action() {

        $this->set( 'zone_warning', self::zoneWarning() );
        $this->setView('base.install');
        $this->setSubview( 'base.installDefaultsEntry', self::SCREEN );
    }

    /**
     * The database's time zone warning, shown by the timezone field: the
     * database is connected by this step (owa-config.php exists). '' when
     * named zones convert or the server does not answer.
     *
     * @return string
     */
    public static function zoneWarning() {

        try {

            return (string) \OWA\Module\Base\Classes\DatabaseRequirement::timeZoneWarning();

        } catch ( \Throwable $e ) {

            return '';
        }
    }
}
