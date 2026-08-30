<?php
namespace OWA\Module\Base\Classes;


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
 * Site Manager Class
 * 
 * handels the common tasks associated with creating and manipulating tracked sites
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.1
 */

class SiteManager extends \OWA\Core\Base {

    function __construct() {

        return parent::__construct();
    }

    /**
     * Ensure a site exists for a domain, and return it if this call created it.
     *
     * The contract is unchanged: calling this twice for one domain creates one
     * site, and the second call returns nothing. What changed is HOW that is
     * achieved. Identity used to be md5( $domain ), so deriving the key and
     * finding a row already there was the same operation as recognising the
     * domain -- idempotency came free, as a side effect of the coupling that
     * made two sites per domain impossible.
     *
     * Identity is now minted, so the domain is looked up explicitly instead.
     * Same behaviour, no longer resting on identity and domain being the same
     * fact.
     *
     * For a second site on a domain that already has one -- a website measured
     * by more than one profile -- use createSite().
     */
    function createNewSite( $domain, $name = '', $description = '', $site_family = '', $site_id = '' ) {

        $existing = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $existing->load( $domain, 'domain' );

        if ( $existing->wasPersisted() ) {

            \OWA\Core\CoreAPI::debug(
                "Cannot create new site. A site for domain $domain already exists." );

            return;
        }

        return $this->createSite( $domain, $name, $description, $site_family, $site_id );
    }

    /**
     * Create a site for a domain, whether or not one already exists.
     *
     * Nothing calls this yet. It exists because a tracked website will be able
     * to have several profiles measuring it, and that is only expressible once
     * identity stops being a function of the domain. Introducing it now keeps
     * the two intents that createNewSite() used to conflate -- "make sure there
     * is one" and "make another" -- separable and testable before anything
     * depends on the distinction.
     */
    function createSite( $domain, $name = '', $description = '', $site_family = '', $site_id = '' ) {

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        if ( $site_id ) {

            /*
             * A pinned identifier. Imports, restores and test fixtures need the
             * identifier to be known in advance -- a fixture has to put the same
             * value in a tracking payload that it puts in the database, and
             * cannot read back something it has not created yet. InstallManager
             * has always accepted one for the same reason.
             */
            $id = $site->generateId( $site_id );

        } else {

            list( $site_id, $id ) = $this->mintUnusedIdentity( $site );
        }

        if ( ! $site_id ) {

            \OWA\Core\CoreAPI::debug( "Could not mint an unused site identity for $domain." );

            return;
        }

        if ( ! $name ) {
            $name = $domain;
        }

        $site->set( 'id', $id );
        $site->set( 'site_id', $site_id );
        $site->set( 'name', $name );
        $site->set( 'domain', $domain );
        $site->set( 'description', $description );
        $site->set( 'site_family', $site_family );

        if ( $site->create() ) {

            return $site;
        }
    }

    /**
     * A minted site_id whose derived numeric key is not already taken.
     *
     * Worth the loop rather than trusting the width. Under the old scheme a
     * derived key colliding meant "same domain", and returning the existing row
     * was correct. Under a minted one a collision would mean handing back a
     * DIFFERENT site than the caller asked to create -- silently, with no error
     * anywhere. Checking costs one indexed read on a table with a handful of
     * rows.
     *
     * @return array{0: string, 1: string} the site_id and its numeric key, or
     *                                     two empty strings if none was free
     */
    private function mintUnusedIdentity( $site, $attempts = 5 ) {

        for ( $i = 0; $i < $attempts; $i++ ) {

            $site_id = $site->mintSiteId();
            $id      = $site->generateId( $site_id );

            $taken = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $taken->load( $id );

            if ( ! $taken->wasPersisted() ) {

                return array( $site_id, $id );
            }
        }

        return array( '', '' );
    }
}

?>