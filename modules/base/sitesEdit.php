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

require_once(OWA_BASE_DIR.'/owa_adminController.php');

/**
 * Edit User Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_sitesEditController extends owa_adminController {

    function __construct($params) {

        parent::__construct($params);

        $this->setRequiredCapability('edit_sites');
        $this->setNonceRequired();
    }

    public function validate()
    {
        // check that siteId is present
        $this->addValidation('siteId', $this->getParam('siteId'), 'required');

        // Check site exists
        $siteEntityConf = [
            'entity'    => 'base.site',
            'column'    => 'site_id',
            'errorMsg'  => $this->getMsg(3208)
        ];

        $this->addValidation('siteId', $this->getParam('siteId'), 'entityExists', $siteEntityConf);
    }

    function action() {

        // This needs form validation in a bad way.

        $site = owa_coreAPI::entityFactory('base.site');
        if (! $this->getParam('siteId')) {
            throw exception('No siteId passed on request');
        }
        $site->load( $site->generateId( $this->getParam('siteId') ) );
        $site->set('name', $this->getParam( 'name' ) );
        $site->set('domain', $this->getParam( 'domain' ) );
        $site->set('description', $this->getParam( 'description') );
        $site->save();

        //$data['view_method'] = 'redirect';
        //$data['do'] = 'base.sites';
        $this->setRedirectAction('base.sites');
        $this->set('status_code', 3201);
    }
}

?>