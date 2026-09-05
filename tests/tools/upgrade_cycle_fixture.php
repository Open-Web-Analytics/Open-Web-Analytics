<?php
/**
 * Legacy-shaped DATA for the upgrade cycle to migrate.
 *
 * WHY
 * ---
 * upgrade_cycle.php rewinds and rebuilds the SCHEMA, which is what caught the
 * two ALTER bugs. But it round-trips an EMPTY database: a fresh install has no
 * 1.x goals, so Update025 -- the one update in the swept range that migrates
 * DATA rather than adding columns -- ran against nothing and reported
 * "Migrated 0 goal(s)" every time. Its read path had never been executed by any
 * test.
 *
 * That is the same hole one level down, and it is the more dangerous half: a
 * broken schema migration stops the upgrade and everybody notices, while a
 * broken data migration completes, reports success, and silently loses what it
 * was supposed to carry across.
 *
 * WHAT IS SEEDED
 * --------------
 * The 1.x goal blob, in the shape a real install holds it: a fixed-length array
 * of numbered slots, nearly all of them empty stubs, inside one settings row
 * per Profile. Live installs carry fifteen slots to describe one goal.
 *
 * ASYMMETRIC ON PURPOSE. Four real goals among eleven stubs, with four
 * different values (100, 1250, 500 and 0 cents), two different match types, one
 * INACTIVE, and one whose value is not a number at all. No assertion here can
 * pass by landing on a figure that happens to be right for another reason --
 * which is exactly what "4 goals, all with value 100" would allow.
 *
 * ADDING A CASE
 * -------------
 * A new data migration gets a seed() clause and an expect() clause here. The
 * sweep calls both and needs no knowledge of either.
 */

/** The 1.x goals blob: four real goals and eleven empty slots. */
function owa_upgrade_cycle_goal_blob() {

    $goals = array();

    // 1. the ordinary case: active, whole-number value, exact match.
    $goals[1] = array(
        'goal_name'   => 'Contact',
        'goal_group'  => 'leads',
        'goal_status' => 'active',
        'goal_value'  => '1',
        'goal_type'   => 'url_destination',
        'goal_number' => 1,
        'details'     => array( 'match_type' => 'exact', 'goal_url' => '/contact' ),
    );

    // 2. a decimal value and a different match type, so neither is assumed.
    $goals[2] = array(
        'goal_name'   => 'Signup',
        'goal_group'  => 'leads',
        'goal_status' => 'active',
        'goal_value'  => '12.50',
        'goal_type'   => 'url_destination',
        'goal_number' => 2,
        'details'     => array( 'match_type' => 'regex', 'goal_url' => '/signup.*' ),
    );

    // 3. NOT active. A migration that copies every goal as enabled would turn
    //    a goal somebody switched off back on, and report success doing it.
    $goals[3] = array(
        'goal_name'   => 'Retired Offer',
        'goal_group'  => 'sales',
        'goal_status' => 'deleted',
        'goal_value'  => '5',
        'goal_type'   => 'url_destination',
        'goal_number' => 3,
        'details'     => array( 'match_type' => 'exact', 'goal_url' => '/offer' ),
    );

    // 4. a value that is not a number, and NO goal_number -- the slot key has
    //    to stand in for it. 1.x stored value as free-form text, so this is the
    //    one field that can fail to convert; it must migrate as 0 rather than
    //    take the goal down with it.
    $goals[4] = array(
        'goal_name'   => 'Newsletter',
        'goal_group'  => 'leads',
        'goal_status' => 'active',
        'goal_value'  => 'free',
        'goal_type'   => 'url_destination',
        'details'     => array( 'match_type' => 'exact', 'goal_url' => '/newsletter' ),
    );

    // 5-15. The stubs. They exist because the blob was a fixed-length array,
    // not because anybody made them, and they must NOT become rows.
    for ( $i = 5; $i <= 15; $i++ ) {

        $goals[ $i ] = array( 'goal_number' => '', 'goal_name' => '',
                              'goal_group'  => '', 'goal_status' => '', 'goal_type' => '' );
    }

    return $goals;
}

/** How many of the slots above are real goals, and how many are stubs. */
function owa_upgrade_cycle_expected_goals() {

    return array(
        // name => [goal_number, is_active, value in cents, operator, url]
        'Contact'       => array( 1, 1, 100,  'exact', '/contact' ),
        'Signup'        => array( 2, 1, 1250, 'regex', '/signup.*' ),
        'Retired Offer' => array( 3, 0, 500,  'exact', '/offer' ),
        'Newsletter'    => array( 4, 1, 0,    'exact', '/newsletter' ),
    );
}

/**
 * Writes the legacy data onto the installed schema.
 *
 * @return array{profile:string, property:string} what it attached to
 */
function owa_upgrade_cycle_seed() {

    $db = \OWA\Core\CoreAPI::dbSingleton();

    /*
     * A Profile that actually HAS a Property.
     *
     * Not simply the first row: the e2e harness adds a tracker fixture site of
     * its own with property_id 0, and picking that one makes the migration's
     * site -> property hop untestable while looking like an installer bug. The
     * installer's own default site is linked correctly.
     */
    $sites = (array) $db->get_results(
        'SELECT site_id, property_id FROM owa_site
          WHERE property_id IS NOT NULL AND property_id > 0
          ORDER BY id LIMIT 1' );

    if ( ! $sites ) {

        $all = (array) $db->get_results( 'SELECT COUNT(*) AS n FROM owa_site' );

        fwrite( STDERR, sprintf(
            "no Profile on this install has a Property (%s site row(s) in total), so the\n"
          . "migration's site -> property hop cannot be exercised. The installer links one;\n"
          . "if it has stopped doing so, that is the finding.\n",
            $all ? ( (array) $all[0] )['n'] : '?' ) );

        exit( 2 );
    }

    $site = (array) $sites[0];

    $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

    // makeId( scope_type, scope_id, module, name ) -- four arguments, the same
    // derivation Update022 uses, so this row is indistinguishable from one an
    // actual 1.x install carried across.
    $setting->set( 'id', $setting->makeId( 'profile', $site['site_id'], 'base', 'goals' ) );
    $setting->set( 'scope_type', 'profile' );
    $setting->set( 'scope_id', $site['site_id'] );
    $setting->set( 'module', 'base' );
    $setting->set( 'name', 'goals' );
    $setting->set( 'value', serialize( owa_upgrade_cycle_goal_blob() ) );
    $setting->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

    if ( ! $setting->create() ) {

        // Already there on a re-run: make sure it holds THIS blob.
        $db->query( sprintf( "UPDATE owa_setting SET value = '%s'
                               WHERE scope_type = 'profile' AND scope_id = '%s' AND name = 'goals'",
            $db->prepare( serialize( owa_upgrade_cycle_goal_blob() ) ), $site['site_id'] ) );
    }

    return array( 'profile' => $site['site_id'], 'property' => $site['property_id'] );
}

/**
 * What the migration must have produced.
 *
 * @param array $seeded what owa_upgrade_cycle_seed() attached to
 * @return array<string> failures, empty when the data came across intact
 */
function owa_upgrade_cycle_expect( $seeded ) {

    $db   = \OWA\Core\CoreAPI::dbSingleton();
    $fail = array();

    $expected = owa_upgrade_cycle_expected_goals();

    $rows = (array) $db->get_results(
        'SELECT id, property_id, name, goal_number, goal_group, is_active, value,
                trigger_event_type FROM owa_goal_event' );

    $events = array();

    foreach ( $rows as $r ) {

        $r = (array) $r;

        $events[ $r['name'] ] = $r;
    }

    /*
     * THE STUBS. Eleven of the fifteen slots are empty, and carrying them over
     * would reproduce the exact thing the table exists to stop.
     */
    if ( count( $rows ) !== count( $expected ) ) {

        $fail[] = sprintf(
            "The migration produced %d goal event(s) from %d real goals in a %d-slot blob.\n"
          . "  Got: %s\n"
          . '  Eleven of those slots are empty stubs and must not become rows.',
            count( $rows ), count( $expected ), count( owa_upgrade_cycle_goal_blob() ),
            $rows ? implode( ', ', array_keys( $events ) ) : '(nothing)' );
    }

    foreach ( $expected as $name => $want ) {

        list( $number, $active, $cents, $operator, $url ) = $want;

        if ( ! isset( $events[ $name ] ) ) {

            $fail[] = sprintf( 'The goal "%s" did not survive the migration at all.', $name );

            continue;
        }

        $got = $events[ $name ];

        if ( (int) $got['goal_number'] !== $number ) {

            $fail[] = sprintf( '"%s" migrated as goal_number %s, expected %d%s.',
                $name, var_export( $got['goal_number'], true ), $number,
                $number === 4 ? ' (the slot key, because the goal carried no number)' : '' );
        }

        if ( (int) $got['is_active'] !== $active ) {

            $fail[] = sprintf(
                "\"%s\" migrated as is_active=%s, expected %d.\n"
              . '  A goal somebody switched off must not come back enabled.',
                $name, var_export( $got['is_active'], true ), $active );
        }

        if ( (int) $got['value'] !== $cents ) {

            $fail[] = sprintf(
                "\"%s\" migrated with value %s, expected %d cents.\n"
              . '  1.x stored money as free-form text; this is the field that can fail to convert.',
                $name, var_export( $got['value'], true ), $cents );
        }

        /*
         * THE HOP. Goals were per site; goal events are per Property. A
         * migration that carried site_id across unchanged would look right in
         * every other respect and attach every goal to nothing.
         */
        if ( (string) $got['property_id'] !== (string) $seeded['property'] ) {

            $fail[] = sprintf(
                "\"%s\" points at property_id %s, expected %s.\n"
              . '  Goals were per Profile and goal events are per Property; that hop is the migration.',
                $name, var_export( $got['property_id'], true ), var_export( $seeded['property'], true ) );
        }

        $conds = (array) $db->get_results( sprintf(
            "SELECT condition_property, condition_operator, condition_value
               FROM owa_goal_event_condition WHERE goal_event_id = '%s'",
            $db->prepare( $got['id'] ) ) );

        if ( count( $conds ) !== 1 ) {

            $fail[] = sprintf(
                '"%s" has %d condition rows; a 1.x goal had exactly one (one URL, one match type).',
                $name, count( $conds ) );

            continue;
        }

        $c = (array) $conds[0];

        if ( $c['condition_operator'] !== $operator || $c['condition_value'] !== $url ) {

            $fail[] = sprintf(
                "\"%s\" migrated its condition as %s %s %s, expected page_uri %s %s.",
                $name, $c['condition_property'], $c['condition_operator'],
                var_export( $c['condition_value'], true ), $operator, var_export( $url, true ) );
        }
    }

    /* No blank conditions from the blank slots, by any route. */
    $empty = (array) $db->get_results(
        "SELECT COUNT(*) AS n FROM owa_goal_event_condition
          WHERE condition_value IS NULL OR condition_value = ''
             OR condition_property IS NULL OR condition_property = ''" );

    if ( $empty && (int) ( (array) $empty[0] )['n'] !== 0 ) {

        $fail[] = sprintf( '%s empty condition row(s) exist. The blank slots became rows.',
            ( (array) $empty[0] )['n'] );
    }

    return $fail;
}
