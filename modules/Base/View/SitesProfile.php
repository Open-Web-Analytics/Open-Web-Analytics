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
        $this->body->set( 'site_id', $this->get('siteId') );
        $this->body->set( 'config', $this->get('config') ?? [] );
        $this->body->set_template( 'sites_addoredit.php' );
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
