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

class Json extends \OWA\Core\View {

    function __construct() {

        parent::__construct();
    }

    function render() {

        // load template
        $this->t->set_template('wrapper_blank.php');
        $this->body->set_template('json.php');

        // JSON only. JSONP wrapped this same body in a caller-named function so
        // it could be loaded with a <script> tag -- a same-origin-policy bypass,
        // and unnecessary now the overlays fetch over CORS.
        $this->body->set('json', json_encode( $this->get( 'json' ) ) );

        \OWA\Core\Lib::setContentTypeHeader( 'json' );
    }
}
