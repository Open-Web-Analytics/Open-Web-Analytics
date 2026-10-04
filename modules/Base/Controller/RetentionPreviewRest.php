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
 *   raw=N             the install page's event-data window
 *   cube_default=N    the install page's reporting window
 *   property_id=ID    the Property page, with cube=N when overriding, or
 *   cube_inherit=1    when it takes the install's window
 */
class RetentionPreviewRest extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        // After the parent, as for every REST route reached with a session
        // (see NotificationsRest).
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $this->set( 'preview', \OWA\Module\Base\Classes\Retention::preview( self::proposed( (array) $this->params ) ) );
    }

    /**
     * The request's values as Retention::preview() takes them. Anything that
     * is not a whole number of months is left out rather than read as 0, so a
     * malformed request asks about no change rather than "keep everything".
     *
     * @param array $params
     * @return array
     */
    public static function proposed( array $params ) {

        $out = array();

        foreach ( array( 'raw' => 'raw', 'cube_default' => 'cube_default', 'cube' => 'cube' ) as $param => $key ) {

            if ( isset( $params[ $param ] ) && ctype_digit( (string) $params[ $param ] ) ) {

                $out[ $key ] = (int) $params[ $param ];
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

    function success() {

        http_response_code( 200 );
        $this->setView( 'base.retentionPreviewRest' );
    }
}

?>
