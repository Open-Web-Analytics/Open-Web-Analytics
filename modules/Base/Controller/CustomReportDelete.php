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
 * Deletes one custom report.
 *
 * Nonce-required. A delete reached by following a link is the case nonces exist
 * for, and this one destroys the only copy -- a custom report has no file to
 * fall back on the way a shipped report does.
 *
 * @since owa 1.8.0
 */
class CustomReportDelete extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';
        $this->setRequiredCapability( 'edit_reports' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'customReportId', $this->getParam( 'customReportId' ), 'required' );
    }

    function action() {

        $id = (string) $this->getParam( 'customReportId' );

        $report = \OWA\Module\Base\Classes\CustomReports::load( $id );

        $user = \OWA\Core\CoreAPI::getCurrentUser();

        /*
         * Authorised against the stored ROW. The id in the request only proves
         * which report was named; this proves the requester may destroy it.
         */
        $may = \OWA\Module\Base\Classes\CustomReports::mayEdit(
            $report,
            (string) $user->getUserData( 'user_id' ),
            (bool) $user->isCapable( 'edit_users' )
        );

        if ( $may ) {

            \OWA\Module\Base\Classes\CustomReports::delete( $id );

            $this->setStatusCode( 2504 );

        } else {

            /*
             * A report somebody else owns, or one that is already gone, are
             * answered the same way: back to the roster, nothing destroyed.
             * Distinguishing them would tell a caller whether an id they are
             * not allowed to touch exists.
             */
            $this->setStatusCode( 3311 );
        }

        $this->setRedirectAction( 'base.customReports' );
    }

    function errorAction() {

        $this->setRedirectAction( 'base.customReports' );
    }
}
