<?php

namespace OWA\Module\Base\View;

class NotificationsRest extends \OWA\Core\View\RestApi {

    function render() {

        $notifications = (array) $this->get('notifications');

        // No count field: the badge is the length of this list, counted by the
        // code that draws it. A second number computed here is a second thing
        // that can be wrong.
        $this->setResponseData( array( 'notifications' => $notifications ) );
    }
}
