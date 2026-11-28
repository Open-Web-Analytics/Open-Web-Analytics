<?php
namespace OWA\Module\Domstream\Controller;

/**
 * Play: opens a recording's page with the player in its fragment.
 *
 * The Recordings report's Play link comes here, on OWA's own origin, and this
 * mints the player's overlay token at the click and redirects the browser to
 * the recorded page with #owa_overlay.<params>. The token lives for
 * OverlayToken::DEFAULT_TTL from then.
 *
 * Only for a user with view_reports on the recording's Property, and only a
 * page on the Profile's domain or a subdomain of it
 * (ProfileDomains::originOf()).
 */
class PlayerLauncher extends \OWA\Core\AdminController {

    use \OWA\Core\PropertyScopedAccess;

    public function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'view_reports' );
    }

    public function validate() {

        $this->addValidation( 'siteId', $this->getParam( 'siteId' ), 'required', [ 'stopOnError' => true ] );
        $this->addValidation( 'recording_id', $this->getParam( 'recording_id' ), 'required', [ 'stopOnError' => true ] );
    }

    public function action() {

        $site_id      = (string) $this->getParam( 'siteId' );
        $recording_id = (string) $this->getParam( 'recording_id' );

        $url = self::pageOf( $site_id, $recording_id );

        if ( $url === '' ) {

            $this->set( 'error_msg', \OWA\Core\CoreAPI::t( 'This recording cannot be played.' ) );
            $this->setView( 'base.error' );

            return;
        }

        $url .= '#owa_overlay.' . self::overlayParams( $site_id, $recording_id );

        // The tracked site is another host, vouched for: pageOf() opens only
        // a page of the Profile's own domain.
        $this->redirectBrowserToUrl( $url, [ (string) parse_url( $url, PHP_URL_HOST ) ] );
    }

    /**
     * The page a recording was made on, without its fragment, when it is a web
     * page on the Profile's domain or a subdomain of it; '' otherwise, and for
     * no such recording.
     *
     * @param  string $site_id
     * @param  string $recording_id
     * @return string
     */
    public static function pageOf( $site_id, $recording_id ) {

        if ( ! preg_match( '/^[1-9][0-9]{0,18}$/', (string) $recording_id ) ) {

            return '';
        }

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf(
            'SELECT MIN(page_location) AS page_location FROM %s WHERE site_id = ? AND recording_id = ?',
            \OWA\Core\CoreAPI::entityFactory( 'domstream.domstream_chunk' )->getTableName() ),
            [ (string) $site_id, (string) $recording_id ] );

        return self::playableUrl( is_array( $row ) ? (string) ( $row['page_location'] ?? '' ) : '', $site_id );
    }

    /**
     * A recorded location as the page Play opens: without its fragment, and ''
     * when it is not an http or https page on the Profile's domain or a
     * subdomain of it.
     *
     * @param  string $location
     * @param  string $site_id
     * @return string
     */
    public static function playableUrl( $location, $site_id ) {

        $url = explode( '#', (string) $location )[0];

        if ( \OWA\Module\Base\Classes\ProfileDomains::originOf( $url,
                \OWA\Module\Base\Classes\ProfileDomains::of( [ (string) $site_id ] ) ) === '' ) {

            return '';
        }

        return $url;
    }

    /**
     * The fragment's overlay params: the player's data link with a fresh
     * token, and the player's message for a failed fetch, base64 and
     * urlencoded.
     *
     * @param  string $site_id
     * @param  string $recording_id
     * @return string
     */
    public static function overlayParams( $site_id, $recording_id ) {

        $template = new \OWA\Core\Template();

        $api_url = $template->makeOverlayApiLink( [
            'recording_id' => (string) $recording_id,
            'siteId'       => (string) $site_id,
            'module'       => 'domstream',
            'version'      => 'v1',
            'do'           => 'domstreams',
        ], 'recording_id' );

        return rawurlencode( base64_encode( (string) json_encode( [
            'action'  => 'loadPlayer',
            'api_url' => $api_url,
            'strings' => [
                'fetchFailed' => \OWA\Core\CoreAPI::t( 'The recording could not be loaded.' ),
            ],
        ] ) ) );
    }
}
