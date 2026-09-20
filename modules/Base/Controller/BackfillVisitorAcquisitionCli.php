<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Fill in acquisition for visitors that predate it being recorded.
 *
 *   php cli.php cmd=backfill-visitor-acquisition --dry-run
 *   php cli.php cmd=backfill-visitor-acquisition
 *   php cli.php cmd=backfill-visitor-acquisition batch=2000
 *
 * Update032 adds the columns; new visitors get them written at creation by
 * VisitorHandlers. Everyone who already existed has NULL, and their acquisition
 * is still recoverable because owa_visitor.first_session_id names the session
 * they arrived in -- so this reads it back off that session.
 *
 * WHY IT IS A COMMAND AND NOT PART OF THE UPDATE
 * NULL acquisition is not a broken state. It is the absence the reporting layer
 * already renders as "(not set)", so an installation that never runs this is
 * consistent, only less complete -- unlike Update031, which had to run
 * automatically because skipping it left one column holding two spellings of
 * "unknown". And this joins owa_visitor to owa_session and four dimension
 * tables; on a shared database instance that is a decision for whoever knows
 * what else is running, not for whatever moment cmd=update happened to be typed.
 *
 * IT IS SAFE TO RUN AGAIN, AND SAFE TO STOP
 * Rows already filled no longer match "acquisition is still NULL", so a rerun
 * skips them and picks up where the last one stopped. Nothing tracks progress
 * because the data already shows it. The walk itself goes by visitor id rather
 * than by re-asking the predicate, because a visitor whose dimension rows are
 * gone gets NULL written over NULL and would otherwise match for ever -- see
 * the loop in action().
 *
 * IT NEVER OVERWRITES
 * Only rows that have none of the five values are touched. A value written by
 * VisitorHandlers at creation is the authority and is never revisited here --
 * this fills gaps, it does not re-derive. That matters because the two can
 * disagree: a session whose dimension rows have since been rewritten resolves to
 * something the original event did not say, and the original event is right.
 *
 * WHAT IT CANNOT RECOVER
 * A visitor whose first session has been deleted -- by retention, by a partition
 * drop, or because it predates the session table -- keeps NULL. That is correct
 * rather than unfortunate: the evidence is gone and inventing a value from a
 * later session would silently turn first-touch into some-touch.
 */
class BackfillVisitorAcquisitionCli extends \OWA\Core\Controller\Cli {

    /**
     * Rows per statement.
     *
     * Chunked rather than one UPDATE ... JOIN over the whole table because the
     * database is routinely shared: a single large write holds locks and
     * competes with whatever else lives on the instance. Batches give the
     * operator somewhere to stop.
     */
    const BATCH = 1000;

    /**
     * visitor column => how to reach the value from the session row.
     *
     * `medium` sits on owa_session as a literal already; the other four resolve
     * through their dimension tables. That asymmetry is 1.x's, not this
     * command's -- see the Visitor entity on why the visitor copies hold
     * literals for all five.
     */
    /**
     * "Never processed", as opposed to "processed and found nothing".
     *
     * All five NULL rather than just the source, because the two differ and the
     * difference is visible: `medium` comes straight off owa_session while the
     * other four resolve through dimension tables, so a visitor whose source_id
     * points at a row that no longer exists ends up with a medium and no source.
     * Keying on the source alone would hand that row back on every future run
     * for ever.
     *
     * The rows this still re-visits are the ones where every lookup came back
     * empty. They are re-walked rather than marked, because marking them would
     * mean storing a sentinel -- and a stored "(not set)" is exactly what
     * Update031 spent a migration removing.
     */
    const UNPROCESSED = 'v.first_session_source IS NULL
                     AND v.first_session_medium IS NULL
                     AND v.first_session_campaign IS NULL
                     AND v.first_session_ad IS NULL
                     AND v.first_session_search_terms IS NULL';

    /**
     * The legacy absence sentinel, mapped to NULL on the way in.
     *
     * owa_source_dim and friends still hold "(not set)" in rows written before
     * Update031, which deliberately cleared only the columns a registered
     * dimension resolves through. Copying the string into a brand new column
     * would reintroduce exactly what that update removed, and leave this column
     * holding two spellings of absence from its first day. NULL is the one the
     * reporting layer already renders as "(not set)".
     */
    const SENTINEL_SQL = "'(not set)'";

    const SOURCES = array(
        'first_session_source'       => array( 'owa_source_dim',      'source_id',                'source_domain' ),
        'first_session_campaign'     => array( 'owa_campaign_dim',    'campaign_id',              'name' ),
        'first_session_ad'           => array( 'owa_ad_dim',          'ad_id',                    'name' ),
        'first_session_search_terms' => array( 'owa_search_term_dim', 'referring_search_term_id', 'terms' ),
    );

    /**
     * Same capability as repair-geo-encoding, and for the same reason: this
     * rewrites stored analytics data in bulk. It is not a reporting action and
     * must not be reachable by anyone who can only read reports.
     */
    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_settings' );

        parent::__construct( $params );
    }

    function action() {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $dry_run = (bool) $this->getParam( 'dry-run' );
        $batch   = (int) $this->getParam( 'batch' );
        $batch   = $batch > 0 ? $batch : self::BATCH;

        $remaining = $db->get_row(
            'SELECT COUNT(*) AS n FROM owa_visitor v
               JOIN owa_session s ON s.id = v.first_session_id
              WHERE ' . self::UNPROCESSED );

        if ( $remaining === false ) {

            \OWA\Core\CoreAPI::notice(
                'Could not read owa_visitor. Has cmd=update been run to apply Update032?' );

            return;
        }

        $remaining = (int) $remaining['n'];

        if ( ! $remaining ) {

            \OWA\Core\CoreAPI::notice( 'Nothing to backfill: every visitor with a first session already has acquisition.' );

            return;
        }

        \OWA\Core\CoreAPI::notice( sprintf(
            '%d visitor(s) without acquisition, in batches of %d.%s',
            $remaining, $batch, $dry_run ? ' DRY RUN -- nothing will be written.' : '' ) );

        if ( $dry_run ) {

            return;
        }

        $done = 0;

        /*
         * BELOW EVERY POSSIBLE ID, NOT ZERO.
         *
         * Visitor ids are signed BIGINTs and a large number of them are
         * NEGATIVE: the ids minted before the 32-bit-to-64-bit rekey were
         * crc32 values cast into a signed column, so roughly half of that era's
         * visitors have an id below zero. Starting the walk at 0 skipped every
         * one of them silently -- 12,941 visitors on demo and 2,183 on
         * peteradamsphoto, which is exactly the shortfall the first production
         * run left behind and reported as outstanding work on every rerun.
         *
         * There is no id at PHP_INT_MIN, so a strict `>` loses nothing.
         */
        $cursor = PHP_INT_MIN;

        /*
         * Walked by id rather than by re-querying the predicate, and that is not
         * a style choice.
         *
         * A visitor whose session survives but whose dimension rows do not
         * resolves to NULL for every column -- the LEFT JOINs see nothing -- so
         * writing NULL over NULL leaves it still matching
         * "first_session_source IS NULL". A predicate-driven loop would hand
         * that same row back on every pass and never terminate. The cursor
         * always advances, so the walk ends whatever the data says.
         *
         * A rerun starts from the beginning again, but the predicate still skips
         * rows already filled, so it resumes in effect and only pays an indexed
         * scan to get there.
         */
        while ( true ) {

            $ids = $this->nextIds( $db, $cursor, $batch );

            if ( $ids === false ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'Stopped after %d row(s): could not read the next batch.', $done ) );

                return;
            }

            if ( ! $ids ) {

                break;
            }

            $cursor = (int) end( $ids );

            if ( $this->fillBatch( $db, $ids ) === false ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'Stopped after %d row(s): the update failed.', $done ) );

                return;
            }

            $done += count( $ids );

            \OWA\Core\CoreAPI::notice( sprintf( '  %d / %d', $done, $remaining ) );
        }

        \OWA\Core\CoreAPI::notice( sprintf(
            'Visited %d visitor(s). Any left empty had no first session row to read, or its '
          . 'dimension rows are gone -- the evidence no longer exists, and inventing a value '
          . 'from a later session would turn first-touch into some-touch.', $done ) );
    }

    /**
     * The next batch of visitor ids still missing acquisition.
     *
     * @param object $db
     * @param int    $cursor  last id already handled
     * @param int    $batch
     * @return array|false ascending ids, or false on error
     */
    private function nextIds( $db, $cursor, $batch ) {

        $rows = $db->get_results( sprintf(
            'SELECT v.id FROM owa_visitor v
               JOIN owa_session s ON s.id = v.first_session_id
              WHERE %s
                AND v.id > %d
              ORDER BY v.id
              LIMIT %d', self::UNPROCESSED, (int) $cursor, (int) $batch ) );

        if ( $rows === false ) {

            return false;
        }

        $ids = array();

        foreach ( (array) $rows as $row ) {

            $ids[] = (int) ( is_array( $row ) ? $row['id'] : $row->id );
        }

        return $ids;
    }

    /**
     * Copy acquisition onto one batch of visitors from their first session.
     *
     * LEFT JOIN to each dimension, so a dimension row that no longer exists
     * yields NULL for that column rather than dropping the visitor from the
     * batch. The inner JOIN to owa_session is deliberate by contrast: with no
     * session there is nothing to read and the row is correctly left alone.
     *
     * @param object $db
     * @param array  $ids
     * @return bool|false false on error
     */
    private function fillBatch( $db, array $ids ) {

        // Every selected value is aliased to the column it lands in. Two of the
        // dimension tables call their value column `name`, so selecting them
        // bare would put two columns called `name` in the derived table and the
        // SET clause would reference whichever the optimiser felt like.
        $selects = array( sprintf(
            "NULLIF(NULLIF(s.medium, %s), '') AS first_session_medium", self::SENTINEL_SQL ) );
        $sets    = array( 'v.first_session_medium = src.first_session_medium' );
        $joins   = '';

        foreach ( self::SOURCES as $column => $from ) {

            list( $table, $key, $value ) = $from;

            $alias     = 'd_' . $column;
            $selects[] = sprintf( "NULLIF(NULLIF(%s.%s, %s), '') AS %s",
                $alias, $value, self::SENTINEL_SQL, $column );
            $sets[]    = sprintf( 'v.%s = src.%s', $column, $column );
            $joins    .= sprintf( ' LEFT JOIN %s %s ON %s.id = s.%s', $table, $alias, $alias, $key );
        }

        $sql = sprintf(
            'UPDATE owa_visitor v
               JOIN (
                 SELECT v2.id AS vid, %s
                   FROM owa_visitor v2
                   JOIN owa_session s ON s.id = v2.first_session_id
                   %s
                  WHERE v2.id IN (%s)
               ) src ON src.vid = v.id
                SET %s',
            implode( ', ', $selects ), $joins,
            implode( ',', array_map( 'intval', $ids ) ), implode( ', ', $sets ) );

        return $db->query( $sql ) !== false;
    }
}

?>
