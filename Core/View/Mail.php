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

class Mail extends \OWA\Core\View {

    // post office
    var $po;
    var $postProcessView = true;

    function __construct() {

        // make this a service
        $this->po = new \OWA\Module\Base\Classes\Mailer;
        parent::__construct();
    }

    function postProcess() {

        $this->po->setHtmlBody( $this->t->fetch() );

        if ( $this->get( 'plainTextView' ) ) {
            $this->po->setAltBody( \OWA\Core\CoreAPI::displayView( $this->get( 'plain_text_view' ) ) );
        }

        return $this->po->sendMail();
    }

    function setMailSubject($sbj) {

        $this->po->setSubject( $sbj );
    }

    /**
     * Add the recipients: one address, or a comma-separated list of them, as
     * notice_email may hold. An address the mailer refuses is logged and
     * skipped (Mailer::addAddress()).
     *
     * @return int how many were added
     */
    function addMailToAddress($email, $name = '') {

        $added     = 0;
        $addresses = array_values( array_filter( array_map( 'trim', explode( ',', (string) $email ) ), 'strlen' ) );

        foreach ( $addresses as $address ) {

            // A name belongs to one person: a list is addressed by address alone.
            $label = count( $addresses ) === 1 && ! empty( $name ) ? $name : $address;

            if ( $this->po->addAddress( $address, $label ) ) {

                $added++;
            }
        }

        return $added;
    }
}
