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

class InstallFinish extends \OWA\Core\View {

    function render($data) {

        // Set Page title
        $this->t->set('page_title', 'Installation Complete');

        // Set Page headline
        $this->body->set('headline', 'Installation is Complete');

        $this->body->set('site_id', $this->get('site_id'));
        $this->body->set('u', $this->get('u'));
        $this->body->set('p', $this->get('p'));
        // load body template
        $this->body->set_template('install_finish.php');
        $this->setJs("owa", "base/dist/owa.reporting-combined-min.js");
    }
}
