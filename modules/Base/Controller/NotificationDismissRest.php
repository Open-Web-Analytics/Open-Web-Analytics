<?php

namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\NotificationManager;

/**
 * DELETE v1/notifications/{id} -- one user is done with one notification.
 *
 * REST and nothing else, deliberately. The badge sits in the header of every
 * page, so dismissing must not be a navigation; a second link-and-redirect
 * route would be a second write path maintained for a UI that no longer
 * exists.
 *
 * Nonce required: this writes on behalf of the logged-in user.
 *
 * No capability beyond being someone. Dismissing is scoped to the caller, so
 * the worst anyone can do is clear their own badge -- and the user id comes
 * from the SESSION, never from a parameter. Reading it from the request would
 * let one user dismiss another\'s notifications, which is the entire security
 * surface of this feature.
 */
class NotificationDismissRest extends \OWA\Core\AdminController {

    function __construct($params) {

        parent::__construct($params);

        /*
         * Capability set AFTER parent::__construct(), matching ReportsRest --
         * the browser calls that route on every report page, so it is the
         * working example of a REST route reached with a session rather than
         * an API key. Setting it before the parent runs makes the constructor
         * take the admin-page path and answer with an HTML error document
         * instead of the REST view.
         *
         * `view_site_list` is the gate, and the choice is not arbitrary:
         *
         *   - Without SOME capability the route answers ANONYMOUS callers --
         *     200 with the list, 202 to a DELETE. The edge refuses
         *     unauthenticated API calls on this deployment, which is what hid
         *     that; the application must not depend on it.
         *   - NOT `install_schema`: the `everyone` role holds it, so requiring
         *     it gates nothing.
         *   - NOT `view_reports`: it is in capabilitiesThatRequireSiteAccess,
         *     so it is checked against a SITE. A notification is not about a
         *     site, this request carries no siteId, and the check fails with
         *     "No access to any site" for a perfectly entitled user.
         *
         * `view_site_list` is held by viewer, analyst and admin, is not held by
         * `everyone`, and is not site-scoped -- which is exactly "any signed-in
         * user".
         */
        $this->setRequiredCapability('view_site_list');
        $this->setNonceRequired();
    }


    function validate() {

        $this->addValidation( 'notificationId', $this->getParam('notificationId'), 'required',
            array( 'stopOnError' => true ) );
    }

    function action() {

        NotificationManager::dismiss(
            $this->getParam('notificationId'),
            \OWA\Core\CoreAPI::getCurrentUser()->getUserData('user_id') );
    }

    function success() {

        http_response_code(202);
        $this->setView( 'base.notificationDismissRest' );
    }

    function errorAction() {

        http_response_code(422);
        $this->setView( 'base.notificationDismissRest' );
    }
}
