<?php

namespace OWA\Module\Base\Classes;

/**
 * Starring a custom report or visualization, per reader.
 *
 * A favourite is the READER's, not the report's: it decides where a report sits
 * in that person's list and nothing about the report itself. So it never
 * appears on owa_custom_report -- see CustomReportFavorite for the shape and
 * why it mirrors notification_state.
 *
 * Nothing here is a permission. Starring something you can see does not change
 * what you can see, which is why toggling asks only that the report exists.
 */
class CustomReportFavorites {

    /**
     * Is this report starred by this user?
     *
     * @param string $report_id
     * @param string $user_id
     * @return bool
     */
    public static function isFavorite( $report_id, $user_id ) {

        if ( (string) $report_id === '' || (string) $user_id === '' ) {

            return false;
        }

        return self::rowId( $report_id, $user_id ) !== null;
    }

    /**
     * Star it if it is not, unstar it if it is.
     *
     * @param  string $report_id
     * @param  string $user_id
     * @return bool   the state it is in afterwards
     */
    public static function toggle( $report_id, $user_id ) {

        if ( (string) $report_id === '' || (string) $user_id === '' ) {

            return false;
        }

        $existing = self::rowId( $report_id, $user_id );

        if ( $existing !== null ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report_favorite' );
            $db     = \OWA\Core\CoreAPI::dbSingleton();

            $db->deleteFrom( $entity->getTableName() );
            $db->where( 'id', $existing );
            $db->executeQuery();

            return false;
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report_favorite' );

        $entity->set( 'id', $entity->generateId(
            'custom_report_favorite:' . $user_id . ':' . $report_id ) );
        $entity->set( 'custom_report_id', $report_id );
        $entity->set( 'user_id', (string) $user_id );
        $entity->set( 'creation_timestamp', \OWA\Core\CoreAPI::getRequestTimestamp() );
        $entity->create();

        return true;
    }

    /**
     * The ids this user has starred.
     *
     * Returned as a list rather than joined into the roster query, because the
     * roster is built by hand from a string of SQL and a LEFT JOIN there would
     * have to carry the user into every branch of it. A person has tens of
     * favourites, not thousands.
     *
     * @param  string $user_id
     * @return array  report id => true
     */
    public static function idsFor( $user_id ) {

        if ( (string) $user_id === '' ) {

            return array();
        }

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report_favorite' );

        $rows = (array) $db->get_results( sprintf(
            "SELECT custom_report_id FROM %s WHERE user_id = '%s'",
            $entity->getTableName(),
            $db->prepare( (string) $user_id ) ) );

        $ids = array();

        foreach ( $rows as $row ) {

            $ids[ (string) $row['custom_report_id'] ] = true;
        }

        return $ids;
    }

    /**
     * Forget every star on a report.
     *
     * Called when a report is deleted: the rows are about a report that no
     * longer exists, and leaving them would put a dead id at the top of
     * somebody's list if the id were ever reused.
     *
     * @param string $report_id
     */
    public static function forget( $report_id ) {

        if ( (string) $report_id === '' ) {

            return;
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report_favorite' );
        $db     = \OWA\Core\CoreAPI::dbSingleton();

        $db->deleteFrom( $entity->getTableName() );
        $db->where( 'custom_report_id', $report_id );
        $db->executeQuery();
    }

    /**
     * @return string|null the row id, or null when it is not starred
     */
    private static function rowId( $report_id, $user_id ) {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report_favorite' );

        $row = $db->get_row( sprintf(
            "SELECT id FROM %s WHERE custom_report_id = '%s' AND user_id = '%s'",
            $entity->getTableName(),
            $db->prepare( (string) $report_id ),
            $db->prepare( (string) $user_id ) ) );

        return ( is_array( $row ) && isset( $row['id'] ) ) ? (string) $row['id'] : null;
    }
}
