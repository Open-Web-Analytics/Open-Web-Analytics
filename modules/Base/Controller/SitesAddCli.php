<?php
namespace OWA\Module\Base\Controller;

/**
 * Add Site Controller
 */

class SitesAddCli extends \OWA\Module\Base\Controller\SitesAdd {

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
    
    public function errorAction() {

        \OWA\Core\Controller\Cli::reportInvalidArguments( $this->data['validation_errors'] ?? [] );
    }

    /**
     * As a Controller\Cli does: invalid arguments exit 2, a refused capability
     * or a Profile not created exits 1.
     *
     * @return mixed
     */
    public function doAction() {

        $data = parent::doAction();
        $view = is_array( $data ) && isset( $data['view'] ) ? $data['view'] : null;
        $code = 0;

        if ( ! empty( $this->v ) && $this->v->hasErrors === true ) {

            $code = \OWA\Core\Controller\Cli::EXIT_USAGE;

        } elseif ( $view === 'base.error' || $view === 'base.genericCli' || empty( $this->data['site'] ) ) {

            $code = \OWA\Core\Controller\Cli::EXIT_FAILED;
        }

        if ( $code && ! \OWA\Core\Controller\Cli::$exit_code ) {

            \OWA\Core\Controller\Cli::$exit_code = $code;
        }

        return $data;
    }
    
    public function success() {

        $site = (array) ( $this->data['site'] ?? [] );

        if ( $site ) {

            \OWA\Core\CoreAPI::notice( 'Site added successfully.' );
            \OWA\Core\CoreAPI::notice( "\n" . json_encode( $site, JSON_PRETTY_PRINT ) );
        }
    }
}
