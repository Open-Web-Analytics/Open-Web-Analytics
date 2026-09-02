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

        /*
         * createSite(), not createNewSite().
         *
         * createNewSite() refuses a domain that already has a site -- which
         * made a SECOND Observation Profile for a website impossible, the very
         * thing Properties exist to allow. nextProfileName() numbers them
         * "Observation Profile 1", "2", "3"; that counter could never reach 2
         * through this screen.
         */
        $site = $sm->createSite( $this->getParam( 'domain' ),
                            $this->getParam( 'name' ),
                            $this->getParam( 'description' ),
                            $this->getParam( 'site_family' ),
                            '',
                            $this->getParam( 'propertyId' ),
                            $this->getParam( 'streamType' ),
                            $this->getParam( 'appId' )
        );
        
        if ( $site ) {
	        
	    	\OWA\Core\CoreAPI::debug( "Site added successfully. site_id: " . $site->get('site_id') );
        }
        
        $this->set( 'site', $site->_getProperties() );
        
    }
    
    /**
     * The domain is NOT validated here any more, because it is not set here.
     *
     * Three rules used to guard it, all of them from 2009, when a site WAS a
     * domain: site_id was md5( domain ), so the domain was the primary-key
     * material. It had to be present, it had to carry a scheme, and it had to
     * be unique. Identifiers are minted now, and every one of those reasons is
     * gone -- but the rules outlived them into preventing two things this
     * hierarchy was built to allow:
     *
     *   - the uniqueness check refused a second Observation Profile for a
     *     website you already track, which is exactly what a Property is for;
     *   - and it did not exclude ARCHIVED Profiles, so archiving one made its
     *     domain permanently unusable -- the row kept to make the deletion
     *     recoverable was what blocked starting over.
     *
     * `required` had to go for its own reason: a Property's domain is optional
     * -- an app has none -- so requiring one to create a Profile meant an app
     * could be a Property and never be observed.
     *
     * The domain belongs to the Property and is validated on the Property
     * screen. What this screen needs is to know WHICH Property, and that is
     * only required when it is not creating one.
     */
    function validate() {

        $this->validateStreamIdentity();

        if ( $this->getParam( 'propertyId' ) ) {

            $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'entityExists',
                array(
                    'entity'   => 'base.property',
                    'column'   => 'id',
                    'errorMsg' => 'That Property no longer exists.',
                ) );

            return;
        }

        /*
         * Creating a Property as well as a Profile. The name describes the
         * website, so it is what the new Property will be called -- and a
         * Property with no name has nothing to head its group in the site
         * selector.
         */
        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            array( 'errorMsg' => 'A name is needed -- it is what the new Property will be called.' ) );
    }

    /**
     * The identifier a Profile needs depends on what it observes.
     *
     * Called from validate() on both paths, because the type is a fact about
     * the Profile and is asked for whether or not a Property is being created
     * alongside it.
     */
    protected function validateStreamIdentity() {

        $type = $this->getParam( 'streamType' ) ?: \OWA\Module\Base\Entity\Site::STREAM_WEB;

        if ( $type === \OWA\Module\Base\Entity\Site::STREAM_WEB ) {

            $this->addValidation( 'domain', trim( (string) $this->getParam( 'domain' ) ), 'required',
                array( 'errorMsg' => 'A website Profile needs the domain of the site it observes.' ) );

            return;
        }

        $this->addValidation( 'appId', trim( (string) $this->getParam( 'appId' ) ), 'required',
            array( 'errorMsg' => 'An app Profile needs its bundle id or package name.' ) );
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
