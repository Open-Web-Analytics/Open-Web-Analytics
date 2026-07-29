<?php
namespace OWA\Core\View;

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

class AdminPage extends \OWA\Core\View {

    function render($data) {

        // Set Page title
        $this->t->set('page_title', $this->get('title'));

        // Set Page headline
        $this->body->set('title', $this->get('title'));
        $this->body->set('titleSuffix', $this->get('titleSuffix'));
        $this->body->set_template('genericAdminPage.php');
        
        $this->setJs('owa.reporting', 'base/dist/owa.reporting-combined-min.js');
        $this->setCss("base/css/owa.reporting-css-combined.css");
    }
}
