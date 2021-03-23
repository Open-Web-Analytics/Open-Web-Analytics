<?php

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

require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_BASE_DIR.'/owa_auth.php');

class owa_loginController extends owa_controller {

    public function validate()
    {
        $this->addValidation('user_id', $this->getParam('user_id'), 'userName', ['stopOnError' => true]);
    }

    function action() {

        $auth = owa_auth::get_instance();
        $status = $auth->authenticateUser();
        $go = owa_sanitize::cleanUrl( $this->getParam('go') );
        // if authentication is successfull
        if ($status['auth_status'] == true) {

            if (!empty($go)) {
                // redirect to url if present
                $url = urldecode(htmlspecialchars_decode( $go ) );
                $this->e->debug("redirecting browser to...:". $url);
                owa_lib::redirectBrowser($url);

            } else {
                //else redirect to home page

                // these need to be unset as they were set previously by the doAction method.
                // need to refactor this out.
                $this->set('auth_status', '');
                $this->set('params', '');
                $this->set('site_id', '');
                $this->setRedirectAction($this->config['start_page']);
            }

        } else {
            // return login form with error msg
            $this->setView('base.loginForm');
            $this->set('go', $go);
            $this->set('error_code', 2002);
            $this->set('user_id', $this->getParam('user_id'));

        }
    }

    function errorAction() {

        // return login form with error msg
        $this->setView('base.loginForm');
        $this->set('go', $go);
        //$this->set('error_code', 2002);
        $this->set('user_id', $this->getParam('user_id'));
    }
}

?>