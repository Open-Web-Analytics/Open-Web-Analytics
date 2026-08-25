<?php

namespace OWA\Module\Base\Entity;

/**
 * One user has dismissed one notification.
 *
 * A row's EXISTENCE is the whole state: dismissed and read are the same thing
 * here by decision, so there is no flag to read and nothing that can disagree
 * with itself. The unread count is the notifications with no row for this user.
 *
 * A join table rather than a column on Notification, because a notification is
 * global and a dismissal is not -- and rather than a serialized list on the
 * user, because that could not be counted in SQL and would lose a dismissal to
 * the last writer when two tabs are open.
 */
class NotificationDismissal extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('notification_dismissal');

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty($id);

        $notification_id = new \OWA\Module\Base\Classes\DbColumn( 'notification_id', OWA_DTD_BIGINT );
        $notification_id->setIndex();
        $this->setProperty($notification_id);

        // The user_id string, matching how base.user identifies a user.
        $user_id = new \OWA\Module\Base\Classes\DbColumn( 'user_id', OWA_DTD_VARCHAR255 );
        $user_id->setIndex();
        $this->setProperty($user_id);

        $dismissed_at = new \OWA\Module\Base\Classes\DbColumn( 'dismissed_at', OWA_DTD_INT );
        $this->setProperty($dismissed_at);
    }
}
