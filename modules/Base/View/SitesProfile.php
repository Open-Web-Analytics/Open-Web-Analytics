<?php
namespace OWA\Module\Base\View;

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
 *  Sites Profile View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class SitesProfile extends \OWA\Core\View {

    function render() {


        $site = $this->get('site');
        if ($this->get('edit')) {
            $this->body->set('action', 'base.sitesEdit');
            $this->body->set('headline', 'Profile Details');

            $siteEntity = \OWA\Core\CoreAPI::entityFactory('base.site');
            $siteEntity->getByColumn('site_id', $this->get('siteId'));
            $this->body->set('siteEntity', $siteEntity);

        } else {
            $this->body->set('action', 'base.sitesAdd');
            $this->body->set('headline', 'New Observation Profile');

        }
        if (isset($site['domain'])) {
            $this->t->set( 'page_title', 'Profile Details' );
        }
        else {
            $this->t->set( 'page_title', 'New Observation Profile' );
        }

        $this->body->set('users', $this->getAllUserRows());

        // Normalised to arrays: both are already set unconditionally, but either
        // can arrive null on the add path, and the template indexes into them.
        $this->body->set( 'site', $site ?? [] );
        $this->body->set( 'edit', $this->get('edit') );

        /*
         * The Properties this Profile could belong to, and which one is
         * pre-selected. Live ones only -- an archived Property is a removed
         * Property and putting a new Profile into one would resurrect it
         * halfway.
         */
        $this->body->set( 'properties', $this->getLivePropertyRows() );
        $this->body->set( 'propertyId', (string) ( $this->get('propertyId') ?? '' ) );
        $this->body->set( 'propertyName', $this->resolvePropertyName( $site['property_id'] ?? '' ) );
        $this->body->set( 'site_id', $this->get('siteId') );
        $this->body->set( 'config', $this->get('config') ?? [] );
        $this->body->set_template( 'sites_addoredit.php' );
    }

    /**
     * Properties that can still be observed, ordered as the site control orders
     * them so the two read the same way.
     *
     * @return array
     */
    private function getLivePropertyRows() {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $property->getTableName() );
        $db->selectColumn( 'id, name, domain, archived_date' );
        $db->orderBy( 'name' );

        $rows = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( empty( $row['archived_date'] ) ) {

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The name of the Property a Profile belongs to, for the read-only line on
     * the edit form.
     *
     * @return string
     */
    private function resolvePropertyName( $propertyId ) {

        if ( ! $propertyId ) {

            return '';
        }

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $propertyId );

        return (string) $property->get( 'name' );
    }

    /**
     * @return array
     */
    private function getAllUserRows() {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom('owa_user');
        $db->selectColumn("*");
        return $db->getAllRows();
    }

}
