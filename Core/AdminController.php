<?php
namespace OWA\Core;


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
 * Administrative Controller Class
 *
 * This controller should be used for internal management pages/actions that require authentication
 *
 * Every signed-in screen of the admin web app is one. Two rules come with it:
 * pending updates block it (Controller::doAction), and it accepts the session
 * cookies and nothing else (acceptedAuthMethods). Screens before sign-in --
 * login, password reset, the updates screen -- and the tracking endpoints are
 * plain Controllers.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class AdminController extends \OWA\Core\Controller {
    
    var $is_admin = true;

    /**
     * The session cookies, unless the controller declares otherwise.
     *
     * A screen is for a signed-in browser, so an API key or an application
     * password is not read, and a login form's user_id and password are
     * parameters rather than a sign-in that writes the session cookies ahead
     * of the nonce check.
     *
     * Only in the web app. REST controllers extend screens to reuse them --
     * AddUserRest is a UsersAdd, ReportsRest a ReportController -- and there the
     * API's own credentials apply; a CLI command (Controller\Cli) runs as the
     * user the CLI signs in.
     *
     * @return string[]|null
     */
    function acceptedAuthMethods() {

        if ( $this->auth_methods !== null
             || \OWA\Core\CoreAPI::getSetting( 'base', 'request_mode' ) !== 'web_app' ) {

            return parent::acceptedAuthMethods();
        }

        return array( 'cookies' );
    }
    
    /**
     * Constructor
     *
     * @param array $params
     * @return \OWA\Core\Controller
     */
    function __construct($params) {
    
        return parent::__construct($params);
    }
}

?>