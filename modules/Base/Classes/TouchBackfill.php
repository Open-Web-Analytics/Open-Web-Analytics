<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The last-touch evidence for history recorded before ingest wrote it
 * (Update066, PLAN 2.29): raw's prior_touch_* and the visitor store's
 * last_touch_*, worked out from the landings raw already holds.
 *
 * That history is v1's, migrated in by Update062 before these columns existed,
 * and any v2 traffic from before this release. Ingest does the same thing one
 * beacon at a time; this does it per site, in set-based statements over one
 * range of visitors at a time.
 *
 * BATCHED BY VISITOR, because the database is shared: as one statement per
 * site it was a 12-minute UPDATE over a million rows of a real 1.x install's
 * history, holding its locks throughout. Every step is per visitor -- the
 * window is partitioned by visitor, the store keyed by one -- so a range of
 * visitors gives exactly the values the whole site would.
 *
 * A LANDING is a session_start row: the marker raised from the beacon that
 * began the session, carrying its tags and referring host. It is NON-DIRECT
 * when any of those holds something -- the same test the cube applies.
 *
 *   1. Each landing gets the visitor's last non-direct landing BEFORE it as
 *      prior_touch_*, on every raw row of that landing beacon (same session,
 *      same ts), as ingest stamps them.
 *   2. Each visitor's latest non-direct landing becomes their last_touch_*,
 *      guarded on last_touch_ts so a newer touch ingest already wrote stays.
 *
 * Idempotent: a second run computes the same values and writes them again.
 */
class TouchBackfill {

    /** The evidence columns, in raw's names. */
    const EVIDENCE = array( 'source', 'medium', 'campaign', 'ad' );

    /** Visitors per statement. */
    const VISITORS_PER_BATCH = 2000;

    /** @var \OWA\Core\Db */
    private $db;

    /** @var string */
    private $raw;

    /** @var string */
    private $store;

    /** @var int visitors per statement */
    private $per;

    function __construct( $per = self::VISITORS_PER_BATCH ) {

        $this->per   = max( 1, (int) $per );

        $this->db    = \OWA\Core\CoreAPI::dbSingleton();
        $this->raw   = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
        $this->store = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' )->getTableName();
    }

    /** @return string[] the sites raw holds landings for */
    public function sites() {

        $out = array();

        foreach ( (array) $this->db->get_results( sprintf(
                "SELECT DISTINCT site_id FROM %s WHERE event_type = 'session_start'", $this->raw ) ) as $row ) {

            $out[] = (string) $row['site_id'];
        }

        return $out;
    }

    /**
     * @param string $site_id
     * @return bool
     */
    public function site( $site_id ) {

        foreach ( $this->ranges( $site_id, $this->per ) as list( $low, $high ) ) {

            if ( ! $this->stamp( $site_id, $low, $high ) || ! $this->store( $site_id, $low, $high ) ) {

                return false;
            }
        }

        return true;
    }

    /**
     * The site's landing visitors, cut into ranges of VISITORS_PER_BATCH.
     *
     * Read once: asking for each next range would scan the landings again.
     *
     * @return array[] [ low, high ] visitor ids, inclusive
     */
    public function ranges( $site_id, $per = self::VISITORS_PER_BATCH ) {

        $ids = array();

        foreach ( (array) $this->db->get_results( sprintf(
                "SELECT DISTINCT visitor_id FROM %s WHERE site_id = ? AND event_type = 'session_start'"
              . ' ORDER BY visitor_id', $this->raw ), array( (string) $site_id ) ) as $row ) {

            $ids[] = (int) $row['visitor_id'];
        }

        $ranges = array();

        foreach ( array_chunk( $ids, max( 1, (int) $per ) ) as $chunk ) {

            $ranges[] = array( $chunk[0], end( $chunk ) );
        }

        return $ranges;
    }

    /** SQL that is true when the landing aliased $a arrived non-direct. */
    private static function nonDirect( $a ) {

        $tests = array();

        foreach ( array( 'tagged_source', 'tagged_medium', 'tagged_campaign', 'tagged_ad', 'referer_host' ) as $c ) {

            $tests[] = sprintf( "NULLIF(TRIM(%s.%s), '') IS NOT NULL", $a, $c );
        }

        return '(' . implode( ' OR ', $tests ) . ')';
    }

    /** Step 1: every landing's prior touch, onto its beacon's rows. */
    private function stamp( $site_id, $low, $high ) {

        $set = array();

        foreach ( self::EVIDENCE as $part ) {

            $set[] = sprintf( 'r.prior_touch_%1$s = p.tagged_%1$s', $part );
        }

        $set[] = 'r.prior_touch_referer_host = p.referer_host';
        $set[] = 'r.prior_touch_ts = p.ts';

        /*
         * The visitor's latest non-direct landing strictly before each landing:
         * a running MAX over the rows before it, which MySQL's window frame
         * supports where LAST_VALUE ... IGNORE NULLS does not.
         */
        $sql = sprintf(
            'UPDATE %1$s r JOIN ('
          . '  SELECT l.visitor_id, l.session_id, l.ts,'
          . '    MAX(CASE WHEN %3$s THEN l.ts END) OVER ('
          . '      PARTITION BY l.visitor_id ORDER BY l.ts, l.session_id'
          . '      ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING) AS prior_ts'
          . "  FROM %1\$s l WHERE l.event_type = 'session_start' AND l.site_id = ? AND l.visitor_id BETWEEN %4\$d AND %5\$d"
          . ') w ON r.site_id = ? AND r.visitor_id = w.visitor_id AND r.session_id = w.session_id AND r.ts = w.ts'
          . " JOIN %1\$s p ON p.event_type = 'session_start' AND p.visitor_id = w.visitor_id AND p.ts = w.prior_ts"
          . ' SET %2$s WHERE r.visitor_id BETWEEN %4$d AND %5$d',
            $this->raw, implode( ', ', $set ), self::nonDirect( 'l' ), (int) $low, (int) $high );

        return $this->db->query( $sql, array( (string) $site_id, (string) $site_id ) ) !== false;
    }

    /** Step 2: each visitor's latest non-direct landing, onto the visitor store. */
    private function store( $site_id, $low, $high ) {

        $latest = sprintf(
            'SELECT l.visitor_id, l.site_id, l.tagged_source, l.tagged_medium, l.tagged_campaign, l.tagged_ad,'
          . '  l.referer_host, l.ts, l.yyyymmdd FROM %1$s l JOIN ('
          . "    SELECT visitor_id, MAX(ts) AS ts FROM %1\$s n WHERE n.event_type = 'session_start'"
          . '      AND n.site_id = ? AND n.visitor_id BETWEEN %3$d AND %4$d AND %2$s GROUP BY visitor_id'
          . "  ) m ON l.visitor_id = m.visitor_id AND l.ts = m.ts AND l.event_type = 'session_start' AND l.site_id = ?",
            $this->raw, self::nonDirect( 'n' ), (int) $low, (int) $high );

        $set = array();

        foreach ( self::EVIDENCE as $part ) {

            $set[] = sprintf( 'v.last_touch_%1$s = t.tagged_%1$s', $part );
        }

        $updated = $this->db->query( sprintf(
            'UPDATE %s v JOIN (%s) t ON v.visitor_id = t.visitor_id SET %s, v.last_touch_referer_host = t.referer_host,'
          . ' v.last_touch_ts = t.ts WHERE v.last_touch_ts IS NULL OR v.last_touch_ts < t.ts',
            $this->store, $latest, implode( ', ', $set ) ),
            array( (string) $site_id, (string) $site_id ) );

        if ( $updated === false ) {

            return false;
        }

        // A visitor the store has no row for: their touch is the only thing to record.
        return $this->db->query( sprintf(
            'INSERT INTO %s (visitor_id, site_id, last_touch_source, last_touch_medium, last_touch_campaign,'
          . ' last_touch_ad, last_touch_referer_host, last_touch_ts, last_seen)'
          . ' SELECT t.visitor_id, t.site_id, t.tagged_source, t.tagged_medium, t.tagged_campaign, t.tagged_ad,'
          . ' t.referer_host, t.ts, FLOOR(t.yyyymmdd / 100) FROM (%s) t'
          . ' WHERE NOT EXISTS (SELECT 1 FROM %s v WHERE v.visitor_id = t.visitor_id)',
            $this->store, $latest, $this->store ),
            array( (string) $site_id, (string) $site_id ) ) !== false;
    }
}

?>
