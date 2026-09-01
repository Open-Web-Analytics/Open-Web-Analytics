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
 * Goals Entry Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class OptionsGoalEntry extends \OWA\Core\AdminController {

    function __construct($params) {

        parent::__construct($params);
        $this->type = 'options';
        $this->setRequiredCapability('edit_settings');
    }

    function action() {

        $number = $this->getParam( 'goal_number' );
        $siteId = $this->get('siteId');
        $gm = \OWA\Core\CoreAPI::supportClassFactory('base', 'goalManager', $siteId);
        $goal = $gm->getGoal( $number );
        $goal_groups = $gm->getAllGoalGroupLabels();
        $this->set( 'goal_groups', $goal_groups );
        $this->set( 'goal', $goal );
        $this->set('goal_number', $number);
        $this->set('siteId', $this->getParam( 'siteId' ) );
        /*
         * The hierarchy wrapper. There is one settings nav now -- the old
         * base.options menu is gone -- so every settings screen carries the tile
         * and the tier groups, module screens included.
         */
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        $this->set( 'hierarchy_tier', 3 );
        $this->setView('base.optionsHierarchy');
        $this->setSubView('base.optionsGoalEntry');

    }
}



?>
