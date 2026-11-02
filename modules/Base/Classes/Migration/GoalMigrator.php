<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * v1's recorded goal completions onto the migrated rows (PLAN.html 2.21).
 *
 * v1 recorded a completion per session in a numbered slot (owa_session.goal_N);
 * v2 counts a conversion row, named by the goal event and flagged
 * is_goal_event = 1 (Classes\GoalMarking). For each session v1 says completed
 * goal N, the first migrated row of that session that meets the goal event
 * Update025 made of slot N -- its condition compiled exactly as the funnel
 * compiles it -- gets one.
 *
 * v1's record decides WHICH sessions converted; the definition only finds the
 * row. So nothing is marked that v1 did not count, which is what keeps this
 * from being retroactive conversion. A completion the definition cannot
 * locate -- the goal was deleted, or its condition changed -- is counted and
 * reported rather than guessed.
 *
 * Runs after the page views: the conversion is copied from one of their rows.
 */
class GoalMigrator extends FactMigrator {

    const SOURCE = 'session';

    /** 1.x kept this many slots per Profile. */
    const SLOTS = 15;

    const NOT_LOCATED = 'goal_not_located';

    /** @var array goal event id => compiled predicate plus 'name', or false */
    private $compiled = array();

    /** @var array site_id => property id */
    private $properties = array();

    protected static function progressKey() {

        return 'session_goals';
    }

    protected function refusal( array $r ) {

        return null;
    }

    protected function events( array $r, array $refs ) {

        return array();
    }

    /** This pass writes no raw rows of its own, so there is nothing to count. */
    public function reconcileSite( $site_id ) {

        return null;
    }

    protected function apply( array $rows, array &$progress ) {

        $raw    = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
        $marked = 0;

        foreach ( $rows as $r ) {

            $progress['rows_read']++;

            for ( $n = 1; $n <= self::SLOTS; $n++ ) {

                if ( empty( $r[ 'goal_' . $n ] ) ) {

                    continue;
                }

                $predicate = $this->predicate( (string) $r['site_id'], $n );

                if ( ! $predicate ) {

                    $this->notLocated( $progress );

                    continue;
                }

                // The session's own day and the next: a visit can cross midnight.
                $day  = (int) $r['yyyymmdd'];
                $next = (int) date( 'Ymd', strtotime( (string) $day . ' +1 day' ) );

                $trigger = (array) $this->db()->get_row( sprintf(
                    'SELECT e.id, e.yyyymmdd, e.site_id, e.visitor_id, e.session_id, e.ts FROM %s e'
                    . ' WHERE e.site_id = ? AND e.visitor_id = ? AND e.session_id = ?'
                    . ' AND e.yyyymmdd BETWEEN ? AND ? AND e.is_goal_event = 0 AND %s ORDER BY e.ts, e.id LIMIT 1',
                    $raw, $predicate['sql'] ),
                    array_merge( array( (string) $r['site_id'], (string) $r['visitor_id'], (string) $r['id'], $day, $next ),
                        $predicate['params'] ) );

                if ( empty( $trigger['id'] ) ) {

                    $this->notLocated( $progress );

                    continue;
                }

                // Derived id and ON DUPLICATE KEY: a second run stores nothing new.
                if ( ! self::storeConversion( $trigger, $predicate['name'] ) ) {

                    return false;
                }

                if ( (int) $this->db()->getAffectedRows() === 1 ) {

                    $marked++;
                }
            }
        }

        return $marked;
    }

    private function notLocated( array &$progress ) {

        $progress['rows_refused']++;
        $progress['refusals'][ self::NOT_LOCATED ] = ( $progress['refusals'][ self::NOT_LOCATED ] ?? 0 ) + 1;
    }

    /**
     * Store the conversion row for a row already in raw, unless it is there.
     *
     * The SQL form of GoalMarking::conversion(), for what is converted after
     * ingest: this migration, and Update075, which turned the events marked
     * before conversion rows existed into them. It copies the columns
     * conversion() would -- the ones materializedProperties() names -- under
     * the id ingest would have derived for it. MySQL's upsert, like the rest of
     * the migration: only an installation upgrading from 1.x, which is MySQL,
     * runs either.
     *
     * created_at is now, so the cube rebuilds the day (Cube\Builder::isCurrent()).
     *
     * @param  array  $trigger  the stored row's id, yyyymmdd, site_id, visitor_id,
     *                          session_id and ts
     * @param  string $name     the goal event's event_name
     * @return bool   false when the write failed
     */
    public static function storeConversion( array $trigger, $name ) {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        $skip    = array( 'id', 'event_type', 'is_goal_event', 'created_at', 'params' );
        $columns = array();

        foreach ( \OWA\Module\Base\Classes\TrackingEventHelpers::materializedProperties( $name ) as $property ) {

            $column = \OWA\Module\Base\Classes\TrackingEventHelpers::columnFor( $property );

            if ( $column !== '' && ! in_array( $column, $skip, true ) && in_array( $column, $raw->getColumns(), true ) ) {

                $columns[ $column ] = $column;
            }
        }

        $id = \OWA\Module\Base\Classes\V2Event::id( $trigger['site_id'], $trigger['visitor_id'], $trigger['session_id'], $trigger['ts'], $name );

        // Qualified throughout: the SELECT reads the table the INSERT writes, so a
        // bare `id` in the duplicate-key clause names both.
        return $db->query( sprintf(
            'INSERT INTO %1$s (id, event_type, is_goal_event, created_at, %2$s)'
          . ' SELECT ?, ?, 1, ?, %3$s FROM %1$s t WHERE t.id = ? AND t.yyyymmdd = ?'
          . ' ON DUPLICATE KEY UPDATE %1$s.id = %1$s.id',
            $raw->getTableName(), implode( ', ', $columns ),
            implode( ', ', array_map( function ( $c ) { return 't.' . $c; }, $columns ) ) ),
            array( $id, (string) $name, (int) round( microtime( true ) * 1000000 ),
                $trigger['id'], (int) $trigger['yyyymmdd'] ) ) !== false;
    }

    /** The Property a site belongs to. */
    public static function propertyFor( $site_id ) {

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->getByColumn( 'site_id', $site_id );

        return $site->get( 'property_id' );
    }

    /**
     * The goal event 1.13's Update025 made of a Property's goal slot, or null.
     *
     * Read by property and slot, NOT by re-deriving the id Update025 gave it.
     * An installation that ran it while still on 32-bit ids holds a 32-bit id
     * there, and a derivation now is 64-bit: it would find nothing.
     *
     * @return string|null
     */
    public static function goalEventFor( $property_id, $goal_number ) {

        $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf(
            'SELECT id FROM %s WHERE property_id = ? AND goal_number = ? ORDER BY id LIMIT 1',
            $goal->getTableName() ), array( (string) $property_id, (int) $goal_number ) );

        return isset( $row['id'] ) ? (string) $row['id'] : null;
    }

    /** Slot N of a site's goals, as Update025 migrated it, compiled; or null. */
    private function predicate( $site_id, $n ) {

        if ( ! array_key_exists( $site_id, $this->properties ) ) {

            $this->properties[ $site_id ] = self::propertyFor( $site_id );
        }

        $id = self::goalEventFor( $this->properties[ $site_id ], $n );

        if ( $id === null ) {

            return null;
        }

        if ( ! array_key_exists( $id, $this->compiled ) ) {

            $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );

            $goal->load( $id );

            $compiled = $goal->wasPersisted()
                ? ( new \OWA\Module\Base\Classes\GoalEventPredicate() )->compile( $goal, 'e' )
                : false;

            $this->compiled[ $id ] = $compiled ? $compiled + array( 'name' => $goal->eventName() ) : false;
        }

        return $this->compiled[ $id ] ?: null;
    }

    /**
     * Delete the conversion rows this pass stored for a site.
     *
     * The passes revert in reverse, so this runs before the page views' and
     * reads the same v1 sessions again: each completion's conversion is the
     * goal event's row in that session.
     *
     * @return int rows deleted
     */
    public function revertSite( $site_id ) {

        $progress = \OWA\Core\CoreAPI::entityFactory( 'base.migration_progress' );
        $progress->load( \OWA\Module\Base\Entity\MigrationProgress::idFor( static::progressKey(), $site_id ) );

        if ( ! $progress->wasPersisted() ) {

            return 0;
        }

        $since       = (int) $progress->get( 'since' );
        $this->since = $since > 0 ? $since : null;

        $raw     = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
        $deleted = 0;
        $after   = null;

        while ( $rows = $this->read( $site_id, $after ) ) {

            foreach ( $rows as $r ) {

                for ( $n = 1; $n <= self::SLOTS; $n++ ) {

                    $predicate = empty( $r[ 'goal_' . $n ] ) ? null : $this->predicate( (string) $r['site_id'], $n );

                    if ( ! $predicate ) {

                        continue;
                    }

                    $day  = (int) $r['yyyymmdd'];
                    $next = (int) date( 'Ymd', strtotime( (string) $day . ' +1 day' ) );

                    if ( $this->db()->query( sprintf(
                            'DELETE FROM %s WHERE site_id = ? AND visitor_id = ? AND session_id = ?'
                          . ' AND yyyymmdd BETWEEN ? AND ? AND event_type = ? AND is_goal_event = 1', $raw ),
                            array( (string) $r['site_id'], (string) $r['visitor_id'], (string) $r['id'],
                                $day, $next, $predicate['name'] ) ) === false ) {

                        throw new \RuntimeException( sprintf( 'v1 migration: reverting goal completions for site %s failed.', $site_id ) );
                    }

                    $deleted += (int) $this->db()->getAffectedRows();
                }
            }

            $last  = end( $rows );
            $after = (string) $last['id'];
        }

        $this->forget( $site_id );

        return $deleted;
    }
}

?>
