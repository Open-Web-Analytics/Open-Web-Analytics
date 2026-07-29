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

class Mail extends \owa_view {

    // post office
    var $po;
    var $postProcessView = true;

    function __construct() {

        // make this a service
        $this->po = new \owa_mailer;
        parent::__construct();
    }

    function postProcess() {

        $this->po->setHtmlBody( $this->t->fetch() );

        if ( $this->get( 'plainTextView' ) ) {
            $this->po->setAltBody( \owa_coreAPI::displayView( $this->get( 'plain_text_view' ) ) );
        }

        return $this->po->sendMail();
    }

    function setMailSubject($sbj) {

        $this->po->setSubject( $sbj );
    }

    function addMailToAddress($email, $name = '') {

        if (empty($name)) {
            $name = $email;
        }

        $this->po->addAddress($email, $name);
    }
}
