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
 * API Request Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.3.0
 */

class ApiRequest extends \OWA\Core\Controller {

    function __construct($params) {

        return parent::__construct($params);
    }

    function getRequiredCapability() {

        $s = \OWA\Core\CoreAPI::serviceSingleton();
            // lookup method class
        $do = $s->getApiMethodClass($this->getParam('do'));

        if ($do) {

            // check for capability
            if (array_key_exists('required_capability', $do)) {
                return $do['required_capability'];
            }
        }
    }

    function doAction() {


        /* CHECK USER FOR CAPABILITIES */
        if ( ! $this->checkCapabilityAndAuthenticateUser( $this->getRequiredCapability() ) ) {

            return $this->data;
        }

        /* PERFORM PRE ACTION */
        // often used by abstract descendant controllers to set various things
        $this->pre();
        /* PERFORM MAIN ACTION */
           return $this->finishActionCall($this->action());
    }

    function action() {

        //determine output format, json is default.
        $format = $this->getParam('format');

        if ( ! $format ) {

            $format = 'json';
        }

        // set content type of reponse
        \OWA\Core\Lib::setContentTypeHeader($format);

        $map = \OWA\Core\CoreAPI::getRequest()->getAllOwaParams();
        $output = \OWA\Core\CoreAPI::executeApiCommand($map);

        // assign to a view for output
        if ( $format === 'json' || $format === 'jsonp') {

            $this->setView( 'base.json' );
            $this->set( 'json', $output );
            $this->set( 'format', $format );

            if ( $format ==='jsonp' ) {

                $this->set('jsonpCallback', $this->getParam('jsonpCallback') );
            }

        } else {
            //@todo move this to a generic raw output view.
            echo $output;
        }

    }

    function notAuthenticatedAction() {

        $this->setErrorMsg('Authentication failed.');
        $this->setView('base.apiError');
    }

    function authenticatedButNotCapableAction($additionalMessage = '') {
        $this->setErrorMsg('Thus user is not capable to perform this api method.');
        $this->setView('base.apiError');
    }
}



?>
