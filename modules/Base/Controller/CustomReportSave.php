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
 * Stores what the builder produced.
 *
 * The definition arrives as JSON assembled by the form. It is validated by
 * CustomReports::save() -- widget count, widget types, and every metric,
 * dimension and sort name resolved through the registry -- and a definition
 * that fails is not stored at all.
 *
 * Nonce-required, like every other form that writes: without it, a link
 * somebody follows could quietly rewrite one of their reports.
 *
 * @since owa 1.8.0
 */
class CustomReportSave extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_reports' );
        $this->setNonceRequired();

        /*
         * The Property the report is saved to: the one the form posts, else an
         * existing report's own (CustomReports::requestProperty()).
         */
        $propertyId = \OWA\Module\Base\Classes\CustomReports::requestProperty(
            (string) $this->getParam( 'customReportId' ), $this->getParam( 'propertyId' ), $this->getCurrentSiteId() );

        if ( $propertyId !== '' ) {

            $this->setParam( 'propertyId', $propertyId );
        }
    }

    public function validate() {

        $this->addValidation( 'customReportName', $this->getParam( 'customReportName' ), 'required' );
        $this->addValidation( 'customReportDefinition', $this->getParam( 'customReportDefinition' ), 'required' );
    }

    function action() {

        $user    = \OWA\Core\CoreAPI::getCurrentUser();
        $user_id = (string) $user->getUserData( 'user_id' );

        $id         = (string) $this->getParam( 'customReportId' );
        $propertyId = (string) $this->getParam( 'propertyId' );

        /*
         * Saved only to a Property the author may report on, a create as well
         * as an edit: edit_reports is held on every Property. Refused as an
         * unknown report is, so a Property's reports are not told apart from
         * none. No Property at all is save()'s to refuse.
         */
        if ( $propertyId !== '' && ! \OWA\Module\Base\Classes\CustomReports::mayReportOn( $propertyId ) ) {

            return $this->refuse( \OWA\Core\CoreAPI::t( 'That report does not exist.' ) );
        }

        /*
         * An EDIT is authorised against the stored row, not against the id in
         * the request. Checking the id would only prove the request named a
         * report; this proves the requester may change THAT one.
         */
        if ( $id !== '' ) {

            /*
             * Of this Property, or not found: asked before ownership, so a
             * report of another Property is answered as one that does not
             * exist rather than as somebody else's.
             */
            $existing = \OWA\Module\Base\Classes\CustomReports::loadOf( $id, $propertyId );

            if ( ! $existing ) {

                return $this->refuse( \OWA\Core\CoreAPI::t( 'That report does not exist.' ) );
            }

            $may = \OWA\Module\Base\Classes\CustomReports::mayEdit(
                $existing, $user_id, (bool) $user->isCapable( 'edit_users' ) );

            if ( ! $may ) {

                return $this->refuse( \OWA\Core\CoreAPI::t( 'That report belongs to another user.' ) );
            }
        }

        // Stored on, and validated against the dimensions of, the Property the
        // builder was on, which the form posts: a custom dimension of its is a
        // choice there. An existing report's is its own (loadOf() above).
        $result = \OWA\Module\Base\Classes\CustomReports::save( array(
            'id'         => $id,
            'name'       => $this->getParam( 'customReportName' ),
            'definition' => $this->getParam( 'customReportDefinition' ),
            'is_shared'  => (bool) $this->getParam( 'isShared' ),
        ), $user_id, $propertyId );

        if ( ! $result['ok'] ) {

            return $this->refuse( $result['error'] );
        }

        /*
         * Created and saved say different things, because they answer different
         * questions -- "did my new report get made" and "did my change stick".
         *
         * Codes of their own, too. This used to set 2504, which the shared
         * catalogue defines as "Goal Saved."
         */
        $this->setStatusCode( $id === '' ? 2510 : 2511 );

        // Straight to the report itself. The author's next question is always
        // whether it looks right, and the roster cannot answer that.
        $this->set( 'reportId', 'custom-' . $result['id'] );

        /*
         * The Property travels with the redirect, and it has to.
         *
         * view_reports is only satisfied against a PARTICULAR Property, so a
         * report URL naming none is refused for everyone who is not an admin -- and
         * this URL is the one the author lands on and then copies to somebody
         * else. Without this the share link works for its author and for nobody
         * they send it to, which is the one failure mode this feature cannot
         * afford. Caught by the e2e suite, not by any unit test: it needs a real
         * user with a real site grant to reproduce.
         */
        if ( $propertyId !== '' ) {

            $this->set( 'propertyId', $propertyId );
        }

        $this->setRedirectAction( 'base.report' );
    }

    /**
     * Back to the builder, with what was typed and why it was refused.
     *
     * DELEGATED rather than rendered here, the same way the report dispatcher
     * delegates: the builder is a reporting screen and needs the chrome that
     * ReportController::pre() supplies, and this controller is a write that
     * redirects on success. Setting the report view from here instead produced
     * an entirely blank page -- no site list, no period, nothing for the view
     * to render -- so an author who mistyped a metric name got a blank screen
     * rather than a message.
     *
     * Making THIS a ReportController was the other thing tried, and it broke
     * the success path: setRedirectAction() copies the controller's data into
     * the redirect, and the report chrome is a site list and a period object.
     *
     * The submitted definition rides along in the params, so the builder
     * redraws what the author had rather than what was last stored.
     *
     * Returned from both action() and errorAction(), and doAction() now uses
     * what either one hands back. It used to discard errorAction()'s return and
     * render this controller's own data, which names no view -- so a refusal
     * raised by validate() rather than by action() was a blank page. That is
     * fixed in Core/Controller.php; this assigned to $this->data as well to
     * work around it, and no longer needs to.
     */
    private function refuse( $message ) {

        $params = $this->params;

        $params['do']                = 'base.customReportEdit';
        $params['customReportError'] = $message;

        $target = new CustomReportEdit( $params );

        return $target->doAction();
    }

    function errorAction() {

        return $this->refuse( \OWA\Core\CoreAPI::t( 'A custom report needs a name and at least one widget.' ) );
    }
}
