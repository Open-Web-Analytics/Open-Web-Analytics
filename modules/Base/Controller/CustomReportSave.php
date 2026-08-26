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

        $this->type = 'options';
        $this->setRequiredCapability( 'edit_reports' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'customReportName', $this->getParam( 'customReportName' ), 'required' );
        $this->addValidation( 'customReportDefinition', $this->getParam( 'customReportDefinition' ), 'required' );
    }

    function action() {

        $user    = \OWA\Core\CoreAPI::getCurrentUser();
        $user_id = (string) $user->getUserData( 'user_id' );

        $id = (string) $this->getParam( 'customReportId' );

        /*
         * An EDIT is authorised against the stored row, not against the id in
         * the request. Checking the id would only prove the request named a
         * report; this proves the requester may change THAT one.
         */
        if ( $id !== '' ) {

            $existing = \OWA\Module\Base\Classes\CustomReports::load( $id );

            $may = \OWA\Module\Base\Classes\CustomReports::mayEdit(
                $existing, $user_id, (bool) $user->isCapable( 'edit_users' ) );

            if ( ! $may ) {

                return $this->refuse( 'That report belongs to somebody else.' );
            }
        }

        $result = \OWA\Module\Base\Classes\CustomReports::save( array(
            'id'         => $id,
            'name'       => $this->getParam( 'customReportName' ),
            'definition' => $this->getParam( 'customReportDefinition' ),
        ), $user_id );

        if ( ! $result['ok'] ) {

            return $this->refuse( $result['error'] );
        }

        $this->setStatusCode( 2504 );

        // Straight to the report itself. The author's next question is always
        // whether it looks right, and the roster cannot answer that.
        $this->set( 'reportId', 'custom-' . $result['id'] );

        /*
         * The site travels with the redirect, and it has to.
         *
         * view_reports is only satisfied against a PARTICULAR site, so a report
         * URL naming no site is refused for everyone who is not an admin -- and
         * this URL is the one the author lands on and then copies to somebody
         * else. Without this the share link works for its author and for nobody
         * they send it to, which is the one failure mode this feature cannot
         * afford. Caught by the e2e suite, not by any unit test: it needs a real
         * user with a real site grant to reproduce.
         */
        $siteId = (string) $this->getParam( 'siteId' );

        if ( $siteId !== '' ) {

            $this->set( 'siteId', $siteId );
        }

        $this->setRedirectAction( 'base.report' );
    }

    /**
     * Back to the builder with what was typed, and why it was refused.
     *
     * Re-rendering rather than redirecting is what keeps the author's work: a
     * redirect would hand back an empty form and the reason for it.
     */
    private function refuse( $message ) {

        $this->set( 'custom_report_error', $message );

        $this->setView( 'base.options' );
        $this->setSubview( 'base.customReportEdit' );

        $this->set( 'custom_report_id', (string) $this->getParam( 'customReportId' ) );
        $this->set( 'custom_report_name', (string) $this->getParam( 'customReportName' ) );

        $decoded = json_decode( (string) $this->getParam( 'customReportDefinition' ), true );

        $this->set( 'custom_report_definition', is_array( $decoded ) ? $decoded : array() );

        $this->set( 'metric_choices',    CustomReportEdit::metricChoices() );
        $this->set( 'dimension_choices', CustomReportEdit::dimensionChoices() );
        $this->set( 'widget_types',      \OWA\Module\Base\Classes\CustomReports::WIDGET_TYPES );
        $this->set( 'max_widgets',       \OWA\Module\Base\Classes\CustomReports::MAX_WIDGETS );
    }

    function errorAction() {

        $this->refuse( 'A custom report needs a name and at least one widget.' );
    }
}
