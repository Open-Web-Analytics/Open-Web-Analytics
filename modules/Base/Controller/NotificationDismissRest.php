<?php

namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\NotificationManager;

/**
 * DELETE v1/notifications/{id} -- one user is done with one notification.
 *
 * REST and nothing else, deliberately. The badge sits in the header of every
 * page, so dismissing must not be a navigation.
 *
 * Nonce required: this writes on behalf of the logged-in user.
 *
 * No capability beyond being someone. Dismissing is scoped to the caller, so
 * the worst anyone can do is clear their own badge -- and the user id comes
 * from the SESSION, never from a parameter. Reading it from the request would
 * let one user dismiss another\'s notifications.
 */
class NotificationDismissRest extends \OWA\Core\AdminController {

    public function __construct($params) {

        parent::__construct($params);

        /*
         * `view_site_list` is the gate, and the choice is not arbitrary:
         *
         *   - Without SOME capability the route answers ANONYMOUS callers --
         *     200 with the list, 202 to a DELETE. The edge refuses
         *     unauthenticated API calls on this deployment, which is what hid
         *     that; the application must not depend on it.
         *   - NOT `install_schema`: the `everyone` role holds it, so requiring
         *     it gates nothing.
         *   - NOT `view_reports`: a role can be given access without it, and
         *     a notification is not a report.
         *
         * `view_site_list` is held by viewer, analyst and admin and not by
         * `everyone`: any signed-in user. A notification is about no
         * Property, so no access list is checked.
         */
        $this->setRequiredCapability('view_site_list');
        $this->setNonceRequired();
    }


    public function validate() {

        $this->addValidation( 'notificationId', $this->getParam('notificationId'), 'required',
            [ 'stopOnError' => true ] );
    }

    public function action() {

        NotificationManager::dismiss(
            $this->getParam('notificationId'),
            \OWA\Core\CoreAPI::getCurrentUser()->getUserData('user_id') );
    }

    public function success() {

        $this->restResponse( 202 );
    }

    public function errorAction() {

        $this->restResponse( 422 );
    }
}
