<?php

namespace OWA\Module\Base\Entity;

/**
 * What one user has done with one notification.
 *
 * Two independent facts, and they are NOT the same fact:
 *
 *   read_at      -- they have seen it. The headline stops being bold and it
 *                   stops counting towards the badge, but it STAYS in the
 *                   list.
 *   dismissed_at -- they are finished with it. It leaves the list.
 *
 * An earlier version of this collapsed them, so the row's existence meant
 * "dismissed" and reading something removed it. That is not how a
 * notification list behaves anywhere people have used one: opening the panel
 * clears the count, and the items remain until you clear them.
 *
 * One row per (notification, user), carrying both, so a user can be at any of
 * the four combinations without two tables having to agree with each other.
 *
 * A join table rather than columns on Notification, because a notification is
 * global and this is not -- and rather than a serialized list on the user,
 * because that could not be counted in SQL and would lose a write to the last
 * writer when two tabs are open.
 */
class NotificationState extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('notification_state');

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

        /*
         * Timestamps rather than flags, because "when" is worth keeping and a
         * timestamp answers "whether" as well. Zero means not yet -- the entity
         * layer writes 0 for numeric columns, so absent and zero read the same.
         */
        $read_at = new \OWA\Module\Base\Classes\DbColumn( 'read_at', OWA_DTD_INT );
        $this->setProperty($read_at);

        $dismissed_at = new \OWA\Module\Base\Classes\DbColumn( 'dismissed_at', OWA_DTD_INT );
        $this->setProperty($dismissed_at);
    }
}
