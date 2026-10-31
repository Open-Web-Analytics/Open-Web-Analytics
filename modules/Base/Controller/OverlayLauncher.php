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
 * Overlay Launcher Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class OverlayLauncher extends \OWA\Core\Controller {

    function action() {

        $url = '';

        if ( $this->get('pagePath') ) {

            $url = $this->urlForPath( (string) $this->get('pagePath'), (string) $this->get('siteId'),
                (string) $this->get('propertyId') );

        }

        if ( $url !== '' ) {

	        if ( strpos( $url, '#' ) ) {
	            $parts = explode( '#', $url );
	            $url = $parts[0];
	        }
	
	        $url = $url.'#owa_overlay.' . trim( (string) $this->getParam( 'overlay_params' ), '\u0000' );
			
			// The tracked site is another host, which a redirect refuses unless
			// vouched for; urlForPath() opens only a host of a Profile's domain.
			$this->redirectBrowserToUrl( $url, array( (string) parse_url( $url, PHP_URL_HOST ) ) );
			$this->set('url', $url);
		}
    }

    /**
     * A page path resolved to one concrete URL to open.
     *
     * A path is not a document: one uri maps to several urls, and so to several
     * documents -- a query string or a fragment makes a new one. The overlay
     * has to open a real page, so one of them has to be chosen.
     *
     * The one with the MOST CLICKS on this site is chosen, because that is the
     * page whose heatmap the reader is asking to see. Picking the lowest id, or
     * whichever row the database returned first, would open a variant with no
     * clicks on it and draw an empty overlay -- which reads as "the heatmap is
     * broken" rather than "you asked for a different url".
     *
     * Scoped by site: documents are content-hashed and shared across sites, so
     * without it this could open a page belonging to somebody else's site.
     *
     * BY PROPERTY OR BY PROFILE. A report is of a Property, so the link Page
     * Detail draws carries its propertyId and no siteId (makeLink()'s link
     * state), and the url is then the one with the most clicks across the
     * Property's Profiles: its cube holds them and nothing else. A siteId, as
     * an older link carries, still narrows it to that Profile.
     *
     * Only for a Property the user can see, and only a url on a Profile's own
     * domain -- a host it names or a subdomain of one. Anyone can send a beacon
     * with any page_location, and the overlay's data link, token and all,
     * travels in the fragment of the url opened: a click forged onto another
     * host would hand it to that host. It also passes over the real clicks
     * that arrive through a proxy, a translation service or a staging copy.
     *
     * @param string $path
     * @param string $siteId
     * @param string $propertyId
     * @return string the url, or '' when nothing matches
     */
    private function urlForPath( $path, $siteId, $propertyId = '' ) {

        if ( $path === '' || ( $siteId === '' && $propertyId === '' ) ) {

            return '';
        }

        /*
         * READ OFF THE CUBE, not owa_click joined to owa_document.
         *
         * Both of those tables are written by the v1 event chain, which v2
         * unregistered -- so this query matched nothing on every install, and
         * urlForPath() cannot tell "no rows" from "no such page": it returns ''
         * either way and the overlay draws empty. Which is exactly the failure the
         * docblock above describes as reading like a broken heatmap. The e2e
         * fixture hid it by writing owa_click rows directly and reading them back.
         *
         * NO JOIN NOW. A click row carries both readings of its own URL --
         * page_path, which is what a path constraint matches, and page_location,
         * which is the concrete URL to open. v1 needed owa_document because the
         * click only held a foreign key.
         *
         * The cube rather than raw, because the cube is what every other report
         * reads and it is the table the reporting layer will constrain the same
         * path against; reading raw here would let the two disagree about which
         * page has the most clicks.
         */
        $property_id = $siteId !== ''
            ? \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $siteId )
            : $propertyId;

        if ( $property_id === ''
             || \OWA\Core\CoreAPI::getCurrentUser()->siteIdForProperty( $property_id ) === '' ) {

            return '';
        }

        $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $property_id );

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $db->tableExists( $table ) ) {

            // A Property whose cube has never been built. Nothing to open, and
            // saying so beats a SQL error on a missing table.
            return '';
        }

        $site_ids = $siteId !== ''
            ? array( $siteId )
            : \OWA\Module\Base\Classes\Cube\Cubes::siteIds( $property_id );

        $domains = self::domainsOf( $site_ids );

        $db->selectFrom( $table, 'e' );
        $db->selectColumn( 'e.page_location AS url, COUNT(*) AS clicks' );
        $db->where( 'e.event_type', 'click' );
        $db->where( 'e.page_path', $path );

        if ( $siteId !== '' ) {

            $db->where( 'e.site_id', $siteId );
        }

        $db->groupBy( 'e.page_location' );
        $db->orderBy( 'clicks', 'DESC' );
        $db->limit( self::CANDIDATES );

        foreach ( (array) $db->getAllRows() as $row ) {

            $url = trim( (string) $row['url'] );

            if ( self::isOnDomain( (string) parse_url( $url, PHP_URL_HOST ), $domains ) ) {

                return $url;
            }
        }

        return '';
    }

    /** How many of the most-clicked urls are tried for one on a Profile's domain. */
    const CANDIDATES = 25;

    /**
     * The Profiles' domains as bare hosts, without a leading www.: a Profile's
     * domain is stored as entered, scheme and all ("http://www.example.com").
     *
     * @param string[] $site_ids
     * @return string[]
     */
    private static function domainsOf( array $site_ids ) {

        $domains = array();

        foreach ( $site_ids as $site_id ) {

            $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $site->getByColumn( 'site_id', $site_id );

            $domain = strtolower( trim( (string) $site->get( 'domain' ) ) );
            $host   = (string) parse_url( strpos( $domain, '//' ) === false ? '//' . $domain : $domain, PHP_URL_HOST );
            $host   = preg_replace( '/^www\./', '', $host );

            if ( $host !== '' ) {

                $domains[] = $host;
            }
        }

        return $domains;
    }

    /**
     * Whether a host is one of the domains or a subdomain of one: www. and
     * the other hosts a site serves under.
     *
     * @param string   $host
     * @param string[] $domains from domainsOf()
     * @return bool
     */
    private static function isOnDomain( $host, array $domains ) {

        $host = strtolower( $host );

        foreach ( $domains as $domain ) {

            if ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) {

                return true;
            }
        }

        return false;
    }
}




?>
