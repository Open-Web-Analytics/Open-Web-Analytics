<?php

namespace OWA\Module\Base\Entity;

/**
 * One person having starred one custom report or visualization.
 *
 * A row per (user, report) pair rather than a column on owa_custom_report,
 * because a favourite belongs to the READER and the report belongs to its
 * author -- a column would make one person's star everybody's.
 *
 * Modelled on notification_state, which is the same shape for the same reason:
 * per-user state about a shared row, joined rather than embedded. The roster
 * left-joins this to sort starred reports to the top, which is why both
 * columns are indexed -- it is read on every listing.
 */
class CustomReportFavorite extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName( 'custom_report_favorite' );

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $custom_report_id = new \OWA\Module\Base\Classes\DbColumn( 'custom_report_id', OWA_DTD_BIGINT );
        $custom_report_id->setIndex();
        $this->setProperty( $custom_report_id );

        // The user_id string, matching how base.user identifies a user -- the
        // same id owa_custom_report.user_id and notification_state.user_id use.
        $user_id = new \OWA\Module\Base\Classes\DbColumn( 'user_id', OWA_DTD_VARCHAR255 );
        $user_id->setIndex();
        $this->setProperty( $user_id );

        $creation_timestamp = new \OWA\Module\Base\Classes\DbColumn( 'creation_timestamp', OWA_DTD_INT );
        $this->setProperty( $creation_timestamp );
    }
}
