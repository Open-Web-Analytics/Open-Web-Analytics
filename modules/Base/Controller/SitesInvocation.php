<?php
namespace OWA\Module\Base\Controller;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//



/**
 * Tracked Sites Tag Generator Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class SitesInvocation extends \OWA\Core\AdminController {

    function __construct($params) {

        $this->setRequiredCapability('edit_sites');
        return parent::__construct($params);
    }

    function action() {
        $site_id = $this->getParam('siteId');
        $this->set('site_id', $site_id);
        $s = \OWA\Core\CoreAPI::entityFactory('base.site');
        $s->getByColumn('site_id', $site_id);
        $this->set('site', $s);
        $this->setSubview('base.sitesInvocation');
        /*
         * The hierarchy wrapper: this is a Profile screen, reached from the site
         * control's nav. The install settings nav beside it would offer a way
         * out that has nothing to do with where you are.
         */
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        /* Tier 3: this screen is about an Observation Profile, so the context line stops there. */
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        $this->setView('base.optionsHierarchy');

        /*
         * The bundle the snippet below loads, there before anyone copies it: a
         * Profile from before bundles existed -- an install upgraded from 1.x
         * -- has none until something publishes it (PLAN 2.30.7).
         */
        $bundle = \OWA\Module\Base\Classes\TrackerBundle::path( (string) $site_id );

        if ( $bundle && ! is_file( $bundle ) && $s->wasPersisted()
             && $s->get( 'stream_type' ) === \OWA\Module\Base\Entity\Site::STREAM_WEB ) {

            \OWA\Module\Base\Classes\TrackerBundle::publishNow( (string) $site_id );
        }

        /*
         * Is the tag set up? Answered here, where the tag is, rather than on
         * every report: whether data is ARRIVING is this screen's question, and
         * whether reporting is READY is the reports' (Cube\Status::readiness()).
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

        $day = $db->get_row( sprintf( "SELECT MAX(yyyymmdd) AS day FROM %s WHERE site_id = '%s'",
            $raw, $db->prepare( (string) $site_id ) ) );

        if ( ! is_array( $day ) || empty( $day['day'] ) ) {

            return null;
        }

        $row = $db->get_row( sprintf(
            "SELECT MAX(ts) AS ts FROM %s WHERE site_id = '%s' AND yyyymmdd = %d",
            $raw, $db->prepare( (string) $site_id ), (int) $day['day'] ) );

        $ts = is_array( $row ) ? (int) ( $row['ts'] ?? 0 ) : 0;

        return $ts > 0 ? intdiv( $ts, 1000000 ) : null;
    }
}





?>
