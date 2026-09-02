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
 * Tracked Sites Tag Generator Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class SitesInvocation extends \OWA\Core\AdminController {

    function __construct($params) {

        $this->setRequiredCapability('edit_sites');
        return parent::__construct($params);
    }

    function action() {
        $site_id = $this->getParam('siteId');
        $this->set('site_id', $site_id);
        $s = \OWA\Core\CoreAPI::entityFactory('base.site');
        $s->getByColumn('site_id', $site_id);
        $this->set('site', $s);
        $this->setSubview('base.sitesInvocation');
        /*
         * The hierarchy wrapper: this is a Profile screen, reached from the site
         * control's nav. The install settings nav beside it would offer a way
         * out that has nothing to do with where you are.
         */
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        /* Tier 3: this screen is about an Observation Profile, so the context line stops there. */
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        $this->setView('base.optionsHierarchy');
    }
}





?>
