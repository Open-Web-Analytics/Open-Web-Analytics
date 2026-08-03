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
 * View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Error extends \OWA\Core\View {


    function __construct() {

        return parent::__construct();
    }

    function render($data) {

        // Set Page title
        $this->t->set('page_title', 'Error');

        if($this->is_subview === true):
            $this->t->set_template('wrapper_blank.php');
        endif;

        // load body template
        // generic_error.php reads error_msg from the body's own scope, so the
        // controller's message has to be handed across or the template falls back
        // to its placeholder and the reason for the error is lost.
        if ( isset( $data['error_msg'] ) ) {

            $msg = $data['error_msg'];

            // Controllers set this from getMsg(), which returns headline/message.
            if ( is_array( $msg ) ) {

                $msg = implode( ' ', array_values( $msg ) );
            }

            $this->body->set( 'error_msg', $msg );
        }

        $this->body->set_template('generic_error.php');

        return;
    }


}

?>