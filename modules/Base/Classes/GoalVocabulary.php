<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * What a goal condition is allowed to name.
 *
 * THE RAW ROW'S COLUMNS, and nothing else. A key event in GA is decided by the
 * event's parameters -- what the stored row holds -- and the same rule here
 * answers three questions at once: what the builder offers, what a saved
 * condition may say, and what Classes\GoalMarking reads. Before this there were
 * three answers. The builder offered every client and server PROPERTY name;
 * marking matched against the tracking event, so it saw property names too; and
 * GoalEventPredicate kept a hand-written map of four v1 names to four v1
 * columns. None of the three agreed with the row that actually gets stored.
 *
 * SCOPED BY EVENT TYPE, from the property registry. A condition on element_id is
 * meaningless on a page view -- only a click carries one -- and offering it
 * produces a goal that cannot fire. propertiesForEvent() already answers which
 * properties an event carries, so the only thing missing was the link from a
 * property to the column it lands in.
 *
 * THAT LINK IS ONE MAP, and every column is either in it or named after its own
 * property. A column whose name differs from its source is a RENAME
 * (dom_element_id becomes element_id) or a DERIVATION (page_path is cut from
 * page_location, device_type from the user agent) -- and for this purpose those
 * are the same thing: the column is available for the events its source property
 * is. A test asserts every raw column is accounted for here, so a new column
 * forces a decision rather than silently becoming unconditionable.
 */
class GoalVocabulary {

    /**
     * column => the property it comes from, where the two names differ.
     *
     * Only the differences: a column with no entry here is named after its own
     * property, which is most of them.
     */
    const SOURCE = array(

        // Cut from the location, which every event carries.
        'page_path'  => 'page_location',
        'page_query' => 'page_location',

        // The referrer, whole and in parts.
        'referer_url'   => 'HTTP_REFERER',
        'referer_host'  => 'HTTP_REFERER',
        'referer_query' => 'HTTP_REFERER',

        // The click target.
        'target_host' => 'target_url',

        // The one user-agent parse, which is also why device_type is available
        // on every event type: the request carries the agent, not the beacon.
        'raw_ua'          => 'HTTP_USER_AGENT',
        'browser_version' => 'HTTP_USER_AGENT',
        'os_version'      => 'HTTP_USER_AGENT',
        'device_type'     => 'HTTP_USER_AGENT',
        'device_brand'    => 'HTTP_USER_AGENT',
        'device_model'    => 'HTTP_USER_AGENT',

        // The browser column is the FAMILY; browser_type is the same reading
        // under its own name, and browser_version comes from the parse above.
        'browser' => 'browser_type',

        // The purchase, whose wire names carry 1.x's commerce-transaction prefix.
        'revenue'        => 'ct_total',
        'tax'            => 'ct_tax',
        'shipping'       => 'ct_shipping',
        'transaction_id' => 'ct_order_id',

        // Renames the row applies.
        'element_id'  => 'dom_element_id',
        'element_tag' => 'dom_element_tag',
        'region'      => 'state',

        'tagged_search_terms' => 'tagged_terms',

        'visitor_fsts'           => 'fsts',
        'prior_sessions'         => 'num_prior_sessions',
        'session_start_ts'       => 'sts',
        'prior_session_start_ts' => 'psts',
    );

    /**
     * Columns a condition may NOT name, and the reason for each.
     *
     * A map rather than a list because the reason is the useful part: this is
     * where the judgement about what a goal IS gets recorded, and the test that
     * every column is accounted for reads the same set.
     */
    const EXCLUDED = array(

        'id'     => 'the row identity, derived from the other columns -- a '
                    . 'condition on it is a condition on a hash',

        'params' => 'a JSON document. A condition on the whole blob would match '
                    . "on substrings of other people's values; custom properties "
                    . 'get a vocabulary when they get columns',

        'is_goal_event' => 'the answer. A condition reading it would make marking '
                           . 'depend on marking',

        'event_type' => 'the trigger already says which event this is about, and '
                        . 'a condition on it could contradict the trigger',

        'site_id'    => 'the goal belongs to the Property, which owns the Profile',
        'visitor_id' => 'an identity hash, not a behaviour',
        'session_id' => 'an identity hash, not a behaviour',

        'beacon_version' => 'which tracker generation wrote the row. Nothing '
                            . 'consults it at ingest and a visitor did not choose it',

        'ts'       => 'a goal on an instant marks that instant, which is a report '
                      . 'filter rather than a behaviour worth counting',
        'yyyymmdd' => 'as ts: a date is what a report is bounded BY',

        'visitor_fsts'           => 'an epoch value. prior_sessions is the '
                                    . 'readable form of the same question',
        'session_start_ts'       => 'an epoch value',
        'prior_session_start_ts' => 'an epoch value',
    );

    /**
     * v1 property names that no longer exist, and the v2 column that means what
     * they meant.
     *
     * For the migration of stored declarations, not for the builder. Every
     * condition in the field names one of these -- page_uri or medium, measured
     * on both installs here -- because they are what the old builder offered and
     * what Update025 wrote. Neither is a column, so without this map the
     * migrated goals count nothing.
     *
     * The acquisition four map to the tagged_* columns deliberately: the raw row
     * records what the LANDING URL CLAIMED, and the classified answer -- tag if
     * there was one, else the referrer classified -- is the pass's, on the cube.
     * A goal is decided at ingest, so it can only test the claim.
     *
     * NOT EXHAUSTIVE, and cannot be: the old builder offered every property name
     * there was, including ones with no column at all (page_type, is_robot, the
     * date parts). Those are reported by the migration rather than guessed at.
     */
    const LEGACY = array(
        'page_uri'     => 'page_path',
        'page_url'     => 'page_location',
        'medium'       => 'tagged_medium',
        'source'       => 'tagged_source',
        'campaign'     => 'tagged_campaign',
        'ad'           => 'tagged_ad',
        'search_terms' => 'tagged_search_terms',
        'search_term'  => 'tagged_search_terms',
    );

    /** Labels that do not survive being prettified from the column name. */
    const LABELS = array(
        'raw_ua'              => 'User agent',
        'page_path'           => 'Page path',
        'page_location'       => 'Page URL',
        'referer_url'         => 'Referrer URL',
        'referer_host'        => 'Referrer host',
        'referer_query'       => 'Referrer query',
        'tagged_source'       => 'Source (from the URL)',
        'tagged_medium'       => 'Medium (from the URL)',
        'tagged_campaign'     => 'Campaign (from the URL)',
        'tagged_ad'           => 'Ad (from the URL)',
        'tagged_search_terms' => 'Search terms (from the URL)',
        'visitor_fsts'        => 'First seen at',
        'session_start_ts'    => 'Session started at',
        'os'                  => 'Operating system',
        'ip_address'          => 'IP address',
        'yyyymmdd'            => 'Date',
    );

    /**
     * Every column a condition may name, whatever the event type.
     *
     * @return string[]
     */
    public static function columns() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        $out = array();

        foreach ( array_keys( (array) $entity->getProperties() ) as $column ) {

            if ( ! array_key_exists( $column, self::EXCLUDED ) ) {

                $out[] = $column;
            }
        }

        sort( $out );

        return $out;
    }

    /** Is this a column a condition may name? */
    public static function has( $column ) {

        return in_array( (string) $column, self::columns(), true );
    }

    /**
     * The columns a condition on this event type may name, as column => label.
     *
     * @param  string $event_name  a v2 event name
     * @return array
     */
    public static function columnsForEvent( $event_name ) {

        $properties = TrackingEventHelpers::propertiesForEvent( $event_name );

        $out = array();

        foreach ( self::columns() as $column ) {

            $source = isset( self::SOURCE[ $column ] ) ? self::SOURCE[ $column ] : $column;

            if ( in_array( $source, $properties, true ) ) {

                $out[ $column ] = self::label( $column );
            }
        }

        asort( $out );

        return $out;
    }

    /** A column's label. */
    public static function label( $column ) {

        $column = (string) $column;

        if ( isset( self::LABELS[ $column ] ) ) {

            return self::LABELS[ $column ];
        }

        return ucfirst( str_replace( '_', ' ', $column ) );
    }

    /**
     * The column a stored condition_property means, or null if nothing does.
     *
     * Idempotent: a value that is already a column is returned unchanged, so the
     * migration can run twice.
     *
     * @param  string $property
     * @return string|null
     */
    public static function columnFor( $property ) {

        $property = (string) $property;

        if ( self::has( $property ) ) {

            return $property;
        }

        if ( isset( self::LEGACY[ $property ] ) ) {

            return self::LEGACY[ $property ];
        }

        /*
         * A property whose column is named differently -- the row's own renames.
         * Read from the same map the builder uses rather than a second list,
         * because a condition saved before this existed could name either side.
         */
        $column = array_search( $property, self::SOURCE, true );

        return $column === false ? null : $column;
    }
}

?>
