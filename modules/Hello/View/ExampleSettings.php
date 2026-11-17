<?php
namespace OWA\Module\Hello\View;


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

class ExampleSettings extends \OWA\Core\View {

    public function __construct() {

        parent::__construct();

        $this->_setPageType( 'Administration Page' );
    }

    /**
     * Names the template. The heading is not set here: Core\View hands every
     * registered settings page its title as settings_page_title, from
     * Module::registerSettingsPage(), so the page and its nav link carry the
     * same name. A value of the view's own goes to the template with
     * $this->body->set( $name, $value ).
     *
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render($data) {

        $this->body->setTemplateFile('hello', 'example_settings.php');
    }


}
