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
 * Password Reset Request Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class PasswordResetForm extends \OWA\Core\Controller {

    public function action() {

        $this->setView( 'base.passwordResetForm', self::page() );
    }

    /** The form, which PasswordResetRequest draws again with its outcome. */
    public static function page() {

        return [
            'template' => 'users_password_reset_request.php',
            'wrapper'  => 'wrapper_public.php',
            'title'    => \OWA\Core\CoreAPI::t( 'Password Reset' ),
        ];
    }
}




?>
