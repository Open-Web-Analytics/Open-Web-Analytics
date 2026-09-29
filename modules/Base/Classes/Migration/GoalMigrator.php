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
 * v2 counts a row with is_goal_event = 1. For each session v1 says completed
 * goal N, the first migrated row of that session that meets the goal event
 * Update025 made of slot N -- its condition compiled exactly as the funnel
 * compiles it -- is marked.
 *
 * v1's record decides WHICH sessions converted; the definition only finds the
 * row. So nothing is marked that v1 did not count, which is what keeps this
 * from being retroactive conversion. A completion the definition cannot
 * locate -- the goal was deleted, or its condition changed -- is counted and
 * reported rather than guessed.
 *
 * Runs after the page views: it marks rows, it does not write them.
 */
class GoalMigrator extends FactMigrator {

    const SOURCE = 'session';

    /** 1.x kept this many slots per Profile. */
    const SLOTS = 15;

    const NOT_LOCATED = 'goal_not_located';

    /** @var array goal event id => compiled predicate or false */
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

                $ok = $this->db()->query( sprintf(
                    'UPDATE %s e SET e.is_goal_event = 1 WHERE e.site_id = ? AND e.visitor_id = ? AND e.session_id = ?'
                    . ' AND e.yyyymmdd BETWEEN ? AND ? AND %s ORDER BY e.ts, e.id LIMIT 1',
                    $raw, $predicate['sql'] ),
                    array_merge( array( (string) $r['site_id'], (string) $r['visitor_id'], (string) $r['id'], $day, $next ),
                        $predicate['params'] ) );

                if ( $ok === false ) {

                    return false;
                }

                if ( (int) $this->db()->getAffectedRows() > 0 ) {

                    $marked++;

                } elseif ( ! $this->alreadyMarked( $raw, $r, $predicate, $day, $next ) ) {

                    $this->notLocated( $progress );
                }
            }
        }

        return $marked;
    }

    /** Whether the row was marked on an earlier run, which UPDATE reports as no change. */
    private function alreadyMarked( $raw, array $r, array $predicate, $day, $next ) {

        return (bool) $this->db()->get_row( sprintf(
            'SELECT e.id FROM %s e WHERE e.site_id = ? AND e.visitor_id = ? AND e.session_id = ?'
            . ' AND e.yyyymmdd BETWEEN ? AND ? AND e.is_goal_event = 1 AND %s LIMIT 1', $raw, $predicate['sql'] ),
            array_merge( array( (string) $r['site_id'], (string) $r['visitor_id'], (string) $r['id'], $day, $next ),
                $predicate['params'] ) );
    }

    private function notLocated( array &$progress ) {

        $progress['rows_refused']++;
        $progress['refusals'][ self::NOT_LOCATED ] = ( $progress['refusals'][ self::NOT_LOCATED ] ?? 0 ) + 1;
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

            $this->compiled[ $id ] = $goal->wasPersisted()
                ? ( ( new \OWA\Module\Base\Classes\GoalEventPredicate() )->compile( $goal, 'e' ) ?: false )
                : false;
        }

        return $this->compiled[ $id ] ?: null;
    }

    /** The page views' revert removes the rows these marks are on. */
    public function revertSite( $site_id ) {

        $this->forget( $site_id );

        return 0;
    }
}

?>
