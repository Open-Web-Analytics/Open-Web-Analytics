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
     * @return array{description: string, arguments: array<string, array<string, mixed>>}
     */
    public static function usage() {

        return [
            'description' => 'Adds a tracked website or app as a Profile, under an existing Property or a new one.',
            'arguments'   => [
                'domain' => [
                    'type'        => 'string',
                    'value'       => '<url>',
                    'description' => 'Required for a website. Its domain.',
                ],
                'name' => [
                    'type'        => 'string',
                    'value'       => '<text>',
                    'description' => 'Required unless propertyId= is given. Names the new Property.',
                ],
                'propertyId' => [
                    'type'        => 'property',
                    'description' => 'Add the Profile to this existing Property instead of creating one.',
                ],
                'streamType' => [
                    'type'        => 'select',
                    'options'     => [ \OWA\Module\Base\Entity\Site::STREAM_WEB, \OWA\Module\Base\Entity\Site::STREAM_APP ],
                    'default'     => \OWA\Module\Base\Entity\Site::STREAM_WEB,
                    'description' => 'What the Profile observes.',
                ],
                'appId' => [
                    'type'        => 'string',
                    'value'       => '<id>',
                    'description' => 'Required for an app. Its bundle ID or package name.',
                ],
                'description' => [
                    'type'        => 'string',
                    'value'       => '<text>',
                    'description' => 'A description.',
                ],
                'site_family' => [
                    'type'        => 'string',
                    'value'       => '<text>',
                    'description' => 'A family name to group it under.',
                ],
            ],
        ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_sites' );

        parent::__construct( $params );
    }

    /**
     * See Classes\AddsProfile::profileInput(): the declared arguments, as their types.
     *
     * @param  string $name
     * @return mixed
     */
    protected function profileInput( $name ) {

        return $this->param( $name );
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
