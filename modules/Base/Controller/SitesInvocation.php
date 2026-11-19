<?php
namespace OWA\Module\Base\Controller;

/**
 * Tracked Sites Tag Generator Controller
 */

class SitesInvocation extends \OWA\Core\AdminController {

    public function __construct($params) {

        $this->setRequiredCapability('edit_sites');
        parent::__construct($params);
    }

    public function action() {
        $site_id = $this->getParam('siteId');
        $this->set('site_id', $site_id);
        $s = \OWA\Module\Base\Entity\Site::forSiteId( $site_id );
        $this->set('site', $s);
        $this->setSubview('base.sitesInvocation');
        /*
         * The settings chrome: this is a Profile screen, reached from the site
         * control's nav. The install settings nav beside it would offer a way
         * out that has nothing to do with where you are.
         */
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], $owa_site_id );

        /*
         * Is the tag set up? Answered here, where the tag is, rather than on
         * every report: whether data is ARRIVING is this screen's question, and
         * whether reporting is READY is the reports' (Cube\Status::readinessOfProperty()).
         */
        $this->set( 'last_event', self::lastEventReceived( $site_id ?: $owa_site_id ) );
    }

    /**
     * When this Profile's most recent event reached raw, or null if none has.
     *
     * The newest day first, then the newest event within it: both lookups
     * stay on raw's (site_id, yyyymmdd) index, where one MAX(ts) over the
     * Profile would read every row it has.
     *
     * @param string $site_id
     * @return int|null unix seconds
     */
    public static function lastEventReceived( $site_id ) {

        if ( (string) $site_id === '' ) {

            return null;
        }

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();

        $day = $db->get_row( sprintf( 'SELECT MAX(yyyymmdd) AS day FROM %s WHERE site_id = ?', $raw ),
            [ (string) $site_id ] );

        if ( ! is_array( $day ) || empty( $day['day'] ) ) {

            return null;
        }

        $row = $db->get_row( sprintf(
            'SELECT MAX(ts) AS ts FROM %s WHERE site_id = ? AND yyyymmdd = %d',
            $raw, (int) $day['day'] ), [ (string) $site_id ] );

        $ts = is_array( $row ) ? (int) ( $row['ts'] ?? 0 ) : 0;

        return $ts > 0 ? intdiv( $ts, 1000000 ) : null;
    }
}
