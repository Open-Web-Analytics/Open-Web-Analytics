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
 * Options View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Options extends \OWA\Core\View {

    function __construct() {

        $this->default_subview = 'base.optionsGeneral';

        return parent::__construct();
    }

    function render($data) {

        //page title
        $this->t->set('page_title', 'OWA Options');

        // load body template
        $this->body->set_template('options.php');

        // fetch admin links from all modules
        // need api call here.
        $this->body->set('headline', 'OWA Settings');

        // get admin panels
        $api = \OWA\Core\CoreAPI::singleton();
        $panels = $api->getAdminPanels();
        //print_r($panels);
        $this->body->set('panels', $panels);

        // Assign config data
        $this->body->set('config', $this->config);
        $this->setJs('owa.reporting', 'base/dist/owa.reporting-combined-min.js');
        $this->setCss('base/css/owa.admin.css');
    }
}

?>