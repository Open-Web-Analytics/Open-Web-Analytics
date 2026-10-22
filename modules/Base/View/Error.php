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

        /*
         * The message is shown ONCE, in the page's error banner (msgs.php in
         * the wrapper), which reads a headline and a message. Controllers pass
         * either getMsg()'s array or a plain string; a string reached the
         * banner as something with neither key, so it drew an empty red box and
         * the text was echoed again, unstyled, below it.
         */
        $this->t->set( 'error_msg', self::bannerMessage( $data['error_msg'] ?? null ) );

        $this->body->set( 'back_url', (string) ( $data['error_back_url'] ?? '' ) );
        $this->body->set_template('generic_error.php');

        // The admin chrome's styles: the header's icons and the banner itself.
        $this->setCss( 'base/css/font-awesome/css/all.min.css' );
        $this->setCss( 'base/css/owa.reporting-css-combined.css' );
    }

    /**
     * An error message in the banner's shape.
     *
     * @param  mixed $msg getMsg()'s array, an array with 'msg', a string, or nothing
     * @return array headline, message
     */
    public static function bannerMessage( $msg ) {

        if ( is_array( $msg ) ) {

            return array(
                'headline' => (string) ( $msg['headline'] ?? \OWA\Core\CoreAPI::t( 'Error' ) ),
                'message'  => (string) ( $msg['message'] ?? $msg['msg'] ?? '' ),
            );
        }

        return array(
            'headline' => \OWA\Core\CoreAPI::t( 'Error' ),
            'message'  => $msg !== null && $msg !== '' ? (string) $msg : \OWA\Core\CoreAPI::t( 'Something went wrong.' ),
        );
    }
}

?>