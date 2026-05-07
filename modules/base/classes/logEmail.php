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


class owa_logEmail {

    var $name = 'generic_console_log';
    var $subject = 'uncaught exception';

    function __construct( $conf = array() ) {

        if ( array_key_exists( 'name', $conf ) ) {

            $this->name = $conf['name'];
        }

        if ( array_key_exists( 'subject', $conf ) ) {

            $this->subject = $conf['subject'];
        }

    }

    function append( $msg ) {

        $address = owa_coreAPI::getSetting('base', 'notice_email');

        $mailer = owa_coreAPI::supportClassFactory('base', 'mailer');
        $mailer->addAddress( $address, '');
        //$mailer->setFrom('owa@localhost', 'Open Web Analytics');
        $mailer->setSubject($this->subject);
        $mailer->setHtmlBody( $msg );
        $mailer->send();

    }

}

?>