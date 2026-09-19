<?php

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


namespace OWA\Module\Base\Entity;

/**
 * Visitor Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Visitor extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('visitor');
        $this->setCachable();

        // properties
        $this->properties['id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['id']->setPrimaryKey();

        //drop
        $this->properties['user_name'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['user_name']->setDataType(OWA_DTD_VARCHAR255);

        //drop
        $this->properties['user_email'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['user_email']->setDataType(OWA_DTD_VARCHAR255);

        $this->properties['first_session_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['first_session_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['first_session_year'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['first_session_year']->setDataType(OWA_DTD_INT);
        $this->properties['first_session_month'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['first_session_month']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['first_session_day'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['first_session_day']->setDataType(OWA_DTD_INT);
        $this->properties['first_session_timestamp'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['first_session_timestamp']->setDataType(OWA_DTD_BIGINT);
        $this->properties['first_session_yyyymmdd'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['first_session_yyyymmdd']->setDataType(OWA_DTD_BIGINT);

        /*
         * Acquisition -- the traffic source of the visitor's FIRST session,
         * written once when the visitor row is created and never updated.
         *
         * Stored as literals, not as dimension foreign keys, for two reasons.
         *
         * A report reaching these goes owa_request -> owa_visitor already; a
         * foreign key here would make it owa_request -> owa_visitor ->
         * owa_source_dim, and ResultSetManager::addDimension joins with
         * OWA_SQL_JOIN (INNER), so every extra hop is another way for a fact to
         * vanish from a report rather than render "(not set)". One hop, one
         * chance to go wrong.
         *
         * And the v2 event table is denormalised throughout, so a literal is
         * the shape it inherits. A foreign key would have to be resolved back
         * into a string at migration time for no benefit taken in the meantime.
         *
         * owa_session already mixes the two this way -- source_id, campaign_id
         * and ad_id are keys, `medium` is a literal -- so this is the existing
         * pattern rather than a new one.
         *
         * Declared NULLABLE, which for a text column is an opt-in: Entity
         * writes '' for an unset one by default, to keep the shape the pre-PDO
         * driver gave columns that already existed. These did not exist then,
         * so they take v2's form instead -- absence is NULL, and "(not set)" is
         * a label applied when a value is rendered.
         */
        $this->properties['first_session_source'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['first_session_source']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['first_session_source']->setNullable();
        $this->properties['first_session_medium'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['first_session_medium']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['first_session_medium']->setNullable();
        $this->properties['first_session_campaign'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['first_session_campaign']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['first_session_campaign']->setNullable();
        $this->properties['first_session_ad'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['first_session_ad']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['first_session_ad']->setNullable();
        $this->properties['first_session_search_terms'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['first_session_search_terms']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['first_session_search_terms']->setNullable();

        //drop
        $num_prior_sessions =  new \OWA\Module\Base\Classes\DbColumn;
        $num_prior_sessions->setName('num_prior_sessions');
        $num_prior_sessions->setDataType(OWA_DTD_INT);
        $this->setProperty($num_prior_sessions);
    }

    function getVisitorName() {

        if ($this->get('user_name') && $this->get('user_name') != '(not set)' ) {
            return $this->get('user_name');
        } elseif ($this->get('user_email') && $this->get('user_email') != '(not set)') {
            return $this->get('user_email');
        } else {
            return $this->get('id');
        }
    }

    function getAvatarId() {

        $eq = \OWA\Core\CoreAPI::getEventDispatch();

        return $eq->filter( 'visitor_avatar_id', $this->get( 'user_email' ) );
    }
}



?>