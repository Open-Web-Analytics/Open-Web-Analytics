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
    /*
     * The hierarchy defaults, duplicated from Update021 ON PURPOSE.
     *
     * A migration must stay self-contained -- it runs against a schema and a
     * codebase that may be older than itself -- so it cannot reach in here, and
     * this must not reach into it. The values are the contract between them:
     * a freshly installed OWA and a migrated one have to be indistinguishable,
     * or "Observation Profile 1" means one thing on one install and another
     * elsewhere.
     */
    const DEFAULT_ORGANIZATION_NAME = 'My Organization';

    /*
     * The key the default Organization's id is derived from -- a fixed string,
     * not the name. Same value Update021 uses, so an install that migrated and
     * one installed fresh mint the same id.
     */
    const DEFAULT_ORGANIZATION_KEY  = 'organization:default';
    const PROFILE_NAME_PREFIX       = 'Observation Profile ';

    /**
     * The Organization every Property hangs from, created on first need.
     *
     * There is exactly one until someone builds a screen to make more, which is
     * why this looks one up rather than taking a name. Installing does not have
     * to know about it, and neither does adding a site.
     *
     * @return string|null organization id
     */
    public function ensureOrganization() {

        $organization = \OWA\Core\CoreAPI::entityFactory( 'base.organization' );

        /*
         * Found by EXISTENCE, not by name.
         *
         * This matched on DEFAULT_ORGANIZATION_NAME, which quietly made the
         * name a key -- and there is a screen for renaming an Organization.
         * Renaming the only one made this stop finding it and mint a second,
         * so the rename appeared to revert, OrganizationProfile showed the new
         * empty row, and every Property added afterwards hung from that one
         * instead.
         *
         * There is exactly one Organization until someone builds a screen to
         * make more, so the first row IS the answer -- and looking for it this
         * way is indifferent to how its id was derived, which matters because
         * installs made before this fix derived it from the name.
         */
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $organization->getTableName() );
        $db->selectColumn( 'id' );

        $existing = (array) $db->getOneRow();

        if ( ! empty( $existing['id'] ) ) {

            return $existing['id'];
        }

        $id = $organization->generateId( self::DEFAULT_ORGANIZATION_KEY );

        $organization->set( 'id', $id );
        $organization->set( 'name', self::DEFAULT_ORGANIZATION_NAME );
        $organization->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

        if ( $organization->create() ) {

            return $id;
        }
    }

    /**
     * The Property a new Profile belongs to, found by domain or created.
     *
     * Keyed on the domain, matching Update021's planner: two Profiles of one
     * website share a Property, and that is what makes adding a second way of
     * tracking a site produce a second Profile rather than a second website.
     *
     * The NAME given here lands on the Property, not the Profile. That is the
     * migration's rule too -- the human name describes the website, and the
     * Profile is only one way of watching it.
     *
     * @return string|null property id
     */
    public function ensurePropertyFor( $domain, $name = '', $description = '' ) {

        $domain = \OWA\Module\Base\Update\Update021::normaliseDomain( $domain );

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        if ( $domain !== '' ) {

            $property->getByColumn( 'domain', $domain );

            $existing = $property->get( 'id' );

            if ( $existing ) {

                return $existing;
            }
        }

        /*
         * A site with no domain gets its own Property rather than sharing one
         * keyed on the empty string -- otherwise every domainless site on the
         * install would collapse into a single website.
         */
        $seed = $domain !== '' ? 'domain:' . $domain : 'site:' . uniqid( '', true );
        $id   = $property->generateId( $seed );

        /*
         * The domain-derived id is only free while no OTHER Property still
         * holds it, and a Property's domain is editable.
         *
         * Move a Property from a.com to b.com and its id stays derived from
         * a.com. The lookup above then misses for a.com -- the column really
         * did change -- so we come here and derive that same id again, the
         * insert fails on the primary key, create() returns false, and this
         * hands back null. The new Profile would be created with no Property.
         */
        $taken = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $taken->load( $id );

        if ( $taken->wasPersisted() ) {

            $id = $property->generateId( $seed . ':' . uniqid( '', true ) );
        }

        $property->set( 'id', $id );
        $property->set( 'organization_id', $this->ensureOrganization() );
        $property->set( 'name', $name !== '' ? $name : ( $domain !== '' ? $domain : 'Untitled' ) );
        $property->set( 'domain', $domain );
        $property->set( 'description', $description );
        $property->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

        if ( $property->create() ) {

            return $id;
        }
    }

    /**
     * "Observation Profile N", numbered within its Property.
     *
     * Counts what is already there rather than tracking a sequence, so it is
     * correct after a migration, after a delete, and on an install whose
     * Profiles arrived by several different routes.
     */
    public function nextProfileName( $property_id ) {

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $site->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( 'property_id', $property_id );

        $existing = count( (array) $db->getAllRows() );

        return self::PROFILE_NAME_PREFIX . ( $existing + 1 );
    }

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

        /*
         * The name the caller gave describes the WEBSITE, so it goes on the
         * Property; the Profile takes the generated "Observation Profile N".
         * That is the same split Update021 applies to existing sites, and the
         * two have to agree -- a fresh install and a migrated one must not be
         * distinguishable.
         */
        $property_id = $this->ensurePropertyFor( $domain, $name, $description );

        $site->set( 'id', $id );
        $site->set( 'site_id', $site_id );
        $site->set( 'property_id', $property_id );
        $site->set( 'name', $property_id ? $this->nextProfileName( $property_id ) : $name );
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