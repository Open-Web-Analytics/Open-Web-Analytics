<?php
namespace OWA\Module\Base\Classes\Spam;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Counting blocked beacons into owa_spam_block, and reading the counts back.
 *
 * A blocked beacon adds one to its day's and Profile's `*` row and one to the
 * row of each rule it met (Entity\SpamBlock).
 *
 * WRITTEN AS IT HAPPENS, OR ONCE PER DRAIN. In the request, each blocked beacon
 * writes its keys as it is blocked. A drain of the tracker-ingest queue may
 * block hundreds of beacons from one attack in a minute, so it opens a batch
 * (begin()), the counts are totalled in memory, and flush() writes each key
 * once at the end. A drain that dies before flush() loses its counts and not
 * its beacons: the counts are a report, and the beacons were blocked either way.
 *
 * An insert-or-add (OWA_SQL_INSERT_OR_ADD, the dialect's upsert): two writers
 * adding to one row both count.
 */
class Blocks {

    /** @var string|null the table to use instead of owa_spam_block. TESTS ONLY. */
    public static $table = null;

    /** @var int statements this process has sent, so a test can tell one per key from one per beacon */
    public static $writes = 0;

    /** @var array|null "yyyymmdd|site|rule" => count while a batch is open, else null */
    private static $batch = null;

    /** Total the counts in memory until flush(). */
    public static function begin() {

        if ( self::$batch === null ) {

            self::$batch = array();
        }
    }

    /** Whether a batch is open. */
    public static function batching() {

        return self::$batch !== null;
    }

    /** The counts an open batch holds: "yyyymmdd|site|rule" => count. */
    public static function pending() {

        return (array) self::$batch;
    }

    /**
     * Write an open batch, one statement per key, and close it.
     *
     * @return bool false when a write failed; the rest are still written
     */
    public static function flush() {

        $batch       = (array) self::$batch;
        self::$batch = null;
        $ok          = true;

        foreach ( $batch as $key => $count ) {

            list( $yyyymmdd, $site_id, $rule ) = explode( '|', $key, 3 );

            $ok = self::write( (int) $yyyymmdd, $site_id, $rule, (int) $count ) && $ok;
        }

        return $ok;
    }

    /**
     * Count one blocked beacon.
     *
     * @param int      $yyyymmdd
     * @param string   $site_id
     * @param string[] $rules  the rules it met
     */
    public static function record( $yyyymmdd, $site_id, array $rules ) {

        $keys = array_unique( array_merge( array( \OWA\Module\Base\Entity\SpamBlock::TOTAL ),
            array_map( 'strval', $rules ) ) );

        foreach ( $keys as $rule ) {

            if ( self::$batch !== null ) {

                $key = (int) $yyyymmdd . '|' . (string) $site_id . '|' . $rule;

                self::$batch[ $key ] = ( self::$batch[ $key ] ?? 0 ) + 1;

                continue;
            }

            self::write( (int) $yyyymmdd, (string) $site_id, $rule, 1 );
        }
    }

    /**
     * Blocked beacons on and since a day: rule => count, `*` first, then the
     * rules by count, highest first.
     *
     * @param  int $since yyyymmdd
     * @return array
     */
    public static function since( $since ) {

        $rows = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            'SELECT rule, SUM(blocked) AS blocked FROM %s WHERE yyyymmdd >= ? GROUP BY rule', self::table() ),
            array( (int) $since ) );

        $out = array();

        foreach ( $rows as $row ) {

            $row = (array) $row;

            if ( (int) $row['blocked'] > 0 ) {

                $out[ (string) $row['rule'] ] = (int) $row['blocked'];
            }
        }

        $total = \OWA\Module\Base\Entity\SpamBlock::TOTAL;
        $all   = $out[ $total ] ?? 0;
        unset( $out[ $total ] );

        uksort( $out, function ( $a, $b ) use ( $out ) {

            return $out[ $b ] <=> $out[ $a ] ?: strcmp( (string) $a, (string) $b );
        } );

        return $all ? array( $total => $all ) + $out : $out;
    }

    private static function write( $yyyymmdd, $site_id, $rule, $count ) {

        self::$writes++;

        // The driver first: loading it is what defines the OWA_SQL_* constants.
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $ok = $db->query( sprintf( OWA_SQL_INSERT_OR_ADD, self::table(),
                'yyyymmdd, site_id, rule, blocked', '?, ?, ?, ?', 'yyyymmdd, site_id, rule', 'blocked' ),
            array( $yyyymmdd, $site_id, $rule, $count, $count ) ) !== false;

        if ( ! $ok ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Spam detection: counting %d blocked %s for %s on %d failed.',
                $count, \OWA\Core\Lib::plural( $count, 'beacon', 'beacons' ), $rule, $yyyymmdd ) );
        }

        return $ok;
    }

    private static function table() {

        return self::$table ?: \OWA\Core\CoreAPI::entityFactory( 'base.spam_block' )->getTableName();
    }
}

?>
