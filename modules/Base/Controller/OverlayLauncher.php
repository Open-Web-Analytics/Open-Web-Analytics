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

        
        $entity = '';
        $id = '';

        $url = '';

        $siteId = (string) $this->get('siteId');

        if ( $this->get('pagePath') ) {

            $url = $this->urlForPath( (string) $this->get('pagePath'), $siteId );

        } elseif ($this->get('document_id')) {

	        $entity = 'base.document';
	        $url_param = 'url';
	        $id = $this->get('document_id');


	        $d = \OWA\Core\CoreAPI::entityFactory( $entity );
			$d->load( $id );

	        $url = trim( (string) $d->get( $url_param ) );

	        // A document is shared across sites, so its url is opened only on
	        // this site's own domain, like urlForPath()'s.
	        if ( ! self::isOnDomain( (string) parse_url( $url, PHP_URL_HOST ), self::domainOf( $siteId ) ) ) {
	            $url = '';
	        }
        }

        // Only on a site this user may see.
        if ( $url !== '' && ! \OWA\Core\CoreAPI::getCurrentUser()->isCapable( 'view_reports', $siteId ) ) {
            $url = '';
        }

        if ( $url !== '' ) {

	        if ( strpos( $url, '#' ) ) {
	            $parts = explode( '#', $url );
	            $url = $parts[0];
	        }
	
	        $url = $url.'#owa_overlay.' . trim( (string) $this->getParam( 'overlay_params' ), '\u0000' );
			
			// The tracked site is another host, which a redirect refuses unless
			// vouched for; only a host of the site's own domain gets here.
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
     * And only a url on the site's own domain -- a host it names or a subdomain
     * of one. Anyone can send a beacon naming any page, and the overlay's data
     * link, token and all, travels in the fragment of the url opened: a click
     * forged onto another host would hand it to that host.
     *
     * @param string $path
     * @param string $siteId
     * @return string the url, or '' when nothing matches
     */
    private function urlForPath( $path, $siteId ) {

        if ( $path === '' || $siteId === '' ) {

            return '';
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( 'owa_click', 'click' );
        $db->selectColumn( 'document.url AS url, COUNT(*) AS clicks' );
        /*
         * INNER, not outer. A click whose document is missing cannot contribute
         * a url, and the document.uri condition below excludes those rows
         * anyway, so the join type that matches the intent is the plain one.
         *
         * This was OWA_SQL_JOIN_LEFT_INNER, which expanded to "LEFT INNER JOIN"
         * -- not SQL in any dialect. MySQL rejected the statement, getOneRow()
         * returns null for a rejected statement exactly as it does for no rows,
         * and urlForPath() reads that as "no match" and returns ''. So the
         * overlay drew empty every time, which is the failure the docblock above
         * describes as reading like a broken heatmap.
         */
        $db->join( OWA_SQL_JOIN, 'owa_document', 'document', 'document_id', 'document.id' );
        $db->where( 'document.uri', $path );
        $db->where( 'click.site_id', $siteId );
        $db->groupBy( 'document.url' );
        $db->orderBy( 'clicks', 'DESC' );
        $db->limit( self::CANDIDATES );

        $domains = self::domainOf( $siteId );

        foreach ( (array) $db->getAllRows() as $row ) {

            $url = trim( (string) $row['url'] );

            if ( self::isOnDomain( (string) parse_url( $url, PHP_URL_HOST ), $domains ) ) {

                return $url;
            }
        }

        return '';
    }

    /** How many of the most-clicked urls are tried for one on the site's domain. */
    const CANDIDATES = 25;

    /**
     * The site's domain as a bare host, without a leading www.: it is stored
     * as entered, scheme and all ("http://www.example.com").
     *
     * @param string $siteId
     * @return string[] none when the site has no domain
     */
    private static function domainOf( $siteId ) {

        if ( $siteId === '' ) {

            return array();
        }

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->getByColumn( 'site_id', $siteId );

        $domain = strtolower( trim( (string) $site->get( 'domain' ) ) );
        $host   = (string) parse_url( strpos( $domain, '//' ) === false ? '//' . $domain : $domain, PHP_URL_HOST );
        $host   = preg_replace( '/^www\./', '', $host );

        return $host !== '' ? array( $host ) : array();
    }

    /**
     * Whether a host is one of the domains or a subdomain of one: www. and
     * the other hosts a site serves under.
     *
     * @param string   $host
     * @param string[] $domains from domainOf()
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
