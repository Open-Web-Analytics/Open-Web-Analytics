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
 * The custom report roster.
 *
 * GATED ON view_site_list, which is the capability that means "any signed-in
 * user". Not edit_reports -- a reader who cannot author a report can still be
 * sent one, and the roster is where they find what they have. And not
 * view_reports either, which looks like the obvious choice and is wrong:
 * view_reports is listed in capabilitiesThatRequireSiteAccess, so it is only
 * satisfied against a particular site. The roster has no site -- it lists
 * reports, and a custom report is site-agnostic -- so requiring view_reports
 * refuses every non-admin with "No access to any site", which is what the e2e
 * suite caught.
 *
 * What edit_reports changes is whether the New and Edit controls appear.
 *
 * WHAT THE ROSTER FILTERS, AND WHAT IT DOES NOT
 *
 * Ownership decides what is LISTED. An admin sees every report; everyone else
 * sees the ones they created. It does not decide what may be OPENED -- a
 * report reached by its URL renders for anyone who may view reports, which is
 * what makes a custom report shareable. That is safe because a custom report
 * is a saved QUERY rather than saved data: every figure in it is one its reader
 * could already have asked for through the ordinary reporting UI.
 *
 * @since owa 1.8.0
 */
class CustomReports extends \OWA\Core\ReportController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'view_site_list' );
    }

    function action() {

        $user    = \OWA\Core\CoreAPI::getCurrentUser();
        $user_id = (string) $user->getUserData( 'user_id' );

        /*
         * "May see everything" is asked as a CAPABILITY, not by comparing the
         * role name to 'admin'. Roles are configurable -- an installation can
         * grant edit_users to a role of its own -- so a string comparison here
         * would answer differently from the rest of the application.
         */
        $sees_all = (bool) $user->isCapable( 'edit_users' );

        $reports = \OWA\Module\Base\Classes\CustomReports::roster( $user_id, $sees_all );

        $this->set( 'custom_reports', $reports );
        $this->set( 'sees_all', $sees_all );
        $this->set( 'may_author', (bool) $user->isCapable( 'edit_reports' ) );
        $this->set( 'current_user_id', $user_id );
    }

    function success() {

        $this->setSubview( 'base.customReports' );
        $this->setView( 'base.report' );
        $this->set( 'title', 'Custom Reports' );

        /*
         * The roster is a list of reports, not a report: it has no period and
         * no site of its own. The sites filter is hidden for the same reason
         * the Sites roster hides it -- there is nothing on this page it would
         * change.
         */
        $this->hideReportingNavigation();
        $this->hideSitesFilter();
    }
}
