<?php

namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Deletes visitor-store rows that no retained raw event refers to.
 *
 * THE STORE LIVES AS LONG AS RAW. A build stamps each event's first
 * attribution from the store at build time (Cube\Builder joins it; raw carries
 * no acquisition), so a row deleted while raw still holds one of its visitor's
 * events turns that visitor's rows into the sentinel on the next rebuild of
 * that partition -- a first build of a new cube, or a reprocess over old
 * history. A row can go only once no rebuild can read one of its events.
 *
 * So there is no window of its own. partition-rotate calls this after it drops
 * raw partitions under keep=; without keep= nothing is dropped from raw, and
 * nothing is deleted here either.
 *
 * The table is not partitioned (Entity\VisitorAcquisition says why), so this
 * is a DELETE in batches, not a partition drop.
 *
 * TWO CONDITIONS, and the second is the rule:
 *
 *   - last_seen before the month of the oldest day raw still holds. A cheap
 *     indexed filter that leaves almost nothing to check on a normal day. It
 *     is not enough on its own: ingest sets last_seen only when it creates the
 *     row and a build advances it later, so a visitor returning after a long
 *     gap has a stale last_seen until the next build.
 *   - no event for the visitor in raw, probed through raw's visitor_id index.
 *
 * AND NEVER WITHIN A COOKIE'S REACH. A visitor with no event left in raw can
 * still return carrying their cookie -- Chrome keeps a first-party cookie up to
 * 400 days -- and with the row gone no first_visit is raised, so every new
 * event gets the sentinel. Under keep= of 14 months or more raw outlives the
 * cookie and this changes nothing; under a shorter keep= it holds rows for
 * visitors seen in the last 14 months (PLAN A.1.22 has the lifespans).
 *
 * UNDATED ROWS ARE KEPT. last_seen 0 or NULL says nothing about age.
 */
class VisitorExpiry {

    /**
     * How long a visitor can come back with their cookie: just over Chrome's
     * 400-day cap on a first-party cookie.
     */
    const COOKIE_MONTHS = 14;

    /**
     * Rows per DELETE. Small, because the store shares its database server
     * with everything else on the installation and this is never urgent.
     */
    const BATCH = 5000;

    /** @return string */
    public static function table() {

        return \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' )->getTableName();
    }

    /** @return string */
    public static function rawTable() {

        return \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
    }

    /**
     * The month of the oldest day raw still holds, as yyyymm, or null when raw
     * is empty.
     *
     * Read from the data, not from keep=: a partition is dropped only when it
     * lies wholly before the cutoff, and old periods are merged into blocks of
     * up to five years, so a kept block can hold days long before it.
     *
     * Per site through site_date (site_id, yyyymmdd), which the server answers
     * with a loose index scan -- one probe per site per partition. A plain
     * MIN(yyyymmdd) has no index leading with the day and reads all of one.
     *
     * @return int|null
     */
    public static function oldestRawMonth() {

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf(
            'SELECT MIN(m) AS m FROM (SELECT site_id, MIN(yyyymmdd) AS m FROM %s GROUP BY site_id) s',
            self::rawTable() ) );

        return empty( $row['m'] ) ? null : intdiv( (int) $row['m'], 100 );
    }

    /**
     * The month a row must have been last seen before to go, as yyyymm: the
     * earlier of raw's oldest month and COOKIE_MONTHS before $now. Null when
     * raw is empty, and nothing goes.
     *
     * @param int|null           $oldest_raw_month yyyymm, from oldestRawMonth()
     * @param \DateTimeInterface $now              in the installation's timezone
     * @return int|null
     */
    public static function cutoff( $oldest_raw_month, \DateTimeInterface $now ) {

        if ( $oldest_raw_month === null ) {

            return null;
        }

        $period = (int) $now->format( 'Y' ) * 12 + (int) $now->format( 'n' ) - 1 - self::COOKIE_MONTHS;
        $floor  = intdiv( $period, 12 ) * 100 + ( $period % 12 ) + 1;

        return min( (int) $oldest_raw_month, $floor );
    }

    /**
     * Now, in the timezone yyyymmdd and last_seen are written in.
     *
     * @return \DateTimeImmutable
     */
    public static function now() {

        return new \DateTimeImmutable( 'now', new \DateTimeZone( JobStatus::timezone() ) );
    }

    /**
     * The rows that may go, as a WHERE clause over the store aliased `v`.
     *
     * @param int $cutoff yyyymm: rows last seen before it are candidates
     * @return string
     */
    protected static function expiredWhere( $cutoff ) {

        return sprintf(
            'v.last_seen > 0 AND v.last_seen < %d '
          . 'AND NOT EXISTS (SELECT 1 FROM %s r WHERE r.visitor_id = v.visitor_id)',
            (int) $cutoff, self::rawTable() );
    }

    /**
     * How many rows would go.
     *
     * @param int $cutoff yyyymm
     * @return int
     */
    public static function countExpired( $cutoff ) {

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf(
            'SELECT COUNT(*) AS n FROM %s v WHERE %s', self::table(), self::expiredWhere( $cutoff ) ) );

        return (int) ( $row['n'] ?? 0 );
    }

    /**
     * Delete one batch.
     *
     * The ids are read first and the DELETE names them, so it removes a known
     * set: a DELETE ... LIMIT without an ORDER BY is non-deterministic, which
     * statement-based replication refuses to trust. The DELETE repeats the
     * conditions, so a visitor whose event reached raw between the two
     * statements keeps the row.
     *
     * @param int $cutoff yyyymm
     * @param int $batch
     * @return int|false rows deleted, or false when the server refused
     */
    public static function deleteBatch( $cutoff, $batch = self::BATCH ) {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $ids = array();

        foreach ( (array) $db->get_results( sprintf(
                'SELECT v.id FROM %s v WHERE %s LIMIT %d',
                self::table(), self::expiredWhere( $cutoff ), max( 1, (int) $batch ) ) ) as $row ) {

            $ids[] = (int) $row['id'];
        }

        if ( ! $ids ) {

            return 0;
        }

        if ( $db->query( sprintf( 'DELETE v FROM %s v WHERE v.id IN (%s) AND %s',
                self::table(), implode( ',', $ids ), self::expiredWhere( $cutoff ) ) ) === false ) {

            return false;
        }

        return (int) $db->getAffectedRows();
    }

    /**
     * Delete every row that may go, a batch at a time.
     *
     * @param int           $cutoff    yyyymm
     * @param callable|null $heartbeat called between batches
     * @param int           $batch
     * @return int|false rows deleted, or false when the server refused
     */
    public static function deleteAll( $cutoff, $heartbeat = null, $batch = self::BATCH ) {

        $deleted = 0;

        do {

            $n = self::deleteBatch( $cutoff, $batch );

            if ( $n === false ) {

                return false;
            }

            $deleted += $n;

            if ( $heartbeat ) {

                $heartbeat();
            }

        } while ( $n > 0 );

        return $deleted;
    }
}

?>
