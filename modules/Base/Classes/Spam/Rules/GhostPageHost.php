<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The page the beacon names is not on the Profile's site: its host (from
 * page_location, the wire's p_l) is neither the Profile's domain nor a
 * subdomain of it.
 *
 * A "ghost" hit: a beacon posted straight to log.php naming a page that was
 * never loaded, usually on a host that has nothing to do with the site.
 * Hosts are compared as Spam\Hosts does: case ignored, port ignored, and
 * www.example.com the same as example.com. A subdomain of the domain is on
 * the site (ProfileDomains::isOnDomain()): shop.example.com is a page of a
 * Profile whose domain is example.com, and badexample.com is not.
 *
 * DOES NOT FIRE for a Profile with no domain set: there is nothing to compare
 * against. Nor for a beacon with no page location, which NO_PAGE_LOCATION
 * scores instead.
 */
class GhostPageHost extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'GHOST_PAGE_HOST';
    const SET    = 'page_origin';
    const POINTS = 5.0;

    public function test( $event, array $headers ) {

        $page = \OWA\Module\Base\Classes\Spam\Received::pageLocation( $event );

        if ( \OWA\Module\Base\Classes\Spam\Hosts::of( $page ) === '' ) {

            return false;
        }

        $hosts = $this->profileHosts( (string) $event->getSiteId() );

        return $hosts && ! \OWA\Module\Base\Classes\ProfileDomains::isOnDomain( $page, $hosts );
    }

    /**
     * The Profile's hosts. Separate so a test can name them without a Profile.
     *
     * @param  string $site_id
     * @return string[]
     */
    protected function profileHosts( $site_id ) {

        return \OWA\Module\Base\Classes\Spam\Hosts::forSite( $site_id );
    }
}

?>
