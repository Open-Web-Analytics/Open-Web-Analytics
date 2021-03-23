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

require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_adminController.php');


/**
 * Add Sites View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_sitesAddView extends owa_view {

    function render($data) {

        //page title
        $this->t->set('page_title', 'Add Web Site');
        $this->body->set('headline', 'Add Web Site Profile');
        // load body template
        $this->body->set_template('sites_addoredit.tpl');

        $this->body->set('action', 'base.sitesAdd');

        //Check to see if user is passed by constructor or else fetch the object.
        if ($data['site']) {
            $this->body->set('site', $data['site']);
        }
    }
}

/**
 * Add Site Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_sitesAddController extends owa_adminController {
	
	function __construct( $params ) {
		
		$this->setRequiredCapability('edit_sites');
		
		return parent::__construct( $params );
	}
	
    function init() {
	    
        $this->setNonceRequired();
      
    }

    function action() {

        $this->set('domain', $this->getParam('protocol').$this->getParam('domain') );

        $sm = owa_coreAPI::supportClassFactory( 'base', 'siteManager' );

        $site = $sm->createNewSite( $this->getParam( 'domain' ),
                            $this->getParam( 'name' ),
                            $this->getParam( 'description' ),
                            $this->getParam( 'site_family' )
        );
        
        if ( $site ) {
	        
	    	owa_coreAPI::debug( "Site added successfully. site_id: " . $site->get('site_id') );    
        }
        
        $this->set( 'site', $site->_getProperties() );
        
    }
    
    function validate() {
	    
	    // Config for the domain validation
        $domain_conf = array(
        	'substring' => 'http', 
        	'position' => 0, 
        	'operator' => '=', 
        	'errorMsg' => 'Please add the "http://" or "https://" to the beginning of your domain.'
        );

        // Add validations to the run
        $this->addValidation('domain', $this->getParam('domain'), 'subStringPosition', $domain_conf);
        
        $this->addValidation('domain', $this->getParam('domain'), 'required', array('stopOnError'	=> true));

        $siteEntityConf = [

             'entity'    => 'base.site',
             'column'    => 'domain',
             'errorMsg'  => $this->getMsg(3206)
         ];

         $this->addValidation('domain', $this->getParam('protocol').$this->getParam('domain'), 'entityDoesNotExist', $siteEntityConf);
    }
    
    function success() {
	    
	    $this->setRedirectAction('base.sites');
        $this->set('status_code', 3202);
    }

    function errorAction() {

        $this->setView('base.options');
        $this->setSubview('base.sitesProfile');
        $this->set('error_code', 3311);
        $this->set('site', $this->params);
    }

}

?>