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
 * v2 counts a goal event, a row named by the goal event definition -- by the
 * name Update075 gives it, see eventName() -- and flagged is_goal_event = 1
 * (Classes\GoalMarking). For each session v1 says completed
 * goal N, the first migrated row of that session that meets the definition
 * Update025 made of slot N -- its condition compiled by
 * Classes\GoalEventDefinitionPredicate -- gets one.
 *
 * v1's record decides WHICH sessions completed a goal; the definition only
 * finds the row. So nothing is marked that v1 did not count, which is what
 * keeps this from marking history retroactively. A completion the definition
 * cannot locate -- the goal was deleted, or its condition changed -- is
 * counted and reported rather than guessed.
 *
 * THE VALUE GOES WITH IT. v1 kept what each completion was worth beside it,
 * in owa_session.goal_N_value: the goal's value, or the order total of the
 * transaction that completed it, as hundredths whatever the currency
 * (Lib::prepareCurrencyValue). The goal event carries it in `value`, in minor
 * units of the Property's currency, as a goal event marked at ingest does
 * (GoalMarking::valueOf()). A completion v1 recorded no value for carries
 * none.
 *
 * Runs after the page views: the goal event is copied from one of their rows.
 *
 * Runs in Update062, before Update076 renames the definitions' tables, so it
 * reads them under the names 1.x left (namedBefore076()).
 */
class GoalMigrator extends FactMigrator {

    const SOURCE = 'session';

    /** 1.x kept this many slots per Profile. */
    const SLOTS = 15;

    const NOT_LOCATED = 'goal_not_located';

    /** @var array definition id => compiled predicate plus 'name', or false */
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
                if ( ! self::storeGoalEvent( $trigger, $predicate['name'],
                        $this->valueOf( (string) $r['site_id'], $r[ 'goal_' . $n . '_value' ] ?? null ) ) ) {

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
     * Store the goal event for a row already in raw, unless it is there.
     *
     * The SQL form of GoalMarking::goalEvent(), for what is marked after
     * ingest: this migration, and Update075, which turned the events marked
     * before goal events were rows of their own into them. It copies the
     * columns goalEvent() would -- the ones materializedProperties() names --
     * under the id ingest would have derived for it. MySQL's upsert, like the
     * rest of the migration: only an installation upgrading from 1.x, which is
     * MySQL, runs either.
     *
     * created_at is now, so the cube rebuilds the day (Cube\Builder::isCurrent()).
     *
     * @param  array  $trigger  the stored row's id, yyyymmdd, site_id, visitor_id,
     *                          session_id and ts
     * @param  string $name     the definition's name
     * @param  array|null $value  array( 'value' => minor units, 'currency' => code ),
     *                          or null for none. Update075 passes none: it runs
     *                          before Update077 gives raw the column.
     * @return bool   false when the write failed
     */
    public static function storeGoalEvent( array $trigger, $name, $value = null ) {

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

        $insert = array( 'id', 'event_type', 'is_goal_event', 'created_at' );
        $select = array( '?', '?', '1', '?' );
        $params = array( $id, (string) $name, (int) round( microtime( true ) * 1000000 ) );

        if ( $value !== null ) {

            unset( $columns['value'], $columns['currency'] );

            $insert[] = 'value';
            $insert[] = 'currency';
            $select[] = '?';
            $select[] = '?';
            $params[] = (int) $value['value'];
            $params[] = (string) $value['currency'];
        }

        // Qualified throughout: the SELECT reads the table the INSERT writes, so a
        // bare `id` in the duplicate-key clause names both.
        return $db->query( sprintf(
            'INSERT INTO %1$s (%2$s, %3$s)'
          . ' SELECT %4$s, %5$s FROM %1$s t WHERE t.id = ? AND t.yyyymmdd = ?'
          . ' ON DUPLICATE KEY UPDATE %1$s.id = %1$s.id',
            $raw->getTableName(), implode( ', ', $insert ), implode( ', ', $columns ),
            implode( ', ', $select ),
            implode( ', ', array_map( function ( $c ) { return 't.' . $c; }, $columns ) ) ),
            array_merge( $params, array( $trigger['id'], (int) $trigger['yyyymmdd'] ) ) ) !== false;
    }

    /**
     * What v1 recorded one completion as worth, as storeGoalEvent() takes it, or
     * null for nothing.
     *
     * v1 stored hundredths whatever the currency, so the amount is divided by
     * 100 and converted by the currency's own exponent, as PurchaseMigrator does
     * with v1's order totals. The currency is the one the Property's reports use
     * (Currency::ofProperty()), as for a definition's value at ingest.
     *
     * NOT WRITTEN where raw has no `value` column. Raw is created from the
     * entity in Update034, so on an upgrade from 1.x it has the column by now.
     * A raw created before the column existed gets it only in Update077, which
     * runs after this.
     *
     * @param  string $site_id
     * @param  mixed  $v1  owa_session.goal_N_value
     * @return array|null
     */
    private function valueOf( $site_id, $v1 ) {

        if ( ! is_numeric( $v1 ) || (float) $v1 <= 0 || ! $this->rawHasValue() ) {

            return null;
        }

        if ( ! array_key_exists( $site_id, $this->properties ) ) {

            $this->properties[ $site_id ] = self::propertyFor( $site_id );
        }

        $currency = \OWA\Module\Base\Classes\Currency::normalize(
            \OWA\Module\Base\Classes\Currency::ofProperty( (string) $this->properties[ $site_id ] ) );
        $currency = $currency !== '' ? $currency : 'USD';

        return array(
            'value'    => \OWA\Module\Base\Classes\Currency::toMinorUnits( $v1 / 100, $currency ),
            'currency' => $currency,
        );
    }

    /** @var bool|null whether raw has a `value` column, asked once */
    private $raw_has_value = null;

    private function rawHasValue() {

        if ( $this->raw_has_value === null ) {

            $this->raw_has_value = (bool) $this->db()->get_results( sprintf( "SHOW COLUMNS FROM %s LIKE 'value'",
                \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName() ) );
        }

        return $this->raw_has_value;
    }

    /** The Property a site belongs to. */
    public static function propertyFor( $site_id ) {

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
        $site->getByColumn( 'site_id', $site_id );

        return $site->get( 'property_id' );
    }

    /**
     * The goal event definition 1.13's Update025 made of a Property's goal
     * slot, or null.
     *
     * Read by property and slot, NOT by re-deriving the id Update025 gave it.
     * An installation that ran it while still on 32-bit ids holds a 32-bit id
     * there, and a derivation now is 64-bit: it would find nothing.
     *
     * @return string|null
     */
    public static function goalEventDefinitionFor( $property_id, $goal_number ) {

        $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->namedBefore076();

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

        $id = self::goalEventDefinitionFor( $this->properties[ $site_id ], $n );

        if ( $id === null ) {

            return null;
        }

        if ( ! array_key_exists( $id, $this->compiled ) ) {

            $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' )->namedBefore076();

            $goal->load( $id );

            $compiled = $goal->wasPersisted()
                ? ( new \OWA\Module\Base\Classes\GoalEventDefinitionPredicate() )->compile( $goal, 'e' )
                : false;

            $this->compiled[ $id ] = $compiled ? $compiled + array( 'name' => $this->eventName( $goal ) ) : false;
        }

        return $this->compiled[ $id ] ?: null;
    }

    /** @var array property id => definition id => its name once Update075 has run */
    private $renamed = array();

    /**
     * The name a definition's goal events carry: the one Update075 gives it,
     * NOT the 1.x name it still has.
     *
     * This pass runs in Update062, before Update075 renames the definitions
     * whose 1.x names are not event names ("Contact form" becomes
     * Contact_form). A row stored under the 1.x name would stay under it, and
     * the Goals report would show the migrated goal events and every later one
     * under two names. After Update075 nothing is renamed and this is the
     * name the definition has, so revertSite() finds the same rows either way.
     */
    private function eventName( \OWA\Module\Base\Entity\GoalEventDefinition $goal ) {

        $property = (string) $goal->get( 'property_id' );

        if ( ! array_key_exists( $property, $this->renamed ) ) {

            $rows = array_map( function ( $r ) { return (array) $r; }, (array) $this->db()->get_results( sprintf(
                'SELECT id, property_id, name FROM %s WHERE property_id = ? ORDER BY id', $goal->getTableName() ),
                array( $property ) ) );

            /*
             * The names as STORED, under the renames. Not the entity's: it is
             * loaded through the entity cache, and Update075 renames with SQL,
             * so after it the cached entity can still carry the 1.x name.
             */
            $this->renamed[ $property ] = \OWA\Module\Base\Entity\GoalEventDefinition::eventNames( $rows )
                + array_column( $rows, 'name', 'id' );
        }

        return (string) ( $this->renamed[ $property ][ (string) $goal->get( 'id' ) ] ?? $goal->get( 'name' ) );
    }

    /**
     * Delete the goal events this pass stored for a site.
     *
     * The passes revert in reverse, so this runs before the page views' and
     * reads the same v1 sessions again: each completion's goal event is the
     * definition's row in that session.
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
