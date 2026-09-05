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
 * Two production failures came out of that hole in one week, and they are the
 * same bug wearing different clothes: a value that means two things, which can
 * only be told apart against a database that already has some history.
 *
 *   - addColumn() answers false for "could not" AND for "already there". On a
 *     fresh table the column is never already there, so the ambiguity reads as
 *     success. Update023 stopped a live upgrade dead.
 *   - DbColumn::getDefinition() returned a string valid inside CREATE TABLE and
 *     invalid inside ALTER TABLE. createTable() was its only caller in any test,
 *     so it was only ever used in the context that made it correct. Update026
 *     stopped the same upgrade one update later.
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
 * REAL command. `php cli.php cmd=update` is what an operator types and what
 * failed on the live site; reimplementing the walk here would be testing this
 * file's idea of an upgrade instead of the one that ships. Separate processes
 * also mean the verify phase reads the schema version off a cold boot rather
 * than out of the config cache that just wrote it.
 *
 * Usage:  bash tests/tools/upgrade_cycle_run.sh      <- provisions, runs all three
 *
 *         php tests/tools/upgrade_cycle.php down   [--force] [--verbose]
 *         php cli.php cmd=update
 *         php tests/tools/upgrade_cycle.php verify [--force] [--verbose]
 */

require_once dirname( __DIR__, 2 ) . '/owa.php';

$owa = new owa( array( 'instance_role' => 'cli' ) );

$phase   = isset( $argv[1] ) && $argv[1][0] !== '-' ? $argv[1] : '';
$force   = in_array( '--force', $argv, true );
$verbose = in_array( '--verbose', $argv, true );

if ( ! in_array( $phase, array( 'down', 'verify' ), true ) ) {

    fwrite( STDERR, "usage: upgrade_cycle.php down|verify [--force] [--verbose]\n"
        . "(or run tests/tools/upgrade_cycle_run.sh, which drives all three steps)\n" );

    exit( 2 );
}

/**
 * What the down phase hands to the verify phase.
 *
 * A file rather than a return value because `php cli.php cmd=update` runs
 * between them, in its own process, deliberately.
 */
define( 'OWA_UPGRADE_CYCLE_STATE', sys_get_temp_dir() . '/owa_upgrade_cycle.json' );

$db     = owa_coreAPI::dbSingleton();
$dbName = (string) owa_coreAPI::getSetting( 'base', 'db_name' );

require_once __DIR__ . '/scratch_guard.php';
require_once __DIR__ . '/upgrade_cycle_fixture.php';

owa_upgrade_cycle_guard( $dbName, $force );

/**
 * The LOWEST update this is expected to reach.
 *
 * Not a target -- the sweep rolls back as far as the down()s allow and this
 * only fails the run when that gets WORSE. Coverage shrinking is silent
 * otherwise: a new update written with `down() { return false; }` satisfies the
 * guard test, raises the floor, and quietly removes every update below it from
 * this sweep. Update022 and below refuse by design (020 rewrites every site's
 * name; there is no honest undo), so 22 is the floor today.
 */
const OWA_UPGRADE_CYCLE_FLOOR = 22;

$fail = array();
$note = static function ( $line ) { fwrite( STDOUT, $line . "\n" ); };

$fail = array();

/* ======================================================================
 * PHASE 1: down
 * ==================================================================== */

if ( $phase === 'down' ) {

    $installed = (int) owa_coreAPI::getSetting( 'base', 'schema_version' );

    $note( "--- schema as installed: $installed ---" );

    if ( $installed < OWA_UPGRADE_CYCLE_FLOOR + 1 ) {

        fwrite( STDERR, "refusing: schema $installed leaves nothing to roll back.\n" );
        exit( 2 );
    }

    /*
     * The legacy DATA, before anything is rewound.
     *
     * Without it the cycle round-trips an empty database and every data
     * migration runs against nothing -- which is how Update025's read path
     * stayed unexecuted by any test while reporting "Migrated 0 goal(s)"
     * as though that were a result.
     */
    $seeded = owa_upgrade_cycle_seed();

    $note( sprintf( 'seeded 1.x goals on profile %s (property %s)',
        $seeded['profile'], $seeded['property'] ) );

    $before = owa_schema_fingerprint( $db, $dbName );

    $note( sprintf( 'fingerprint: %d columns, %d indexes over %d tables',
        count( $before['columns'] ), count( $before['indexes'] ), count( $before['tables'] ) ) );

    $rolled = array();

    for ( $v = $installed; $v >= 1; $v-- ) {

        $update = owa_update_for( $v );

        if ( ! $update ) {

            $note( "  $v: no update class, stopping" );
            break;
        }

        if ( ! $update->rollback() ) {

            $note( "  $v: rollback refused or failed -- floor" );
            break;
        }

        $rolled[] = $v;

        $note( "  $v: rolled back" );
    }

    $floor = (int) owa_coreAPI::getSetting( 'base', 'schema_version' );

    $note( sprintf( '--- rolled back %d update(s), down to schema %d ---',
        count( $rolled ), $floor ) );

    file_put_contents( OWA_UPGRADE_CYCLE_STATE, json_encode( array(
        'installed' => $installed,
        'floor'     => $floor,
        'rolled'    => $rolled,
        'before'    => $before,
        'seeded'    => $seeded,
    ) ) );

    if ( ! $rolled ) {

        fwrite( STDERR, "\nNothing rolled back at all, so nothing below was exercised.\n" );
        exit( 1 );
    }

    exit( 0 );
}

/* ======================================================================
 * PHASE 2: verify -- `php cli.php cmd=update` has run in between
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
$seeded    = (array) ( $state['seeded'] ?? array() );

// Read from a cold boot: this process did not write the version, so a value
// that only exists in someone's config cache cannot be mistaken for a
// persisted one.
$reached = (int) owa_coreAPI::getSetting( 'base', 'schema_version' );

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

if ( $reached !== $installed ) {

    $fail[] = sprintf(
        "The upgrade stopped at schema %d instead of %d.\n"
      . "  THIS IS THE LIVE FAILURE, REPRODUCED. Read the `cli.php cmd=update` output\n"
      . "  above for the update that stopped and the statement it was refused on. An\n"
      . "  install left here refuses every request with \"OWA Updates required\".",
        $reached, $installed );
}

/* ---- the schema has to be the one we started with ------------------------- */

$after = owa_schema_fingerprint( $db, $dbName );

foreach ( owa_fingerprint_diff( $before, $after ) as $problem ) {

    $fail[] = $problem;
}

/* ---- the DATA has to have come across too ---------------------------------- */

$note( '--- checking what the data migrations produced ---' );

foreach ( owa_upgrade_cycle_expect( $seeded ) as $problem ) {

    $fail[] = "A DATA migration lost or mangled what it was carrying across.\n  " . $problem;
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
 * And the DATA is still exactly what it was.
 *
 * A migration re-run must UPDATE the rows it made, not make them again --
 * which is what the content-derived ids in Update025 are for. This is the
 * assertion that notices when that derivation stops being deterministic:
 * the count doubles and nothing else changes.
 */
foreach ( owa_upgrade_cycle_expect( $seeded ) as $problem ) {

    $fail[] = "Re-running the migrations changed the migrated data.\n  " . $problem;
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

$note( sprintf( "\nOK: %d update(s) rolled back, re-applied by cli.php cmd=update, and\n"
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

    $update = owa_coreAPI::updateFactory( 'base', $class );

    $update->schema_version = (int) $v;

    return $update;
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

    $columns = array();
    $tables  = array();

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

    $byIndex = array();

    $rows = (array) $db->get_results( sprintf(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME LIKE 'owa\\_%%'
          ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX", $dbName ) );

    foreach ( $rows as $r ) {

        $r = (array) $r;

        $key = $r['TABLE_NAME'] . '|' . $r['INDEX_NAME'];

        if ( ! isset( $byIndex[ $key ] ) ) {

            $byIndex[ $key ] = array(
                'table'  => $r['TABLE_NAME'],
                'unique' => ! (int) $r['NON_UNIQUE'],
                'cols'   => array(),
            );
        }

        $byIndex[ $key ]['cols'][] = $r['COLUMN_NAME'];
    }

    // Named by their column set, so the same index found under two names is one
    // index -- and a DUPLICATE of an existing one collapses onto it rather than
    // reading as a difference.
    $indexes = array();

    foreach ( $byIndex as $ix ) {

        $indexes[] = sprintf( '%s (%s)%s', $ix['table'],
            implode( ', ', $ix['cols'] ), $ix['unique'] ? ' UNIQUE' : '' );
    }

    sort( $columns );

    $indexes = array_values( array_unique( $indexes ) );

    sort( $indexes );

    ksort( $tables );

    return array( 'columns' => $columns, 'indexes' => $indexes,
                  'tables'  => array_keys( $tables ) );
}

/** @return array<string> human-readable differences, empty when identical */
function owa_fingerprint_diff( $before, $after, $what = 'a down-and-up cycle' ) {

    $out = array();

    $pairs = array(
        'table'  => array( $before['tables'],  $after['tables'] ),
        'column' => array( $before['columns'], $after['columns'] ),
        'index'  => array( $before['indexes'], $after['indexes'] ),
    );

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
