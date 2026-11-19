<?php
namespace OWA\Module\Base\Classes;

/**
 * What a goal condition is allowed to name.
 *
 * THE RAW ROW'S COLUMNS, and nothing else. A goal is decided by what the stored
 * row holds, and that one rule answers three questions at once: what the builder
 * offers, what a saved condition may say, and what Classes\GoalMarking reads.
 *
 * SCOPED BY EVENT TYPE, from the property registry. A condition on element_id is
 * meaningless on a page view -- only a click carries one -- and offering it
 * produces a goal that cannot fire. propertiesForEvent() answers which
 * properties an event carries; this adds the link from a property to the column
 * it lands in.
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
    const SOURCE = [

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

        // The purchase, whose wire names carry 1.x's commerce-transaction prefix.
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
    ];

    /**
     * Columns a condition may NOT name, and the reason for each.
     *
     * A map rather than a list because the reason is the useful part: this is
     * where the judgement about what a goal IS gets recorded, and the test that
     * every column is accounted for reads the same set.
     */
    const EXCLUDED = [

        'id'     => 'the row identity, derived from the other columns -- a '
                    . 'condition on it is a condition on a hash',

        'params' => 'a JSON document. A condition on the whole blob would match '
                    . "on substrings of other people's values; custom properties "
                    . 'get a vocabulary when they get columns',

        'is_goal_event' => 'the answer. A condition reading it would make marking '
                           . 'depend on marking',

        'value' => 'set by marking, on goal events only. Every event a condition '
                   . 'is tested against carries none',

        'event_type' => 'the trigger already says which event this is about, and '
                        . 'a condition on it could contradict the trigger',

        'created_at' => 'when the row reached raw, stamped by ingest -- provenance a '
                        . 'build reads, not anything a visitor did',

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
        'prior_touch_ts'         => 'an epoch value. The prior touch\'s source, '
                                    . 'medium and campaign are the readable parts',

        'event_seq'   => 'the event\'s position in its page load, an ordering the '
                         . 'tracker keeps for the build -- not something a visitor did',

        'element_anchor' => 'a hash of the clicked element\'s place in the page, which '
                            . 'the heatmap matches; element_id is the readable way to '
                            . 'name an element',
        'anchor_x'       => 'where inside the element the click landed, for drawing it',
        'anchor_y'       => 'where inside the element the click landed, for drawing it',

        'spam_score' => 'how much the request looked like spam, stamped on every row '
                        . 'of the beacon. Not something a visitor did',
        'spam_rules' => 'the spam rules the request met. Not something a visitor did',
    ];

    /**
     * What a column holds, for the builder's picker, where the report dimension
     * reading the same column has no description to lend (description()).
     */
    const DESCRIPTIONS = [
        'raw_ua'               => 'The browser\'s full user agent string, as it arrived. '
                                  . 'Browser, operating system and device are its readings.',
        'engagement_msec'      => 'How long the page was in the foreground before this event, '
                                  . 'in milliseconds.',
        'page_width'           => 'The width of the browser window, in pixels, when the click happened.',
        'page_height'          => 'The height of the browser window, in pixels, when the click happened.',
        'referer_query'        => 'The query string of the referring URL, without the host or path.',
        'tagged_ad'            => 'The ad or link the landing URL was tagged with '
                                  . '(owa_ad or utm_content).',
        'tagged_search_terms'  => 'The search terms the landing URL was tagged with '
                                  . '(owa_search_terms or utm_term).',
        'prior_touch_source'   => 'The source of the visit before this one that had a '
                                  . 'campaign or referrer, within the lookback window.',
        'prior_touch_medium'   => 'The medium of that prior touch.',
        'prior_touch_campaign' => 'The campaign of that prior touch.',
        'prior_touch_ad'       => 'The ad of that prior touch.',
        'prior_touch_referer_host' => 'The referring host of that prior touch.',
        'revenue'              => 'The purchase\'s total, in minor units of its currency '
                                  . '(cents for USD).',
        'tax'                  => 'The tax on the purchase, in minor units of its currency.',
        'shipping'             => 'The shipping on the purchase, in minor units of its currency.',
    ];

    /**
     * v1 property names that no longer exist, and the v2 column that means what
     * they meant.
     *
     * For the migration of stored declarations, not for the builder: 1.x
     * conditions name these -- page_uri or medium, what the 1.x builder offered
     * and what Update025 wrote. Neither is a column, so without this map the
     * migrated goals would count nothing.
     *
     * The acquisition four map to the tagged_* columns deliberately: the raw row
     * records what the LANDING URL CLAIMED, and the classified answer -- tag if
     * there was one, else the referrer classified -- is the pass's, on the cube.
     * A goal is decided at ingest, so it can only test the claim.
     *
     * NOT EXHAUSTIVE, and cannot be: the 1.x builder offered every property name
     * there was, including ones with no column at all (page_type, is_robot, the
     * date parts). Those are reported by the migration rather than guessed at.
     */
    const LEGACY = [
        'page_uri'     => 'page_path',
        'page_url'     => 'page_location',
        'medium'       => 'tagged_medium',
        'source'       => 'tagged_source',
        'campaign'     => 'tagged_campaign',
        'ad'           => 'tagged_ad',
        'search_terms' => 'tagged_search_terms',
        'search_term'  => 'tagged_search_terms',
        // 1.x's browser was the user agent's version (resolveBrowserVersion);
        // the family was browser_type, which is still a column.
        'browser'      => 'browser_version',
    ];

    /** Labels that do not survive being prettified from the column name. */
    const LABELS = [
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
        // Prettifying the column gives "Is outbound", which reads as a question
        // half-asked. The condition is on the click.
        'is_outbound'         => 'Outbound click',
        'engagement_msec'     => 'Engagement time (ms)',
        'target_url'          => 'Target URL',
    ];

    /**
     * Every column a condition may name, whatever the event type.
     *
     * @return string[]
     */
    public static function columns() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        $out = [];

        foreach ( array_keys( (array) $entity->getProperties() ) as $column ) {

            if ( ! array_key_exists( $column, self::EXCLUDED ) ) {

                $out[] = $column;
            }
        }

        sort( $out );

        return $out;
    }

    /**
     * Is this a column a condition may name?
     *
     * @param  string $column
     * @return bool
     */
    public static function has( $column ) {

        return in_array( (string) $column, self::columns(), true );
    }

    /**
     * The columns a condition on this event type may name, as column => label.
     *
     * @param  string $event_name  a v2 event name
     * @return array<string, string>
     */
    public static function columnsForEvent( $event_name ) {

        $properties = TrackingEventHelpers::propertiesForEvent( $event_name );

        $out = [];

        foreach ( self::columns() as $column ) {

            $source = isset( self::SOURCE[ $column ] ) ? self::SOURCE[ $column ] : $column;

            if ( in_array( $source, $properties, true ) ) {

                $out[ $column ] = self::label( $column );
            }
        }

        asort( $out );

        return $out;
    }

    /** @var array<string, string>|null column => description, from the dimension registry */
    private static $registryDescriptions = null;

    /**
     * What a column holds, in a sentence, for the builder's picker.
     *
     * The report dimension that reads the same column says it already: a goal
     * on is_outbound and a report grouped by Outbound Click are about the same
     * value, so the two describe it once. DESCRIPTIONS covers the columns no
     * dimension reads.
     *
     * @param  string $column
     * @return string  empty when nothing describes it
     */
    public static function description( $column ) {

        if ( self::$registryDescriptions === null ) {

            self::$registryDescriptions = [];

            // The modules' dimensions alone: these describe raw row columns,
            // and a custom dimension is a column of one Property's cube.
            foreach ( DimensionRegistry::forProperty( null )->all() as $dim ) {

                $col = (string) ( $dim['column'] ?? '' );

                if ( $col !== '' && ! empty( $dim['description'] )
                     && ( $dim['entity'] ?? '' ) === 'base.event'
                     && ! isset( self::$registryDescriptions[ $col ] ) ) {

                    self::$registryDescriptions[ $col ] = (string) $dim['description'];
                }
            }
        }

        $column = (string) $column;

        if ( isset( self::$registryDescriptions[ $column ] ) ) {

            return self::$registryDescriptions[ $column ];
        }

        return isset( self::DESCRIPTIONS[ $column ] ) ? \OWA\Core\Strings::translate( self::DESCRIPTIONS[ $column ] ) : '';
    }

    /**
     * A column's label.
     *
     * @param  string $column
     * @return string
     */
    public static function label( $column ) {

        $column = (string) $column;

        if ( isset( self::LABELS[ $column ] ) ) {

            return \OWA\Core\Strings::translate( self::LABELS[ $column ] );
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
         * because a stored condition can name either side.
         */
        $column = array_search( $property, self::SOURCE, true );

        return $column === false ? null : $column;
    }
}
