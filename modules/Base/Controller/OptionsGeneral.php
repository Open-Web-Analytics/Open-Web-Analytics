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
 * Admin Settings/Options Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class OptionsGeneral extends \OWA\Core\AdminController {

    function __construct($params) {

        parent::__construct($params);
        $this->type = 'options';
        $this->setRequiredCapability('edit_settings');

    }

    function action() {

        $this->set( 'configuration',  $this->c->fetch('base') );

        // add data to container
        /*
         * The hierarchy wrapper. Install-wide options live at the top of the same
         * nav now -- one settings menu rather than two, which is only possible
         * because every session lands on a Profile and the tile is always
         * populated.
         */
        $owa_site_id = $this->resolveCurrentSiteId();
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        /* Tier 0: an install-wide screen, so the context line names nothing below it. */
        $this->set( 'hierarchy_tier', 0 );
        $this->setView( 'base.optionsHierarchy' );
        $this->data['subview'] = 'base.optionsGeneral';
        $this->data['view_method'] = 'delegate';

        return $this->data;

    }

}



?>
