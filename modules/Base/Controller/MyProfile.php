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
 * The signed-in user's own account.
 *
 * Their name, their email address and their password. Not other people's --
 * base.usersProfile is that screen and requires edit_users. This one always
 * acts on whoever is signed in, so it takes no user_id: a screen that accepted
 * one would have to prove the requester may edit that account, and there is no
 * reason for this screen to be able to edit any account but its own.
 *
 * @since owa 1.8.0
 */
class MyProfile extends \OWA\Core\Controller {

    function __construct( $params ) {

        /*
         * view_site_list is what every signed-in role carries -- admin,
         * analyst and viewer -- and nothing else does. Editing your own account
         * is not an administrative act, so gating this on edit_users or
         * edit_settings would leave most users with no way to change their own
         * name.
         */
        $this->setRequiredCapability( 'view_site_list' );

        return parent::__construct( $params );
    }

    function action() {

        $user = \OWA\Core\CoreAPI::getCurrentUser();

        $this->set( 'user_id', (string) $user->getUserData( 'user_id' ) );
        $this->set( 'real_name', (string) $user->getUserData( 'real_name' ) );
        $this->set( 'email_address', (string) $user->getUserData( 'email_address' ) );
        $this->set( 'role', (string) $user->getRole() );

        /*
         * Whether the address is theirs to change. The field is shown either
         * way -- knowing which address the account uses matters even when it
         * cannot be edited here -- but read-only without the capability, and
         * the save refuses it as well.
         */
        $this->set( 'may_edit_email', (bool) $user->isCapable( 'edit_own_email' ) );

        /*
         * A refused save comes back through here rather than redirecting, so
         * what was typed is still on the form. The password fields are
         * deliberately not carried back: they are secrets, and re-rendering
         * them would put them in the page source of a refusal.
         */
        foreach ( array( 'real_name', 'email_address' ) as $field ) {

            if ( $this->getParam( 'submitted_' . $field ) !== null ) {

                $this->set( $field, (string) $this->getParam( 'submitted_' . $field ) );
            }
        }

        if ( $this->getParam( 'myProfileError' ) ) {

            $this->set( 'my_profile_error', (string) $this->getParam( 'myProfileError' ) );
        }

        if ( $this->getParam( 'myProfileSaved' ) ) {

            $this->set( 'my_profile_saved', true );
        }

        /*
         * The settings chrome, the same way every other screen in that nav
         * gets it. Tier 0: this is about the person rather than about the
         * Organization, Property or Profile below it.
         */
        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->set( 'hierarchy_tier', 0 );

        // Tier 0 because it belongs to no Organization, but it is not an
        // install-wide setting, so the root crumb says whose screen this is.
        $this->set( 'hierarchy_root_label', 'My Preferences' );
        $this->set( 'siteId', $siteId );

        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.myProfile' );
    }
}
