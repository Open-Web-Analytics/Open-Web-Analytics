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

/**
 * Applies a change to which users may see a site's data.
 *
 * The submission is a delta, not a replacement: the form posts the ids it
 * rendered alongside the ids that were checked, and only users appearing in
 * the rendered list can be affected. That is what makes an empty submission
 * mean "everyone shown was unchecked" rather than "revoke every user of this
 * site", which is what it meant when the form was a multi-select and the
 * absence of a field was indistinguishable from a deliberate clearing.
 */
class SitesEditAllowedUsers extends \OWA\Module\Base\Controller\SitesEditSettings {

    function action() {

        $site_id = $this->getParam( 'siteId' );

        $siteEntity = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $siteEntity->load( $siteEntity->generateId( $site_id ) );

        $rendered = $this->getParam( 'rendered_users' );
        $checked  = $this->getParam( 'allowed_users' );

        $siteEntity->applyAssignedUserChanges(
            is_array( $rendered ) ? $rendered : array(),
            is_array( $checked )  ? $checked  : array()
        );

        $this->set('siteId', $site_id);
        $this->set('edit', true);
        $this->setStatusCode( 3201 );
        $this->setRedirectAction( 'base.sitesProfile' );
    }
}

?>
