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

/**
 * Archive a Property, and every Observation Profile under it.
 *
 * The cascade is downward and explicit: a Property is how someone says "this
 * website", so removing it means removing the ways it was being watched. It
 * does NOT work the other way -- archiving the last Profile of a Property
 * leaves the Property empty and reachable, which is what makes it possible to
 * start its Profiles over.
 *
 * Nothing is destroyed. Both tiers take an archived_date, and everything
 * hanging off them -- grants, scoped settings, collected data -- is left where
 * it is.
 */
class PropertyDelete extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_sites' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'required' );

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'entityExists',
            array(
                'entity'   => 'base.property',
                'column'   => 'id',
                'errorMsg' => 'That Property no longer exists.',
            ) );
    }

    function action() {

        $propertyId = $this->getParam( 'propertyId' );
        $archivedAt = \OWA\Core\CoreAPI::getRequestTimestamp();

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $propertyId );

        if ( $property->wasPersisted() ) {

            $property->set( 'archived_date', $archivedAt );
            $property->update();
        }

        /*
         * The Profiles are archived one at a time rather than with a single
         * UPDATE ... WHERE property_id: an entity write keeps the row cache
         * and the entity's own update path in step, and the number of Profiles
         * on one Property is small by construction.
         */
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $site->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( 'property_id', $propertyId );

        foreach ( (array) $db->getAllRows() as $row ) {

            $profile = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $profile->load( $row['id'] );

            if ( $profile->wasPersisted() && ! $profile->isArchived() ) {

                $profile->set( 'archived_date', $archivedAt );
                $profile->update();
            }
        }

        $this->setRedirectAction( 'base.reportingHome' );
        $this->set( 'status_code', 3205 );
    }
}
