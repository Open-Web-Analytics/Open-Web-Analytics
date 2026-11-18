<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * GET v1/retentionPreview -- what saving the Data Retention form would do.
 *
 * Read-only: it plans and describes, and stores nothing. The browser asks it
 * before a save and shows the confirmation it returns (owa.retention.js).
 *
 *   raw=N             the install page's event-data window; blank keeps everything
 *   cube_default=N    the install page's reporting window; blank is raw's
 *   property_id=ID    the Property page, with cube=N when overriding, or
 *   cube_inherit=1    when it takes the install's window
 */
class RetentionPreviewRest extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        parent::__construct( $params );

        // After the parent, as for every REST route reached with a session
        // (see NotificationsRest).
        $this->setRequiredCapability( 'edit_settings' );
    }

    public function action() {

        $this->set( 'response', [ 'preview' => (array) \OWA\Module\Base\Classes\Retention::preview( self::proposed( (array) $this->params ) ) ] );
    }

    /**
     * The request's values as Retention::preview() takes them. A blank value is
     * the field left empty, which keeps everything (0). Anything else that is
     * not a whole number of months is left out, so a malformed request asks
     * about no change rather than "keep everything".
     *
     * @param array<string, mixed> $params
     * @return array<string, int|string> raw, cube_default and cube in months; property_id
     */
    public static function proposed( array $params ) {

        $out = [];

        foreach ( [ 'raw' => 'raw', 'cube_default' => 'cube_default', 'cube' => 'cube' ] as $param => $key ) {

            if ( ! array_key_exists( $param, $params ) || $params[ $param ] === null ) {

                continue;
            }

            $value = trim( (string) $params[ $param ] );

            if ( $value === '' ) {

                $out[ $key ] = 0;

            } elseif ( ctype_digit( $value ) ) {

                $out[ $key ] = (int) $value;
            }
        }

        if ( ! empty( $params['property_id'] ) && ctype_digit( (string) $params['property_id'] ) ) {

            $out['property_id'] = (string) $params['property_id'];

            // Taking the install's window again: preview it as that window.
            if ( ! empty( $params['cube_inherit'] ) ) {

                $out['cube'] = \OWA\Module\Base\Classes\Retention::months(
                    \OWA\Core\CoreAPI::getSetting( 'base', \OWA\Module\Base\Classes\Retention::CUBE ) );
            }
        }

        return $out;
    }

    public function success() {

        $this->restResponse( 200, 'response' );
    }
}
