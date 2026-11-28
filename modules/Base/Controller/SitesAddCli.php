<?php
namespace OWA\Module\Base\Controller;

/**
 * cmd=add-site: adds a Profile as the Add Profile screen does
 * (Classes\AddsProfile), with the same capability checks.
 */

class SitesAddCli extends \OWA\Core\Controller\Cli {

    use \OWA\Core\PropertyScopedAccess, \OWA\Module\Base\Classes\AddsProfile {
        \OWA\Module\Base\Classes\AddsProfile::createsProperty insteadof \OWA\Core\PropertyScopedAccess;
        \OWA\Module\Base\Classes\AddsProfile::requestPropertyId insteadof \OWA\Core\PropertyScopedAccess;
    }

    /**
     * See \OWA\Core\Controller\Cli::usage().
     *
     * @return array{description: string, arguments: array<string,string>}
     */
    public static function usage() {

        return [
            'description' => 'Adds a tracked website or app as a Profile, under an existing Property or a new one.',
            'arguments'   => [
                'domain=<url>'         => 'Required for a website. Its domain.',
                'name=<text>'          => 'Required unless propertyId= is given. Names the new Property.',
                'propertyId=<id>'      => 'Add the Profile to this existing Property instead of creating one.',
                'streamType=<web|app>' => 'What the Profile observes. Defaults to web.',
                'appId=<id>'           => 'Required for an app. Its bundle ID or package name.',
                'description=<text>'   => 'A description.',
                'site_family=<text>'   => 'A family name to group it under.',
            ],
        ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_sites' );

        parent::__construct( $params );
    }

    public function action() {

        $this->addProfile();

        // Not created: exits 1, with nothing printed beyond what createSite() logged.
        if ( empty( $this->data['site'] ) ) {

            $this->markFailed( 'the Profile was not created', self::EXIT_FAILED );
        }
    }

    public function errorAction() {

        \OWA\Core\Controller\Cli::reportInvalidArguments( $this->data['validation_errors'] ?? [] );
    }

    public function success() {

        $site = (array) ( $this->data['site'] ?? [] );

        if ( $site ) {

            \OWA\Core\CoreAPI::notice( 'Site added successfully.' );
            \OWA\Core\CoreAPI::notice( "\n" . json_encode( $site, JSON_PRETTY_PRINT ) );
        }
    }
}
