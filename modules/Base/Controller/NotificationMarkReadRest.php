<?php

namespace OWA\Module\Base\Controller;

use OWA\Module\Base\Classes\NotificationManager;

/**
 * POST v1/notifications/{id} -- one user has read one notification.
 *
 * Separate from dismissing because they are separate facts: reading clears the
 * badge and unbolds the headline while the notification STAYS in the list;
 * dismissing removes it. One route could not express both without a mode
 * parameter deciding which write happened, which is how a caller ends up
 * dismissing something it meant to mark read.
 *
 * The user id comes from the SESSION, never a parameter.
 */
class NotificationMarkReadRest extends \OWA\Core\AdminController {

    function __construct($params) {

        parent::__construct($params);

        // See NotificationsRest for why this capability and not another.
        $this->setRequiredCapability('view_site_list');
        $this->setNonceRequired();
    }

    function validate() {

        $this->addValidation( 'notificationId', $this->getParam('notificationId'), 'required',
            array( 'stopOnError' => true ) );
    }

    function action() {

        NotificationManager::markRead(
            $this->getParam('notificationId'),
            \OWA\Core\CoreAPI::getCurrentUser()->getUserData('user_id') );
    }

    function success() {

        http_response_code(202);
        $this->setView( 'base.notificationMarkReadRest' );
    }

    function errorAction() {

        http_response_code(422);
        $this->setView( 'base.notificationMarkReadRest' );
    }
}
