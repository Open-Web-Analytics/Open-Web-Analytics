<?php

namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * What a site's last thirty minutes look like, read from raw (PLAN 1.6).
 *
 * RAW, NOT THE CUBE. Reports read the cube and are as of the last build;
 * this is immediate, and reports only what is on the event or in the visitor
 * store. A classified source, medium or channel is the build's and is not
 * here: first-user acquisition is shown as it was collected -- the tagged
 * source, else the referring host.
 *
 * EVERY QUERY CARRIES A CLOSED WINDOW: site, a yyyymmdd range and a ts range.
 * The day range prunes raw's partitions; site_ts (Update064) makes the ts
 * range a range read, so the cost follows the window rather than the day.
 * The statements run are kept in $statements so a test can assert that on the
 * SQL itself (PLAN 2.16), which a timing test on a small fixture cannot.
 */
class Realtime {

    const WINDOW_MINUTES = 30;

    const RECENT_MINUTES = 5;

    /** Rows per card, as GA caps its cards: the busiest, not all of them. */
    const TOP = 10;

    const RECENT_EVENTS = 20;

    const VISITOR_EVENTS = 100;

    /** @var string[] every statement run, in order */
    public $statements = array();

    private $site_id;

    private $end;

    private $start;

    private $days;

    /**
     * @param string   $site_id
     * @param int|null $now_usec the window's end, microseconds; now by default
     */
    public function __construct( $site_id, $now_usec = null ) {

        $this->site_id = (string) $site_id;
        $this->end     = $now_usec !== null ? (int) $now_usec : (int) round( microtime( true ) * 1000000 );
        $this->start   = $this->end - self::WINDOW_MINUTES * 60 * 1000000;

        // yyyymmdd is written in the installation's zone, so the window's
        // days are read in it too. Two days when the window spans midnight.
        $zone       = new \DateTimeZone( JobStatus::timezone() );
        $this->days = array(
            (int) ( new \DateTimeImmutable( '@' . intdiv( $this->start, 1000000 ) ) )->setTimezone( $zone )->format( 'Ymd' ),
            (int) ( new \DateTimeImmutable( '@' . intdiv( $this->end, 1000000 ) ) )->setTimezone( $zone )->format( 'Ymd' ),
        );
    }

    /** @return array every card, in one answer */
    public function summary() {

        $recent_start = $this->end - self::RECENT_MINUTES * 60 * 1000000;

        return array(
            'window'      => array( 'start' => $this->start, 'end' => $this->end, 'minutes' => self::WINDOW_MINUTES ),
            'activeUsers' => array(
                'last30' => $this->users( $this->start ),
                'last5'  => $this->users( $recent_start ),
            ),
            'perMinute'   => $this->perMinute(),
            'pages'       => $this->pages(),
            'events'      => $this->events(),
            'goals'       => $this->goals(),
            'sources'     => $this->sources(),
            'countries'   => $this->countries(),
            'located'     => $this->located(),
            'devices'     => $this->devices(),
            'recent'      => $this->recent(),
            'queued'      => (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'queue_events' ),
        );
    }

    /**
     * One visitor's events in the window, newest first: GA's user snapshot.
     *
     * @param  string $visitor_id
     * @return array[]
     */
    public function visitor( $visitor_id ) {

        if ( ! ctype_digit( (string) $visitor_id ) ) {

            return array();
        }

        return array_map( array( $this, 'event' ), $this->rows(
            'SELECT ts, event_type, page_path, page_title, country, city, visitor_id FROM {raw} r'
          . ' WHERE {window} AND r.visitor_id = ' . (string) $visitor_id
          . ' ORDER BY ts DESC LIMIT ' . self::VISITOR_EVENTS ) );
    }

    // ---- cards -------------------------------------------------------------

    private function users( $from ) {

        $row = $this->rows( 'SELECT COUNT(DISTINCT visitor_id) AS n FROM {raw} r WHERE {window}', $from );

        return (int) ( $row[0]['n'] ?? 0 );
    }

    /** Distinct visitors per minute, oldest first; the last is the current minute. */
    private function perMinute() {

        $out = array_fill( 0, self::WINDOW_MINUTES, 0 );

        foreach ( $this->rows(
                'SELECT LEAST(' . ( self::WINDOW_MINUTES - 1 ) . ', FLOOR((ts - ' . $this->start . ') / 60000000)) AS m,'
              . ' COUNT(DISTINCT visitor_id) AS n FROM {raw} r WHERE {window} GROUP BY m' ) as $row ) {

            $m = (int) $row['m'];

            if ( $m >= 0 && $m < self::WINDOW_MINUTES ) {

                $out[ $m ] = (int) $row['n'];
            }
        }

        return $out;
    }

    private function pages() {

        return array_map( function ( $r ) {

            return array( 'path' => $r['page_path'], 'title' => $r['title'],
                'views' => (int) $r['views'], 'users' => (int) $r['users'] );

        }, $this->rows(
            "SELECT page_path, MAX(page_title) AS title, SUM(event_type = 'page_view') AS views,"
          . ' COUNT(DISTINCT visitor_id) AS users FROM {raw} r WHERE {window} AND page_path IS NOT NULL'
          . ' GROUP BY page_path ORDER BY views DESC, users DESC LIMIT ' . self::TOP ) );
    }

    private function events() {

        return array_map( function ( $r ) {

            return array( 'name' => $r['event_type'], 'count' => (int) $r['n'] );

        }, $this->rows(
            'SELECT event_type, COUNT(*) AS n FROM {raw} r WHERE {window}'
          . ' GROUP BY event_type ORDER BY n DESC LIMIT ' . self::TOP ) );
    }

    /**
     * Goal completions: the flagged events, and which goal each was.
     *
     * is_goal_event is one flag per event (Classes\GoalMarking), so which
     * goal converted is each goal's predicate run against the flagged rows
     * (Classes\GoalEventPredicate). Goals are matched at ingest on the raw
     * row, so their vocabulary is raw's and every one that compiles runs here;
     * one that does not compile is left out of the breakdown, its completions
     * still in the total.
     */
    private function goals() {

        $total = $this->rows( 'SELECT COUNT(*) AS n FROM {raw} r WHERE {window} AND r.is_goal_event = 1' );
        $out   = array( 'total' => (int) ( $total[0]['n'] ?? 0 ), 'byGoal' => array() );

        if ( ! $out['total'] ) {

            return $out;
        }

        $predicate = new GoalEventPredicate();

        foreach ( $this->activeGoals() as $goal ) {

            $compiled = $predicate->compile( $goal, 'r' );

            if ( ! $compiled ) {

                continue;
            }

            $row = $this->rows(
                'SELECT COUNT(*) AS n FROM {raw} r WHERE {window} AND r.is_goal_event = 1 AND ' . $compiled['sql'],
                null, $compiled['params'] );

            if ( ! empty( $row[0]['n'] ) ) {

                $out['byGoal'][] = array( 'name' => (string) $goal->get( 'name' ), 'count' => (int) $row[0]['n'] );
            }
        }

        usort( $out['byGoal'], function ( $a, $b ) { return $b['count'] <=> $a['count']; } );

        return $out;
    }

    /**
     * First-user acquisition of the window's visitors, as collected.
     *
     * The tagged source, else the referring host, else (direct): the build's
     * reading (Cube\SourceStep), unclassified. NULL where no acquisition was
     * captured -- acq_ts, not the row, which a user property can create for a
     * visitor whose acquisition is unknown (Cube\Columns) -- and the screen
     * labels it (unknown), as a report does.
     */
    private function sources() {

        $store = \OWA\Core\CoreAPI::entityFactory( 'base.visitor_acquisition' )->getTableName();

        return array_map( function ( $r ) {

            return array( 'source' => $r['source'], 'medium' => $r['medium'],
                'campaign' => $r['campaign'], 'users' => (int) $r['users'] );

        }, $this->rows(
            "SELECT CASE WHEN a.acq_ts IS NULL THEN NULL"
          . " ELSE COALESCE(NULLIF(a.acq_source, ''), NULLIF(a.acq_referer_host, ''), '(direct)') END AS source,"
          . ' a.acq_medium AS medium, a.acq_campaign AS campaign, COUNT(*) AS users'
          . ' FROM (SELECT DISTINCT visitor_id FROM {raw} r WHERE {window}) v'
          . ' LEFT JOIN ' . $store . ' a ON a.visitor_id = v.visitor_id'
          . ' GROUP BY source, medium, campaign ORDER BY users DESC LIMIT ' . self::TOP ) );
    }

    /** Every country with a visitor, for the map; the table shows the first few. */
    private function countries() {

        return array_map( function ( $r ) {

            return array( 'code' => $r['country_code'], 'name' => $r['country'], 'users' => (int) $r['users'] );

        }, $this->rows(
            'SELECT country_code, MAX(country) AS country, COUNT(DISTINCT visitor_id) AS users'
          . ' FROM {raw} r WHERE {window} AND country_code IS NOT NULL'
          . ' GROUP BY country_code ORDER BY users DESC' ) );
    }

    /** Visitors whose location is known, so the map can say how many it cannot show. */
    private function located() {

        $row = $this->rows(
            'SELECT COUNT(DISTINCT visitor_id) AS n FROM {raw} r WHERE {window} AND country_code IS NOT NULL' );

        return (int) ( $row[0]['n'] ?? 0 );
    }

    private function devices() {

        return array_map( function ( $r ) {

            return array( 'type' => $r['device_type'], 'users' => (int) $r['users'] );

        }, $this->rows(
            'SELECT device_type, COUNT(DISTINCT visitor_id) AS users FROM {raw} r WHERE {window}'
          . ' GROUP BY device_type ORDER BY users DESC LIMIT ' . self::TOP ) );
    }

    private function recent() {

        return array_map( array( $this, 'event' ), $this->rows(
            'SELECT ts, event_type, page_path, page_title, country, city, visitor_id FROM {raw} r WHERE {window}'
          . ' ORDER BY ts DESC LIMIT ' . self::RECENT_EVENTS ) );
    }

    // ---- plumbing ----------------------------------------------------------

    private function event( array $r ) {

        return array(
            'ts'      => (int) $r['ts'],
            'type'    => $r['event_type'],
            'path'    => $r['page_path'],
            'title'   => $r['page_title'],
            'country' => $r['country'],
            'city'    => $r['city'],
            // As text: a visitor id is a 64-bit integer, past what JSON's
            // numbers hold exactly in a browser.
            'visitor' => (string) $r['visitor_id'],
        );
    }

    /** The site's active goal events, the way GoalMarking loads them. */
    private function activeGoals() {

        $property_id = \OWA\Module\Base\Entity\GoalEvent::propertyFor( $this->site_id );

        // No Property is no goals. An unguarded where() on an empty value would
        // hand this site every Property's goal events.
        if ( ! $property_id ) {

            return array();
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $db     = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'property_id', $property_id );
        $db->where( 'is_active', 1 );

        $goals = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            $goal = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
            $goal->setProperties( $row );
            $goals[] = $goal;
        }

        return $goals;
    }

    /**
     * Run one card's statement over the window.
     *
     * {raw} is raw's table and {window} the site, day and time bounds.
     * Substituted by name rather than by sprintf, because a compiled goal
     * predicate can carry a LIKE pattern and its % would be read as a format.
     *
     * @param  string   $sql
     * @param  int|null $from   window start, microseconds; the window's own by default
     * @param  array    $params bound after the site
     * @return array[]
     */
    private function rows( $sql, $from = null, array $params = array() ) {

        $window = sprintf( 'r.site_id = ? AND r.yyyymmdd BETWEEN %d AND %d AND r.ts > %d AND r.ts <= %d',
            min( $this->days ), max( $this->days ), $from !== null ? (int) $from : $this->start, $this->end );

        $sql = str_replace( array( '{raw}', '{window}' ),
            array( \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName(), $window ), $sql );

        $this->statements[] = $sql;

        return array_map( function ( $r ) { return (array) $r; },
            (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( $sql, array_merge( array( $this->site_id ), $params ) ) );
    }
}

?>
