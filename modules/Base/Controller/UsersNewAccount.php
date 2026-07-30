<?php
namespace OWA\Module\Base\Controller;


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
 * New user Account Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class UsersNewAccount extends \OWA\Core\Controller {

    function __construct($params) {
        return parent::__construct($params);

    }

    function action() {

        $event = $this->getParam('event');

        // return email view
        $data['user_id']= $event->get('user_id');
        $data['email_address']= $event->get('email_address');
        $data['temp_passkey'] = $event->get('temp_passkey');
        $data['subject'] = 'OWA User Account Setup';
        $data['view'] = 'base.usersNewAccount';
        $data['view_method'] = 'email';
        $data['name'] = $event->get('real_name');

        return $data;
    }

}




?>
