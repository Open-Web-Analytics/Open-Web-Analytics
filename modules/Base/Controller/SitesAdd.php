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

class SitesAdd extends \OWA\Core\AdminController {
	
	function __construct( $params ) {
		
		$this->setRequiredCapability('edit_sites');
		
		return parent::__construct( $params );
	}
	
    function init() {
	    
        $this->setNonceRequired();
      
    }

    function action() {

        $this->set('domain', $this->getParam('protocol').$this->getParam('domain') );

        $sm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );

        $site = $sm->createNewSite( $this->getParam( 'domain' ),
                            $this->getParam( 'name' ),
                            $this->getParam( 'description' ),
                            $this->getParam( 'site_family' )
        );
        
        if ( $site ) {
	        
	    	\OWA\Core\CoreAPI::debug( "Site added successfully. site_id: " . $site->get('site_id') );
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
	    
	    $this->setRedirectAction('base.reportingHome');
        $this->set('status_code', 3202);
    }

    function errorAction() {

        /*
         * The hierarchy wrapper. There is one settings nav now -- the old
         * base.options menu is gone -- so every settings screen carries the tile
         * and the tier groups, module screens included.
         */
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        $this->set( 'hierarchy_tier', 3 );
        $this->setView('base.optionsHierarchy');
        $this->setSubview('base.sitesProfile');
        /*
         * 3002 -- "the form had errors" -- not 3311.
         *
         * 3311 has been the CLI-updates message since 2010, and this screen has
         * set 3311 since 2009: the later commit took a code three form screens
         * were already using. Latent rather than visible, because
         * Controller::doAction() sets validation_errors before calling
         * errorAction() and msgs.php shows error_msg only when there are none --
         * so the CLI text was suppressed on the ordinary path and would have
         * appeared the moment that branch changed.
         */
        $this->set('error_code', 3002);
        $this->set('site', $this->params);
    }

}

?>
