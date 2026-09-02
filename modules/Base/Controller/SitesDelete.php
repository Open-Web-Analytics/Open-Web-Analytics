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
 * Delete Site Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class SitesDelete extends \OWA\Core\AdminController {

    function __construct($params) {
        parent::__construct($params);
        $this->setRequiredCapability('edit_sites');
        $this->setNonceRequired();
    }

    /**
     * Archives the Profile rather than destroying it.
     *
     * The row deletion left everything hanging off it behind -- the access
     * grants in owa_site_user, the scoped settings rows, and every fact row
     * ever collected under that site_id -- as orphans that a re-minted
     * identifier could have inherited. It was also unrecoverable.
     *
     * Archiving stamps a date and stops the Profile being listed, reported on,
     * or collected for. Everything it owns stays exactly where it is, which is
     * what makes a restore possible.
     */
    function action() {

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');
        $site->load( $site->generateId( $this->getParam( 'siteId' ) ) );

        if ( $site->wasPersisted() ) {

            $site->set( 'archived_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $site->update();
        }

        $this->setRedirectAction('base.reportingHome');
        $this->set('status_code', 3204);
    }
}

?>