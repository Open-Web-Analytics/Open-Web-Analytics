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

class SitesEditSettings extends \OWA\Core\AdminController {

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

        // Each setting the screen would store, checked against its declaration.
        foreach ( \OWA\Module\Base\Classes\SettingsForm::scopedProblems(
                      \OWA\Module\Base\Classes\SettingsForm::registeredFieldSet( 'base.profileObservation' ),
                      'profile', (string) $this->getParam( 'siteId' ),
                      $this->getParam( 'config' ), $this->getParam( 'override' ) ) as $name => $problem ) {

            $this->addValidation( $name, '', 'required', array( 'errorMsg' => $problem ) );
        }
    }

    function action() {

        $site_id = $this->getParam( 'siteId' );

        /*
         * One scoped row per setting the screen shows, decided by each field's
         * Override switch: on stores the value at this Profile, off removes any
         * value it holds. See SettingsForm::saveScoped().
         */
        $saved = \OWA\Module\Base\Classes\SettingsForm::saveScoped(
            \OWA\Module\Base\Classes\SettingsForm::registeredFieldSet( 'base.profileObservation' ),
            'profile', (string) $site_id,
            (array) $this->getParam( 'config' ), (array) $this->getParam( 'override' ) );

        if ( $saved ) {

            $this->setStatusCode( 3201 );
        }

        $this->set('siteId', $site_id);
        $this->set('edit', true);
        $this->setRedirectAction( 'base.sitesProfile' );
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
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->load( $site->generateId( $owa_site_id ) );
        $this->set('site', $site->_getProperties());

        /*
         * What the form sent -- each value and each switch -- so correcting the
         * reported error and saving again does not write over the rest.
         */
        $this->set( 'posted', array(
            'config'   => (array) $this->getParam( 'config' ),
            'override' => (array) $this->getParam( 'override' ),
        ) );

        // Also the resolved id, so the form posts back to the site it is showing.
        $this->set( 'siteId', $owa_site_id );
    }
}

?>