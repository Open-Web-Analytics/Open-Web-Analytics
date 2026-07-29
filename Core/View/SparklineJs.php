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

/**
 * @depricated
 */
class SparklineJs extends \owa_view {

    function __construct() {

        parent::__construct();

    }

    function render($data) {

        // load template
        $this->t->set_template('wrapper_blank.php');
        $this->body->set_template('sparklineJs.php');
        // set
        $this->body->set('widget', $data['widget']);
        $this->body->set('type', $data['type']);
        $this->body->set('height', $data['height']);
        $this->body->set('width', $data['width']);
        $this->body->set('values', $data['series']['values']);
        $this->body->set('dom_id', $data['dom_id'].rand());
        //$this->setJs("includes/jquery/jquery.sparkline.js");
        return;
    }
}
