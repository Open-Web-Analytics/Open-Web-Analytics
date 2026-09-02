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
 * Add Sites View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class SitesAdd extends \OWA\Core\View {

    function render($data) {

        //page title
        $this->t->set('page_title', 'New Observation Profile');
        $this->body->set('headline', 'New Observation Profile');
        // load body template
        $this->body->set_template('sites_addoredit.php');

        $this->body->set('action', 'base.sitesAdd');

        // sites_addoredit.php is shared with base.sitesProfile and reads both
        // 'site' and 'config'. Set them UNCONDITIONALLY -- empty array when
        // absent -- so the template never has to guess which view rendered it.
        // 'site' was previously set only when $data['site'] was truthy, and
        // 'config' was never set here at all.
        $this->body->set('site', $data['site'] ?? []);
        $this->body->set('config', $data['config'] ?? []);
    }
}
