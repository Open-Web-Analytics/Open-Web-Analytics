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
 * Goals Roster Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class OptionsGoals extends \OWA\Core\AdminController {
    
    function __construct($params) {
    
        parent::__construct($params);
        $this->type = 'options';
        $this->setRequiredCapability('edit_settings');
    }
    
    function action() {
        
        $siteId = $this->get('siteId');
        $gm = \OWA\Core\CoreAPI::supportClassFactory('base', 'goalManager', $siteId);
        $goals = $gm->getAllGoals();
        $goal_groups = $gm->getAllGoalGroupLabels();
        $this->set('goals', $goals);
        $this->set('goal_groups', $goal_groups);
        /*
         * The hierarchy wrapper: this is a Profile screen, reached from the site
         * control's nav. The install settings nav beside it would offer a way
         * out that has nothing to do with where you are.
         */
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        $this->setView('base.optionsHierarchy');
        $this->setSubView('base.optionsGoals');
        $this->set('siteId', $siteId);
    }
}



?>
