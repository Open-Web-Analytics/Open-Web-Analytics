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
 * Site Profile Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class SitesProfile extends \OWA\Core\AdminController {

    function __construct($params) {

        $this->setRequiredCapability('edit_sites');
        return parent::__construct($params);
    }

    function action() {

        // needed as this controller is
        $site_id = $this->getParam('siteId');
        if (!empty($site_id)) {
            $site = \OWA\Core\CoreAPI::entityFactory('base.site');
            $site->getByColumn('site_id', $site_id);
            $site_data = $site->_getProperties();
            $this->set('config', $site->get('settings') );
            $this->set('edit', $this->getParam('edit'));

        } else {
            $site_data = array();
        }



        $this->set('site', $site_data);
        $this->set('siteId', $site_id);
        // The hierarchy wrapper: a Profile is a tier of the tree, not a
        // settings page. See OptionsHierarchy.
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->setView('base.optionsHierarchy');
        $this->setSubview('base.sitesProfile');
    }


}






?>
