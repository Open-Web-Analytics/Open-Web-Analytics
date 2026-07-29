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
        $this->t->set('page_title', 'Add Web Site');
        $this->body->set('headline', 'Add Web Site Profile');
        // load body template
        $this->body->set_template('sites_addoredit.php');

        $this->body->set('action', 'base.sitesAdd');

        //Check to see if user is passed by constructor or else fetch the object.
        if ($data['site']) {
            $this->body->set('site', $data['site']);
        }
    }
}
