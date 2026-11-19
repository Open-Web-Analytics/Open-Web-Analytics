<?php
namespace OWA\Module\Base\Classes\Spam;

/**
 * Counting blocked beacons into owa_spam_block, and reading the counts back.
 *
 * A blocked beacon adds one to its day's and Profile's `*` row and one to the
 * row of each rule it met (Entity\SpamBlock).
 *
 * WRITTEN AS IT HAPPENS, OR ONCE PER DRAIN. In the request, each blocked beacon
 * writes its keys as it is blocked, in one statement. A drain of the
 * tracker-ingest queue may block hundreds of beacons from one attack in a
 * minute, so it opens a batch (begin()), the counts are totalled in memory,
 * and flush() writes them at the end, one statement per distinct count. A
 * drain that dies before flush() loses its counts and not its beacons: the
 * counts are a report, and the beacons were blocked either way.
 *
 * An insert-or-add of several rows (OWA_SQL_INSERT_OR_ADD, the dialect's
 * upsert): two writers adding to one row both count. The rows of a statement
 * are in key order, so two statements meeting on the same rows lock them in
 * the same order.
 */
class Blocks {

    /** @var string|null the table to use instead of owa_spam_block. TESTS ONLY. */
    public static $table = null;

    /** @var int statements this process has sent, so a test can tell one per key from one per beacon */
    public static $writes = 0;

    /** @var array<string, int>|null "yyyymmdd|site|rule" => count while a batch is open, else null */
    private static $batch = null;

    /**
     * Total the counts in memory until flush().
     *
     * @return void
     */
    public static function begin() {

        if ( self::$batch === null ) {

            self::$batch = [];
        }
    }

    /**
     * Whether a batch is open.
     *
     * @return bool
     */
    public static function batching() {

        return self::$batch !== null;
    }

    /**
     * The counts an open batch holds: "yyyymmdd|site|rule" => count.
     *
     * @return array<string, int>
     */
    public static function pending() {

        return (array) self::$batch;
    }

    /**
     * Write an open batch, one statement per distinct count, and close it.
     *
     * @return bool false when a write failed; the rest are still written
     */
    public static function flush() {

        $batch       = (array) self::$batch;
        self::$batch = null;
        $ok          = true;
        $by_count    = [];

        foreach ( $batch as $key => $count ) {

            list( $yyyymmdd, $site_id, $rule ) = explode( '|', (string) $key, 3 );

            $by_count[ (int) $count ][] = [ (int) $yyyymmdd, $site_id, $rule ];
        }

        foreach ( $by_count as $count => $keys ) {

            $ok = self::write( $keys, $count ) && $ok;
        }

        return $ok;
    }

    /**
     * Count one blocked beacon.
     *
     * @param int      $yyyymmdd
     * @param string   $site_id
     * @param string[] $rules  the rules it met
     * @return void
     */
    public static function record( $yyyymmdd, $site_id, array $rules ) {

        $rules = array_unique( array_merge( [ \OWA\Module\Base\Entity\SpamBlock::TOTAL ],
            array_map( 'strval', $rules ) ) );

        if ( self::$batch !== null ) {

            foreach ( $rules as $rule ) {

                $key = (int) $yyyymmdd . '|' . (string) $site_id . '|' . $rule;

                self::$batch[ $key ] = ( self::$batch[ $key ] ?? 0 ) + 1;
            }

            return;
        }

        $keys = [];

        foreach ( $rules as $rule ) {

            $keys[] = [ (int) $yyyymmdd, (string) $site_id, $rule ];
        }

        self::write( $keys, 1 );
    }

    /**
     * Blocked beacons on and since a day: rule => count, `*` first, then the
     * rules by count, highest first.
     *
     * @param  int $since yyyymmdd
     * @return array<string, int>
     */
    public static function since( $since ) {

        $rows = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            'SELECT rule, SUM(blocked) AS blocked FROM %s WHERE yyyymmdd >= ? GROUP BY rule', self::table() ),
            [ (int) $since ] );

        $out = [];

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

        return $all ? [ $total => $all ] + $out : $out;
    }

    /**
     * Add $count to each key's row, in one statement.
     *
     * @param  list<array{int, string, string}> $keys yyyymmdd, site_id, rule
     * @param  int $count
     * @return bool false when the write failed
     */
    private static function write( array $keys, $count ) {

        if ( ! $keys ) {

            return true;
        }

        self::$writes++;

        sort( $keys );

        // The driver first: loading it is what defines the OWA_SQL_* constants.
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $params = [];

        foreach ( $keys as $key ) {

            array_push( $params, $key[0], $key[1], $key[2], (int) $count );
        }

        $params[] = (int) $count;

        // Several rows' placeholders, which the template's VALUES ( ) encloses.
        $ok = $db->query( sprintf( OWA_SQL_INSERT_OR_ADD, self::table(),
                'yyyymmdd, site_id, rule, blocked', implode( '), (', array_fill( 0, count( $keys ), '?, ?, ?, ?' ) ),
                'yyyymmdd, site_id, rule', 'blocked' ),
            $params ) !== false;

        if ( ! $ok ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Spam detection: counting %d blocked %s for %s failed.',
                $count, \OWA\Core\Lib::plural( $count, 'beacon', 'beacons' ),
                implode( ', ', array_map( fn ( $key ) => $key[2] . ' on ' . $key[0], $keys ) ) ) );
        }

        return $ok;
    }

    /** @return string */
    private static function table() {

        return self::$table ?: \OWA\Core\CoreAPI::entityFactory( 'base.spam_block' )->getTableName();
    }
}
