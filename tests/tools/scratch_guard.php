<?php
/**
 * "Is it safe to wreck this database?"
 *
 * Two tools here rewind an installed schema so that a migration can be run for
 * real -- seed_pre_hierarchy.php and upgrade_cycle.php. Both drop tables and
 * columns, and both are one mistyped command away from doing it to a dev
 * install or, worse, a production one. The check lives in one place so the two
 * cannot drift apart, and so a third tool gets it by asking rather than by
 * remembering.
 *
 * The test is a NAME, not a row count: an empty-looking database can still be
 * the one a site is pointed at, and a scratch database that happens to hold
 * fixtures is still scratch. The harnesses that provision these all name them
 * so this passes -- owa_e2e_installcli_scratch, owa_e2e_selfhost, owa_test_*.
 */

if ( ! function_exists( 'owa_looks_like_scratch_db' ) ) {

    /**
     * @param string $dbName the database the install is actually connected to
     */
    function owa_looks_like_scratch_db( $dbName ) {

        foreach ( array( 'scratch', 'owa_test', 'selfhost', 'tmp' ) as $marker ) {

            if ( stripos( (string) $dbName, $marker ) !== false ) {

                return true;
            }
        }

        return false;
    }

    /**
     * Refuses, loudly and with an exit code, unless the caller insisted.
     *
     * --force exists because a maintainer with an install they genuinely do not
     * care about should not have to rename a database to use these. It is not a
     * flag to reach for when the refusal is inconvenient: the refusal is the
     * feature.
     *
     * @param string $dbName
     * @param bool   $force  the caller saw --force on the command line
     */
    function owa_upgrade_cycle_guard( $dbName, $force ) {

        if ( owa_looks_like_scratch_db( $dbName ) || $force ) {

            return;
        }

        fwrite( STDERR,
            "refusing: '$dbName' does not look like a scratch database.\n"
          . "This rewinds the schema, which drops tables and columns and the data in\n"
          . "them. Provision one with tests/tools/upgrade_cycle_run.sh, or pass --force\n"
          . "if you are certain -- never against an install with data you want.\n" );

        exit( 2 );
    }
}
