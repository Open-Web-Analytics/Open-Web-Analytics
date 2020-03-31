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

require_once(OWA_BASE_DIR.'/owa_module.php');

/**
 * Remote Queue Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2014 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.5.7
 */

class owa_remoteQueueModule extends owa_module {

    function __construct() {

        $this->name = 'remoteQueue';
        $this->display_name = 'Remote Queue';
        $this->group = 'logging';
        $this->author = 'Peter Adams';
        $this->version = '1.0';
        $this->description = 'Posts incoming tracking events to a remote instance of OWA';
        $this->config_required = false;
        $this->required_schema_version = 1;

        // register named queues

        $endpoint = owa_coreAPI::getSetting( 'remoteQueue', 'endpoint' );

        if ( $endpoint ) {

            $this->registerEventQueue( 'incoming_tracking_events', array(

                'queue_type'            =>     'http',
                'endpoint'                =>    $endpoint
            ));
        }

        return parent::__construct();
    }
}