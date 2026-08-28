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

        /*
         * The sort rides on the URL rather than being remembered, so a link to
         * the roster shows the same order to whoever opens it. Both halves come
         * from the request and neither is trusted: the column is resolved
         * through an allowlist, and the direction is a boolean.
         */
        $sort = (string) $this->getParam( 'rosterSort' );

        if ( ! isset( \OWA\Module\Base\Classes\CustomReports::ROSTER_SORTS[ $sort ] ) ) {

            $sort = \OWA\Module\Base\Classes\CustomReports::ROSTER_DEFAULT_SORT;
        }

        $descending = $this->getParam( 'rosterDesc' );
        $descending = $descending === null || $descending === ''
            ? null
            : (bool) $descending;

        $reports = \OWA\Module\Base\Classes\CustomReports::roster(
            $user_id, $sees_all, $sort, $descending );

        // What the headings need to draw themselves: which one is active, and
        // which way, so each can link to the OPPOSITE of what it shows now.
        $this->set( 'roster_sort', $sort );
        $this->set( 'roster_desc', $descending === null
            ? ( $sort === \OWA\Module\Base\Classes\CustomReports::ROSTER_DEFAULT_SORT )
            : $descending );

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
         * $this->data, NOT $this->get().
         *
         * Controller::get() is getParam() -- it reads the REQUEST. Asking it
         * for something action() set returns whatever the URL happened to
         * carry, which is nothing, so the count read as 0 and the New button
         * was never offered.
         */
        $this->set( 'title_count', count( (array) ( $this->data['custom_reports'] ?? array() ) ) );

        /*
         * New sits on the title's line, because it acts on the whole screen.
         * Offered only to someone who may author one -- a reader who cannot
         * would get a button that leads to a refusal.
         */
        if ( ! empty( $this->data['may_author'] ) ) {

            $this->set( 'title_actions', array(
                array(
                    'url'   => \OWA\Core\CoreAPI::supportClassFactory( 'base', 'template' )
                                   ->makeLink( array( 'do' => 'base.customReportEdit' ) ),
                    'label' => 'New Custom Report',
                    'icon'  => 'fa-plus',
                ),
            ) );
        }

        /*
         * The reporting NAV stays: the roster is part of the reporting UI, and
         * a reader who opens it should be able to get anywhere else from it
         * without going back first.
         *
         * The period picker and Live View do NOT: this is a list of reports,
         * not a report of a time range, and neither control would change
         * anything on the page. The sites filter goes for the same reason --
         * a custom report is site-agnostic, so the list is the same whichever
         * site is selected.
         */
        $this->hideTimeControls();
        $this->hideSitesFilter();
    }
}
