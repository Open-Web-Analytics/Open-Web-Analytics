<?php
namespace OWA\Module\Base\Controller;

/**
 * Reset Secret keys Command Line Interface (CLI) Controller
 */
class ResetSecretsCli extends \OWA\Core\Controller\Cli {

    /** What a rotation ends, said on success and in usage(). */
    const ENDS = 'This ends signed-in sessions, application passwords and the revoke links in their e-mails (OWA_AUTH_*), and outstanding nonces and overlay links (OWA_NONCE_*): a form on a page already open must be reloaded. Password reset and invitation links keep working.';

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Replaces OWA_NONCE_KEY, OWA_NONCE_SALT, OWA_AUTH_KEY and OWA_AUTH_SALT in owa-config.php with new random values; changes nothing unless each is defined exactly once. ' . self::ENDS,
            'arguments'   => [],
        ];
    }
    /**
     *  constructor.
     * @param $params
     */
    public function __construct( $params ) {
        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_settings' );
    }

    /**
     *
     */
    public function action() {

        // The same setting the loader and the installer use, so this rewrites
        // the file the install is actually running on.
        $config_file = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'config_file' );

        $result = \OWA\Module\Base\Classes\ConfigSecrets::rotate( $config_file );

        if ( $result['error'] !== null ) {

            return $this->fail( $result['error'] );
        }

        foreach ( $result['warnings'] as $warning ) {

            \OWA\Core\CoreAPI::notice( $warning );
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Rotated %s in %s. %s',
            implode( ', ', \OWA\Module\Base\Classes\ConfigSecrets::NAMES ), $result['path'], self::ENDS ) );
    }

    public function errorAction() {

        \OWA\Core\Controller\Cli::reportInvalidArguments( $this->data['validation_errors'] ?? [] );
    }

}
