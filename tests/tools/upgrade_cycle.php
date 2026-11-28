<?php
/**
 * Runs the schema updates. Actually runs them, against a database that has a
 * past.
 *
 * WHY THIS EXISTS
 * ---------------
 * Nothing else in this repository ever upgrades a database. Module::install()
 * creates every table from the CURRENT entity definitions and then stamps
 * schema_version at the latest, and that is how every test environment is
 * provisioned -- the dev install, the scratch install, the isolation sweep, the
 * self-hosted e2e harness. applyUpdates() walks `$seq > $current_schema_version`,
 * which is never true. The update path is dead code under test.
 *
 * The ambiguities this finds are values that mean two things, which can only
 * be told apart against a database that already has some history:
 *
 *   - addColumn() answers false for "could not" AND for "already there". On a
 *     fresh table the column is never already there, so the ambiguity reads as
 *     success.
 *   - a column definition that is valid inside CREATE TABLE and invalid inside
 *     ALTER TABLE is only ever exercised by createTable() on a fresh install.
 *
 * A fresh database has no history, so both ambiguities collapse to the harmless
 * reading. This makes a database with a history.
 *
 * HOW
 * ---
 * Rather than install an old release and upgrade it -- which needs old code to
 * run on a modern PHP, and dates badly -- it uses the rollbacks:
 *
 *   1. fingerprint the schema as installed
 *   2. roll DOWN one update at a time, as far as the down()s allow
 *   3. roll back UP through the real Module::update() path
 *   4. fingerprint again: it must MATCH
 *   5. re-apply every update that was rolled, forced, on the schema it already
 *      built -- which is the "already there" case, and must succeed
 *
 * Step 4 is the one that earns its keep. A column can come back without its
 * index and every other check here still passes; the fingerprint notices,
 * because an index that only new installs have is exactly the divergence nobody
 * finds until a report goes slow.
 *
 * Coverage grows on its own. The floor is wherever the rollbacks stop being
 * honest, so every update written under the "idempotent up AND down" rule is
 * swept the day it lands, with no list here to update.
 *
 * DESTRUCTIVE. It rolls the schema back, which drops tables and columns and the
 * data in them. It refuses to run against a database that does not look like a
 * scratch one; --force overrides, and should not be used.
 *
 * RUN IN PHASES, in separate processes, because the middle one has to be the
 * REAL command. `php cli.php cmd=core-update-schema` is what an operator types;
 * reimplementing the walk here would be testing this
 * file's idea of an upgrade instead of the one that ships. Separate processes
 * also mean the verify phase reads the schema version off a cold boot rather
 * than out of the config cache that just wrote it.
 *
 * THE SECOND PASS: TABLES CREATED FIRST
 * --------------------------------------
 * cmd=core-install on an installed system creates every missing table from the
 * current entities (Module::createMissingTables()) and then applies the pending
 * updates, so an update can find its table already there. Each update has to
 * give the same result either way. After the first pass:
 *
 *   6. snapshot every owa_ table: SHOW CREATE TABLE (AUTO_INCREMENT values
 *      removed), its row count, and a hash of each row
 *   7. rewind again, the same way, without the per-update measurements
 *   8. create the missing tables, as cmd=core-install does, recording no
 *      schema version
 *   9. upgrade with cli.php cmd=core-update-schema
 *  10. snapshot again: it must MATCH step 6, table for table and row for row
 *
 * The down phase seeds a goal event definition and its condition first, so the
 * tables Update076 renames have rows to lose. Columns named for a time (_at,
 * _date, _time, _timestamp) are not compared: an update that deletes a row and
 * puts it back stamps it again.
 *
 * Usage:  bash tests/tools/upgrade_cycle_run.sh      <- provisions, runs both passes
 *
 *         php tests/tools/upgrade_cycle.php down   [--force] [--verbose]
 *         php cli.php cmd=core-update-schema
 *         php tests/tools/upgrade_cycle.php verify [--force] [--verbose]
 *
 *         php tests/tools/upgrade_cycle.php snapshot       [--force]
 *         php tests/tools/upgrade_cycle.php rewind         [--force]
 *         php tests/tools/upgrade_cycle.php create-missing [--force]
 *         php cli.php cmd=core-update-schema
 *         php tests/tools/upgrade_cycle.php compare        [--force]
 */

require_once dirname( __DIR__, 2 ) . '/owa.php';

$owa = new owa( [ 'request_type' => 'cli' ] );

$phase   = isset( $argv[1] ) && $argv[1][0] !== '-' ? $argv[1] : '';
$force   = in_array( '--force', $argv, true );
$verbose = in_array( '--verbose', $argv, true );

if ( ! in_array( $phase, [ 'down', 'verify', 'snapshot', 'rewind', 'create-missing', 'compare' ], true ) ) {

    fwrite( STDERR, "usage: upgrade_cycle.php down|verify|snapshot|rewind|create-missing|compare [--force] [--verbose]\n"
        . "(or run tests/tools/upgrade_cycle_run.sh, which drives both passes)\n" );

    exit( 2 );
}

/**
 * What the down phase hands to the verify phase.
 *
 * A file rather than a return value because `php cli.php cmd=core-update-schema` runs
 * between them, in its own process, deliberately.
 */
define( 'OWA_UPGRADE_CYCLE_STATE', sys_get_temp_dir() . '/owa_upgrade_cycle.json' );

/** The snapshot phase's record of the first pass's end state, which the compare phase reads. */
define( 'OWA_UPGRADE_CYCLE_SNAPSHOT', sys_get_temp_dir() . '/owa_upgrade_cycle_snapshot.json' );

$db     = \OWA\Core\CoreAPI::dbSingleton();
$dbName = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'db_name' );

require_once __DIR__ . '/scratch_guard.php';

owa_upgrade_cycle_guard( $dbName, $force );

/**
 * The LOWEST update this is expected to reach.
 *
 * Not a target -- the sweep rolls back as far as the down()s allow and this
 * only fails the run when that gets WORSE. Coverage shrinking is silent
 * otherwise: a new update written with `down() { return false; }` satisfies the
 * guard test, raises the floor, and quietly removes every update below it from
 * this sweep. v2's chain starts at 34 -- an installation upgrades from 1.14.0,
 * schema 33 -- so 33 is the floor.
 */
const OWA_UPGRADE_CYCLE_FLOOR = 33;

/** The Property whose cube the cycle creates when the install has none. */
const OWA_UPGRADE_CYCLE_PROPERTY = 7787000000000001;

/** The goal event definition the down phase seeds, and its condition. */
const OWA_UPGRADE_CYCLE_GOAL      = '7787000000000002';
const OWA_UPGRADE_CYCLE_CONDITION = '7787000000000003';

/** Rows hashed one by one, per table, so a difference names its row. A larger table is compared by count and checksum. */
const OWA_UPGRADE_CYCLE_ROWS = 5000;

$fail = [];
$note = static function ( $line ) { fwrite( STDOUT, $line . "\n" ); };

$fail = [];

/* ======================================================================
 * PHASE 1: down
 * ==================================================================== */

if ( $phase === 'down' ) {

    $installed = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' );

    $note( "--- schema as installed: $installed ---" );

    if ( $installed < OWA_UPGRADE_CYCLE_FLOOR + 1 ) {

        fwrite( STDERR, "refusing: schema $installed leaves nothing to roll back.\n" );
        exit( 2 );
    }

    /*
     * A CUBE, or no cube update runs on anything.
     *
     * Cubes are created only by a scheduled build, and a scratch install has
     * no Property that has seen data, so without this every CubeColumn loop
     * would walk zero tables, and a cube down() that drops with a plain DROP --
     * 1731 at the next swap -- would pass.
     */
    if ( ! \OWA\Module\Base\Classes\Cube\Cubes::allTables() ) {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->setProperties( [
            'id'            => OWA_UPGRADE_CYCLE_PROPERTY,
            'name'          => 'Upgrade cycle',
            'domain'        => 'example.test',
            'property_type' => \OWA\Module\Base\Entity\Property::TYPE_WEB,
            'creation_date' => time(),
        ] );

        if ( ! $property->create() || ! \OWA\Module\Base\Classes\Cube\Cubes::create( OWA_UPGRADE_CYCLE_PROPERTY ) ) {

            fwrite( STDERR, "refusing: could not create a cube for the cube updates to act on.\n" );
            exit( 2 );
        }

        $note( 'created ' . \OWA\Module\Base\Classes\Cube\Cubes::tableFor( OWA_UPGRADE_CYCLE_PROPERTY ) );
    }

    /*
     * Rows in the tables an update renames, so a pass that loses them shows.
     * Written in the current vocabulary, which every down() and up() after
     * it accepts.
     */
    if ( ! owa_upgrade_cycle_seed() ) {

        fwrite( STDERR, "refusing: could not seed a goal event definition for Update076 to move.\n" );
        exit( 2 );
    }

    $before = owa_schema_fingerprint( $db, $dbName );

    $note( sprintf( 'fingerprint: %d columns, %d indexes over %d tables',
        count( $before['columns'] ), count( $before['indexes'] ), count( $before['tables'] ) ) );

    $rolled = [];

    /*
     * What each down() actually CHANGED, per version.
     *
     * The end-to-end fingerprint compare at the bottom of phase 2 cannot see
     * this. It takes one reading before the rollback and one after the forward
     * re-apply, so a down() that returns true without touching anything leaves
     * the schema where it was, the forward pass finds nothing to do, and the
     * two readings match. A no-op down() passes a cycle that only checks the
     * ends.
     *
     * So each rollback is fingerprinted on both sides. Phase 2 does the same
     * for each up(), and the pair is what gets asserted: an up() that changes
     * the schema must have a down() that changes it back.
     */
    $down_changed = [];
    $up_changed   = [];

    for ( $v = $installed; $v >= 1; $v-- ) {

        $update = owa_update_for( $v );

        if ( ! $update ) {

            $note( "  $v: no update class, stopping" );
            break;
        }

        $was = owa_schema_fingerprint( $db, $dbName );

        if ( ! $update->rollback() ) {

            $note( "  $v: rollback refused or failed -- floor" );
            break;
        }

        $rolled_to = owa_schema_fingerprint( $db, $dbName );

        /*
         * Re-apply this one update, then roll it back again.
         *
         * Everything above $v is already rolled back and $v is the top, so
         * this is exactly the state a real upgrade meets when it reaches $v --
         * which makes it the one moment both directions can be measured
         * honestly.
         *
         * It also exercises up() twice over the run, so an update that is not
         * re-runnable shows up here rather than on somebody's install.
         */
        $reapplied = null;

        if ( $update->apply( true ) ) {

            $reapplied = owa_schema_fingerprint( $db, $dbName );

            if ( ! $update->rollback() ) {

                $note( "  $v: re-applied but would not roll back a second time -- floor" );
                break;
            }

        } else {

            $note( "  $v: would not re-apply on the schema it just rolled to" );
        }

        $down_changed[ $v ] = owa_fingerprint_diff( $was, $rolled_to ) ? true : false;
        $up_changed[ $v ]   = $reapplied === null
            ? null
            : ( owa_fingerprint_diff( $rolled_to, $reapplied ) ? true : false );

        $rolled[] = $v;

        $note( sprintf( '  %d: rolled back%s%s', $v,
            $down_changed[ $v ] ? '' : ' (down changed no schema)',
            $up_changed[ $v ]   ? ' (up changes schema)' : '' ) );
    }

    $floor = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' );

    $note( sprintf( '--- rolled back %d update(s), down to schema %d ---',
        count( $rolled ), $floor ) );

    file_put_contents( OWA_UPGRADE_CYCLE_STATE, json_encode( [
        'installed' => $installed,
        'floor'     => $floor,
        'rolled'    => $rolled,
        'before'    => $before,
        'down'      => $down_changed,
        'up'        => $up_changed,
    ] ) );

    if ( ! $rolled ) {

        fwrite( STDERR, "\nNothing rolled back at all, so nothing below was exercised.\n" );
        exit( 1 );
    }

    exit( 0 );
}

/* ======================================================================
 * THE SECOND PASS: snapshot, rewind, create-missing, then compare --
 * `php cli.php cmd=core-update-schema` runs between the last two
 * ==================================================================== */

if ( $phase === 'snapshot' ) {

    $snapshot = owa_state_snapshot( $db, $dbName );

    if ( file_put_contents( OWA_UPGRADE_CYCLE_SNAPSHOT, json_encode( $snapshot ) ) === false ) {

        fwrite( STDERR, 'could not write ' . OWA_UPGRADE_CYCLE_SNAPSHOT . "\n" );
        exit( 2 );
    }

    $note( sprintf( 'snapshot: %d tables, %d rows, at schema %d', count( $snapshot['tables'] ),
        array_sum( array_column( $snapshot['tables'], 'count' ) ), $snapshot['schema'] ) );

    exit( 0 );
}

if ( $phase === 'rewind' ) {

    $installed = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' );

    for ( $v = $installed; $v >= 1; $v-- ) {

        $update = owa_update_for( $v );

        if ( ! $update || ! $update->rollback() ) {

            break;
        }
    }

    $floor = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' );

    $note( sprintf( '--- rolled back from %d to schema %d ---', $installed, $floor ) );

    if ( $floor > OWA_UPGRADE_CYCLE_FLOOR ) {

        fwrite( STDERR, sprintf( "The second rewind stopped at schema %d, above %d: a down() refused or failed.\n",
            $floor, OWA_UPGRADE_CYCLE_FLOOR ) );
        exit( 1 );
    }

    exit( 0 );
}

if ( $phase === 'create-missing' ) {

    // What cmd=core-install runs before the updates: every active module's missing tables, no schema version recorded.
    $tables = ( new \OWA\Module\Base\Classes\InstallManager() )->createMissingTables();

    if ( $tables['problem'] !== null || $tables['failed'] ) {

        fwrite( STDERR, sprintf( "Creating the missing tables failed: %s\n",
            $tables['problem'] ?? implode( ', ', $tables['failed'] ) ) );
        exit( 1 );
    }

    $note( sprintf( '--- created %d missing table(s) at schema %d: %s ---', count( $tables['created'] ),
        (int) \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' ),
        $tables['created'] ? implode( ', ', $tables['created'] ) : 'none' ) );

    exit( 0 );
}

if ( $phase === 'compare' ) {

    $plain = @json_decode( (string) @file_get_contents( OWA_UPGRADE_CYCLE_SNAPSHOT ), true );

    if ( ! is_array( $plain ) || ! isset( $plain['tables'] ) ) {

        fwrite( STDERR, "no snapshot of the first pass -- run `upgrade_cycle.php snapshot` first.\n" );
        exit( 2 );
    }

    $differences = owa_state_diff( $plain, owa_state_snapshot( $db, $dbName ) );

    @unlink( OWA_UPGRADE_CYCLE_SNAPSHOT );

    if ( $differences ) {

        fwrite( STDERR, "\n=== the upgrade path is broken when the missing tables are created first ===\n\n" );
        fwrite( STDERR, "The upgrade after cmd=core-install's create-missing-tables step ended somewhere other\n"
            . "than the plain upgrade. An update depends on its table not existing yet.\n\n" );
        fwrite( STDERR, 'First difference: ' . $differences[0] . "\n" );

        if ( count( $differences ) > 1 ) {

            fwrite( STDERR, sprintf( "\n%d more:\n  %s\n", count( $differences ) - 1,
                implode( "\n  ", array_slice( $differences, 1, 20 ) ) ) );
        }

        exit( 1 );
    }

    $note( sprintf( "\nOK: with the missing tables created first, the upgrade ends where the plain one did:\n"
        . '%d tables, their definitions and their rows identical.', count( $plain['tables'] ) ) );

    exit( 0 );
}

/* ======================================================================
 * PHASE 2: verify -- `php cli.php cmd=core-update-schema` has run in between
 * ==================================================================== */

$state = @json_decode( (string) @file_get_contents( OWA_UPGRADE_CYCLE_STATE ), true );

if ( ! is_array( $state ) || ! isset( $state['before'] ) ) {

    fwrite( STDERR, "no state from the down phase -- run `upgrade_cycle.php down` first.\n" );
    exit( 2 );
}

$installed = (int) $state['installed'];
$floor     = (int) $state['floor'];
$rolled    = (array) $state['rolled'];
$before    = $state['before'];

// Read from a cold boot: this process did not write the version, so a value
// that only exists in someone's config cache cannot be mistaken for a
// persisted one.
$reached = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' );

$note( sprintf( '--- rolled to %d, upgrade reached %d, installed at %d ---',
    $floor, $reached, $installed ) );

if ( $floor > OWA_UPGRADE_CYCLE_FLOOR ) {

    $fail[] = sprintf(
        "The sweep now stops at schema %d; it used to reach %d.\n"
      . "  An update between %d and %d has a down() that refuses or fails, which removes\n"
      . "  every update below it from this sweep -- silently, because a refusal is a legal\n"
      . "  answer. Either give it a real rollback, or lower OWA_UPGRADE_CYCLE_FLOOR here\n"
      . "  and say in the commit what stopped being covered.",
        $floor, OWA_UPGRADE_CYCLE_FLOOR, OWA_UPGRADE_CYCLE_FLOOR, $floor );
}

/* ---- every schema change must be reversible ------------------------------
 *
 * The end-to-end compare below cannot see this. It reads the schema once before
 * the rollback and once after the upgrade, so an update whose down() returns
 * true without touching anything leaves the schema where it was, the upgrade
 * finds nothing to do, and the two readings agree. A no-op down() passes a
 * cycle that only checks the ends -- which is the whole reason a rollback is
 * worth testing separately from an upgrade.
 *
 * Phase 1 measured each update in both directions at the one moment both are
 * meaningful, so the pairing is available here: an up() that changes the schema
 * must have a down() that changes it back.
 *
 * A data-only update legitimately changes no schema in either direction and is
 * not caught by this, correctly -- one that rewrites column VALUES may have a
 * down() that is deliberately a no-op.
 */
foreach ( (array) $rolled as $v ) {

    if ( ! owa_update_declares_schema_work( $v ) ) {

        continue;
    }

    $up_changed   = $state['up'][ $v ]   ?? null;
    $down_changed = $state['down'][ $v ] ?? null;

    if ( $up_changed === true && $down_changed === true ) {

        continue;
    }

    if ( empty( $down_changed ) ) {

        $fail[] = sprintf(
            "Update%03d does schema work, but rolling it back changed nothing.\n"
          . "  The schema was byte-identical either side of down(), so the rollback is a\n"
          . "  no-op wearing a success. An install that reverts this release keeps the\n"
          . "  new shape while the reverted code expects the old one.\n"
          . "  Usually this means down() reads its column definitions off the entity --\n"
          . "  which describes the CURRENT schema, and so no longer holds what the older\n"
          . "  one needs. An update that drops a column has to carry the definitions\n"
          . "  itself; see Update046.",
            $v );

        continue;
    }

    if ( empty( $up_changed ) ) {

        $fail[] = sprintf(
            "Update%03d does schema work but re-applying it changed nothing.\n"
          . "  Its down() moved the schema and its up() did not put it back, so the two\n"
          . "  are not inverses. An install upgrading through this version does not get\n"
          . "  the shape a fresh install has.",
            $v );
    }
}

foreach ( (array) ( $state['up'] ?? [] ) as $v => $up_changed ) {

    if ( $up_changed === null ) {

        $fail[] = sprintf(
            "Update%03d would not re-apply on the schema its own down() produced.\n"
          . "  That is the exact state a real upgrade meets when it reaches this update,\n"
          . "  so an install rolling forward from below this version cannot get past it.",
            $v );
    }
}

if ( $reached !== $installed ) {

    $fail[] = sprintf(
        "The upgrade stopped at schema %d instead of %d.\n"
      . "  THIS IS THE LIVE FAILURE, REPRODUCED. Read the `cli.php cmd=core-update-schema` output\n"
      . "  above for the update that stopped and the statement it was refused on. An\n"
      . "  install left here refuses every request with \"OWA Updates required\".",
        $reached, $installed );
}

/* ---- the schema has to be the one we started with ------------------------- */

$after = owa_schema_fingerprint( $db, $dbName );

foreach ( owa_fingerprint_diff( $before, $after ) as $problem ) {

    $fail[] = $problem;
}

/* ---- and applying them again must not be a failure ------------------------ */

$note( '--- re-applying each rolled update against the schema it just built ---' );

foreach ( array_reverse( $rolled ) as $v ) {

    $update = owa_update_for( $v );

    if ( ! $update ) {

        continue;
    }

    // force=true skips the "already applied" abort so up() actually runs. Every
    // column it adds is now present, every table it creates exists: the exact
    // shape that stopped the live upgrade, and it must report success.
    if ( ! $update->apply( true ) ) {

        $fail[] = sprintf(
            "Update %d failed when re-applied to a schema that already has its changes.\n"
          . "  Not hypothetical: an install jumping several versions gets its tables built\n"
          . "  from the CURRENT entity definitions, so later updates routinely find their\n"
          . "  columns already there. Use addColumnIfMissing() rather than reading\n"
          . '  addColumn() === false as a failure.', $v );
    }
}

$note( '--- and once more, to prove that run changed nothing ---' );

$final = owa_schema_fingerprint( $db, $dbName );

foreach ( owa_fingerprint_diff( $after, $final, 're-application' ) as $problem ) {

    $fail[] = $problem;
}

/*
 * Every cube still takes a build.
 *
 * The fingerprint compares columns and indexes, and an instant column changes
 * neither -- it is row-format metadata, and EXCHANGE PARTITION refuses the
 * swap over it (1731). So each cube is asked, and then built.
 */
$note( '--- and every cube still swaps a build ---' );

foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

    if ( $db->hasInstantColumns( $table ) === true ) {

        $fail[] = sprintf( "%s has instant-column history after the cycle. Every build of it\n"
          . "  will fail with 1731 until ALTER TABLE %s FORCE. A cube update ALTERed it\n"
          . "  without rebuilding.", $table, $table );

        continue;
    }

    $property_id = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdFor( $table );

    if ( $property_id === '' ) {

        continue;
    }

    $builder = new \OWA\Module\Base\Classes\Cube\Builder( $property_id );
    $spans   = $builder->partitions( (int) date( 'Ymd' ), (int) date( 'Ymd' ) );
    $result  = $spans ? $builder->rebuild( $spans[0] ) : [ 'ok' => false, 'error' => 'no partition for today' ];

    if ( empty( $result['ok'] ) ) {

        $fail[] = sprintf( "A build of %s failed after the cycle: %s (the server said: %s)",
            $table, $result['error'], $db->lastQueryError() );
    }
}

/* ---- verdict -------------------------------------------------------------- */

@unlink( OWA_UPGRADE_CYCLE_STATE );

if ( $verbose ) {

    $note( "\nrolled and re-applied: " . implode( ', ', $rolled ) );
}

if ( $fail ) {

    fwrite( STDERR, "\n=== the upgrade path is broken ===\n\n" );

    foreach ( $fail as $i => $f ) {

        fwrite( STDERR, sprintf( "%d. %s\n\n", $i + 1, $f ) );
    }

    exit( 1 );
}

$note( sprintf( "\nOK: %d update(s) rolled back, re-applied by cli.php cmd=core-update-schema, and\n"
    . 'applied again on top of themselves. The schema is identical to the install.',
    count( $rolled ) ) );

exit( 0 );


/* ---- helpers -------------------------------------------------------------- */

/**
 * The update object for a sequence number, with its version set.
 *
 * Module::update() sets schema_version off the FILENAME rather than trusting
 * the class, and apply()/rollback() both refuse without it.
 */
function owa_update_for( $v ) {

    $class = 'Update' . str_pad( (string) $v, 3, '0', STR_PAD_LEFT );

    if ( ! class_exists( '\\OWA\\Module\\Base\\Update\\' . $class ) ) {

        return null;
    }

    $update = \OWA\Core\CoreAPI::updateFactory( 'base', $class );

    $update->schema_version = (int) $v;

    return $update;
}

/**
 * Does this update do SCHEMA work, as opposed to data work?
 *
 * Read off the source, because intent is not observable from the schema on a
 * fresh install. An update that DROPS a column drops nothing there -- the
 * column was never created, since the entity stopped declaring it -- so its
 * up() moves the fingerprint exactly as little as a broken down() does, and
 * the two are indistinguishable by measurement alone.
 *
 * The classifier is not the assertion. It only decides which updates are
 * expected to move the schema; whether they actually did is measured at
 * runtime in phase 1.
 *
 * @param int $v
 * @return bool
 */
function owa_update_declares_schema_work( $v ) {

    $file = sprintf( '%smodules/Base/Update/Update%03d.php', \OWA\Core\Env::root() . '/', $v );

    if ( ! is_readable( $file ) ) {

        return false;
    }

    $src = (string) file_get_contents( $file );

    foreach ( [ 'addColumnIfMissing', 'dropColumnIfPresent', 'addColumn',
                     'dropColumn', 'createTable', 'dropTable', 'addIndex',
                     'dropIndex' ] as $needle ) {

        if ( strpos( $src, $needle ) !== false ) {

            return true;
        }
    }

    return false;
}

/**
 * What the schema IS, in the terms that matter.
 *
 * Deliberately NOT a SHOW CREATE TABLE diff. Two things legitimately differ
 * between a table that was CREATEd complete and one an ALTER filled in, and
 * neither means anything:
 *
 *   - COLUMN ORDER. ALTER ... ADD puts the column last; CREATE TABLE puts it in
 *     declaration order. Same schema, different ordinal positions.
 *   - INDEX NAMES. createTable() writes `INDEX (col)`, which MySQL names after
 *     the column; addIndex() names it idx_col so a re-run is recognisable. An
 *     index's identity here is the columns it covers, not what it is called.
 *
 * Everything else is compared: which columns exist, their types, their
 * nullability, and which column sets are indexed.
 */
function owa_schema_fingerprint( $db, $dbName ) {

    $columns = [];
    $tables  = [];

    $rows = (array) $db->get_results( sprintf(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME LIKE 'owa\\_%%'", $dbName ) );

    foreach ( $rows as $r ) {

        $r = (array) $r;

        $tables[ $r['TABLE_NAME'] ] = true;

        $columns[] = sprintf( '%s.%s %s %s', $r['TABLE_NAME'], $r['COLUMN_NAME'],
            $r['COLUMN_TYPE'], $r['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL' );
    }

    $byIndex = [];

    $rows = (array) $db->get_results( sprintf(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME LIKE 'owa\\_%%'
          ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX", $dbName ) );

    foreach ( $rows as $r ) {

        $r = (array) $r;

        $key = $r['TABLE_NAME'] . '|' . $r['INDEX_NAME'];

        if ( ! isset( $byIndex[ $key ] ) ) {

            $byIndex[ $key ] = [
                'table'  => $r['TABLE_NAME'],
                'unique' => ! (int) $r['NON_UNIQUE'],
                'cols'   => [],
            ];
        }

        $byIndex[ $key ]['cols'][] = $r['COLUMN_NAME'];
    }

    // Named by their column set, so the same index found under two names is one
    // index -- and a DUPLICATE of an existing one collapses onto it rather than
    // reading as a difference.
    $indexes = [];

    foreach ( $byIndex as $ix ) {

        $indexes[] = sprintf( '%s (%s)%s', $ix['table'],
            implode( ', ', $ix['cols'] ), $ix['unique'] ? ' UNIQUE' : '' );
    }

    sort( $columns );

    $indexes = array_values( array_unique( $indexes ) );

    sort( $indexes );

    ksort( $tables );

    return [ 'columns' => $columns, 'indexes' => $indexes,
                  'tables'  => array_keys( $tables ) ];
}

/** @return array<string> human-readable differences, empty when identical */
function owa_fingerprint_diff( $before, $after, $what = 'a down-and-up cycle' ) {

    $out = [];

    $pairs = [
        'table'  => [ $before['tables'],  $after['tables'] ],
        'column' => [ $before['columns'], $after['columns'] ],
        'index'  => [ $before['indexes'], $after['indexes'] ],
    ];

    foreach ( $pairs as $kind => $pair ) {

        list( $was, $is ) = $pair;

        $lost   = array_values( array_diff( $was, $is ) );
        $gained = array_values( array_diff( $is, $was ) );

        if ( $lost ) {

            $out[] = sprintf(
                "%d %s(s) did not survive %s:\n    %s\n"
              . "  The update that rebuilt this does not put back everything it took away.%s",
                count( $lost ), $kind, $what, implode( "\n    ", array_slice( $lost, 0, 20 ) ),
                $kind === 'index'
                    ? "\n  An index only fresh installs have is the divergence nobody finds\n"
                    . '  until a report goes slow.' : '' );
        }

        if ( $gained ) {

            $out[] = sprintf( "%d %s(s) appeared that the install did not have:\n    %s",
                count( $gained ), $kind, implode( "\n    ", array_slice( $gained, 0, 20 ) ) );
        }
    }

    return $out;
}

/**
 * A goal event definition and its condition, under the names they have now,
 * unless they are there already.
 *
 * @return bool
 */
function owa_upgrade_cycle_seed() {

    $definition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition' );
    $definition->load( OWA_UPGRADE_CYCLE_GOAL );

    if ( $definition->wasPersisted() ) {

        return true;
    }

    $definition->setProperties( [
        'id'                 => OWA_UPGRADE_CYCLE_GOAL,
        'property_id'        => OWA_UPGRADE_CYCLE_PROPERTY,
        'name'               => 'upgrade_cycle_signup',
        'trigger_event_type' => 'page_view',
        'count_mode'         => \OWA\Module\Base\Entity\GoalEventDefinition::COUNT_PER_SESSION,
        'condition_match'    => 'all',
        'is_active'          => 1,
        'creation_date'      => time(),
    ] );

    $condition = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event_definition_condition' );
    $condition->setProperties( [
        'id'                       => OWA_UPGRADE_CYCLE_CONDITION,
        'goal_event_definition_id' => OWA_UPGRADE_CYCLE_GOAL,
        'sort_order'               => 1,
        'condition_property'       => 'page_path',
        'condition_operator'       => 'exact',
        'condition_value'          => '/signup/done',
        'creation_date'            => time(),
    ] );

    return (bool) $definition->create() && (bool) $condition->create();
}

/**
 * Whether a column is named for a time, and so is not compared: an update that
 * deletes a row and puts it back stamps it again.
 *
 * @param  string $column
 * @return bool
 */
function owa_state_column_is_time( $column ) {

    return (bool) preg_match( '/(^|_)(at|date|time|timestamp)$/', (string) $column );
}

/**
 * Every owa_ table as it is: SHOW CREATE TABLE with its AUTO_INCREMENT value
 * removed, its row count, a checksum of its rows, and each row's hash by
 * primary key while the table holds at most OWA_UPGRADE_CYCLE_ROWS.
 *
 * @return array{schema: int, tables: array<string, array{create: string, count: int, checksum: string, rows: array<string, array{h: string, c: array<string, string>, p: array<string, string>}>|null}>}
 */
function owa_state_snapshot( $db, $dbName ) {

    $out = [ 'schema' => (int) \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' ), 'tables' => [] ];

    $names = (array) $db->get_results( sprintf(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME LIKE 'owa\\_%%' ORDER BY TABLE_NAME", $dbName ) );

    foreach ( $names as $r ) {

        $table  = (string) ( (array) $r )['TABLE_NAME'];
        $create = array_values( (array) $db->get_row( sprintf( 'SHOW CREATE TABLE `%s`', $table ) ) )[1] ?? '';

        $pk = array_map( static fn ( $k ) => (string) ( (array) $k )['COLUMN_NAME'], (array) $db->get_results( sprintf(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME = '%s' AND INDEX_NAME = 'PRIMARY'
              ORDER BY SEQ_IN_INDEX", $dbName, $table ) ) );

        $rows = [];

        foreach ( (array) $db->get_results( sprintf( 'SELECT * FROM `%s`', $table ) ) as $row ) {

            $row = (array) $row;

            foreach ( array_keys( $row ) as $column ) {

                if ( owa_state_column_is_time( $column ) ) {

                    unset( $row[ $column ] );
                }
            }

            ksort( $row );

            $hashes   = array_map( static fn ( $v ) => md5( serialize( $v ) ), $row );
            $previews = array_map( static fn ( $v ) => $v === null ? 'NULL'
                : var_export( mb_strimwidth( mb_scrub( (string) $v, 'UTF-8' ), 0, 80, '...' ), true ), $row );

            $key = $pk
                ? implode( '|', array_map( static fn ( $c ) => (string) ( $row[ $c ] ?? '' ), $pk ) )
                : md5( serialize( $row ) );

            $rows[ $key ] = [ 'h' => md5( serialize( $hashes ) ), 'c' => $hashes, 'p' => $previews ];
        }

        ksort( $rows, SORT_STRING );

        $out['tables'][ $table ] = [
            'create'   => (string) preg_replace( '/ AUTO_INCREMENT=\d+/', '', (string) $create ),
            'count'    => count( $rows ),
            'checksum' => md5( serialize( array_column( $rows, 'h' ) ) . serialize( array_keys( $rows ) ) ),
            'rows'     => count( $rows ) <= OWA_UPGRADE_CYCLE_ROWS ? $rows : null,
        ];
    }

    return $out;
}

/**
 * How two snapshots differ, table by table in name order: the plain pass's
 * ($plain) against the pass with the missing tables created first ($first).
 *
 * @return list<string> one line or block per difference, the first difference first
 */
function owa_state_diff( array $plain, array $first ) {

    $out = [];

    if ( (int) $plain['schema'] !== (int) $first['schema'] ) {

        $out[] = sprintf( 'the schema version is %d after the plain upgrade and %d with the tables created first.',
            $plain['schema'], $first['schema'] );
    }

    $names = array_unique( array_merge( array_keys( $plain['tables'] ), array_keys( $first['tables'] ) ) );
    sort( $names );

    foreach ( $names as $table ) {

        $a = $plain['tables'][ $table ] ?? null;
        $b = $first['tables'][ $table ] ?? null;

        if ( $a === null || $b === null ) {

            $out[] = sprintf( '%s exists only %s.', $table,
                $a === null ? 'with the tables created first' : 'after the plain upgrade' );

            continue;
        }

        if ( $a['create'] !== $b['create'] ) {

            $was = explode( "\n", $a['create'] );
            $is  = explode( "\n", $b['create'] );
            $at  = 0;

            while ( $at < max( count( $was ), count( $is ) ) && ( $was[ $at ] ?? null ) === ( $is[ $at ] ?? null ) ) {

                $at++;
            }

            $out[] = sprintf( "%s: SHOW CREATE TABLE differs at line %d.\n    plain upgrade:        %s\n    tables created first: %s",
                $table, $at + 1, trim( $was[ $at ] ?? '(no line)' ), trim( $is[ $at ] ?? '(no line)' ) );
        }

        if ( $a['count'] !== $b['count'] || $a['checksum'] !== $b['checksum'] ) {

            $out[] = sprintf( '%s: %d rows after the plain upgrade, %d with the tables created first%s',
                $table, $a['count'], $b['count'], owa_state_row_diff( $a['rows'], $b['rows'] ) );
        }
    }

    return $out;
}

/**
 * The first row two tables' rows differ in, said: a row only one has, or the
 * columns that differ, with their values. Empty when either was too large to
 * keep its rows.
 *
 * @param  array<string, array{h: string, c: array<string, string>, p: array<string, string>}>|null $a
 * @param  array<string, array{h: string, c: array<string, string>, p: array<string, string>}>|null $b
 * @return string
 */
function owa_state_row_diff( $a, $b ) {

    if ( $a === null || $b === null ) {

        return '; the rows differ (too many to compare one by one).';
    }

    foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $key ) {

        if ( ! isset( $a[ $key ] ) || ! isset( $b[ $key ] ) ) {

            $row = $a[ $key ] ?? $b[ $key ];

            return sprintf( ".\n    row %s is only %s: %s", $key,
                isset( $a[ $key ] ) ? 'after the plain upgrade' : 'with the tables created first',
                implode( ', ', array_map( static fn ( $c, $v ) => "$c=$v", array_keys( $row['p'] ), $row['p'] ) ) );
        }

        if ( $a[ $key ]['h'] === $b[ $key ]['h'] ) {

            continue;
        }

        $columns = [];

        foreach ( array_unique( array_merge( array_keys( $a[ $key ]['c'] ), array_keys( $b[ $key ]['c'] ) ) ) as $c ) {

            if ( ( $a[ $key ]['c'][ $c ] ?? null ) !== ( $b[ $key ]['c'][ $c ] ?? null ) ) {

                $columns[] = sprintf( '%s: %s, not %s', $c, $b[ $key ]['p'][ $c ] ?? '(no column)', $a[ $key ]['p'][ $c ] ?? '(no column)' );
            }
        }

        return sprintf( ".\n    row %s differs with the tables created first: %s", $key, implode( '; ', $columns ) );
    }

    return '.';
}
