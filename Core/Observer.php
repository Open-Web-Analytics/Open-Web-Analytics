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
 * Base class for event handlers.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Observer extends \OWA\Core\Base {

    /**
     * Event Message
     *
     * @var array
     */
    public $m;

    /**
     * Run an action with this event as its `event` parameter.
     *
     * @param string $action
     * @return mixed
     */
    public function handleEvent( $action ) {

        \OWA\Core\CoreAPI::performAction( $action, array( 'event' => $this->m ) );

        return \OWA\Core\CoreAPI::debug( sprintf( 'Handled Event. Action: %s', $action ) );
    }

    /**
     * Send a plain-text message through the configured mailer: its transport,
     * From address and error logging.
     *
     * @param string $email_address
     * @param string $subject
     * @param string $msg
     * @return bool whether the transport accepted it
     */
    public function sendMail( $email_address, $subject, $msg ) {

        $mailer = $this->mailer();
        $mailer->setSubject( (string) $subject );
        $mailer->setHtmlBody( nl2br( htmlspecialchars( (string) $msg, ENT_QUOTES, 'UTF-8' ) ) );
        $mailer->setAltBody( (string) $msg );

        if ( ! $mailer->addAddress( (string) $email_address ) ) {

            return false;
        }

        return $mailer->sendMail();
    }

    /** The mailer sendMail() uses. */
    protected function mailer() {

        return new \OWA\Module\Base\Classes\Mailer();
    }
}

?>