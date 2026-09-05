<?php

use PHPUnit\Framework\TestCase;

/**
 * A migration must not treat "the column is already there" as a failure.
 *
 * THE BUG THIS EXISTS FOR, WHICH WAS FOUND IN PRODUCTION
 *
 * addColumn() answers FALSE both for "could not" and for "already exists". A
 * migration that stops on the second never writes its schema version, so every
 * request afterwards refuses with "OWA Updates required" -- on a database that
 * is already correct.
 *
 * That is not a re-run guard and it is not hypothetical. It is the ORDINARY
 * case for an install jumping several versions at once: a table created by an
 * earlier migration is built from the CURRENT entity definition, which already
 * carries every column later migrations add. An installation at schema 20 ran
 * Update021, which created owa_property complete with the archived_date that
 * Update023 then tried to add -- and the upgrade stopped dead at 23, mid-way,
 * on a live site.
 *
 * Update::addColumnIfMissing() is the one place that gets this right. This test
 * makes sure the next migration uses it rather than rediscovering the trap.
 */
final class UpdateAddColumnGuardTest extends TestCase
{
    /**
     * The install root, resolved from this file rather than from OWA_DIR.
     *
     * A data provider runs BEFORE any bootstrap, so the constant does not exist
     * yet -- and a provider that throws reports as "the data provider is
     * invalid", which says nothing about what is wrong.
     */
    private static function root(): string
    {
        return dirname( __DIR__ ) . '/';
    }

    /** @return array<string, array{0:string,1:string}> path => [path, source] */
    public static function updateFiles(): array
    {
        $cases = array();

        foreach ( (array) glob( self::root() . 'modules/*/Update/Update*.php' ) as $file ) {

            $cases[ basename( dirname( $file, 2 ) ) . '/' . basename( $file ) ]
                = array( $file, (string) file_get_contents( $file ) );
        }

        return $cases;
    }

    /**
     * No migration calls addColumn() and reads the result as a failure.
     *
     * The call itself is fine -- addColumnIfMissing() makes it. What must not
     * appear is a migration deciding for itself what a false answer means,
     * because false does not mean what it looks like it means.
     *
     * @dataProvider updateFiles
     */
    public function testNoUpdateTreatsAddColumnFalseAsFailure( string $file, string $source ): void
    {
        $relative = str_replace( self::root(), '', $file );

        /*
         * Comments stripped first. Several of these files EXPLAIN the trap in
         * prose, and a naive scan reads the explanation as the offence -- which
         * would make the fix undoable without deleting the reasoning for it.
         */
        $code = preg_replace( '~/\*.*?\*/~s', '', $source );
        $code = preg_replace( '~//[^\n]*~', '', (string) $code );

        $this->assertDoesNotMatchRegularExpression(
            '/addColumn\s*\([^)]*\)\s*===?\s*false/', (string) $code,
            "$relative reads addColumn() === false as a failure. It answers false for "
            . '"already exists" too, so this stops the upgrade on a database that is '
            . 'already correct. Use $this->addColumnIfMissing( $entity, $column ).' );

        $this->assertDoesNotMatchRegularExpression(
            '/!\s*\$\w+->addColumn\s*\(/', (string) $code,
            "$relative negates addColumn() directly, which reads \"already exists\" as a "
            . 'failure. Use $this->addColumnIfMissing( $entity, $column ).' );
    }

    /** The helper exists, and is reachable from a migration. */
    public function testTheHelperExistsOnTheUpdateBaseClass(): void
    {
        $this->assertTrue( method_exists( '\OWA\Core\Update', 'addColumnIfMissing' ),
            'Update::addColumnIfMissing() is gone, so every migration that adds a column '
            . 'has to rediscover why addColumn() answering false is not a failure.' );

        $method = new ReflectionMethod( '\OWA\Core\Update', 'addColumnIfMissing' );

        $this->assertTrue( $method->isProtected(),
            'the helper must be callable from a migration subclass' );
    }

    /**
     * And the migrations that add columns actually use it.
     *
     * Named rather than discovered: these are the four that were adding columns
     * when the trap was found, and a fifth appearing without the guard is
     * exactly what the scan above is for.
     */
    public function testTheColumnAddingUpdatesUseTheHelper(): void
    {
        foreach ( array( 'Update023', 'Update024', 'Update026', 'Update027' ) as $name ) {

            $source = (string) file_get_contents(
                self::root() . 'modules/Base/Update/' . $name . '.php' );

            $this->assertStringContainsString( 'addColumnIfMissing', $source,
                "$name adds a column without the guard, so an install that jumps versions "
                . 'stops there.' );
        }
    }
    /**
     * THE UPGRADE SWEEP IS WIRED INTO CI.
     *
     * Everything above scans source or exercises one update. The thing that
     * actually runs the migrations end to end is tests/tools/upgrade_cycle.php,
     * and it only runs because a CI job invokes it -- no other environment
     * upgrades anything, so if that job is deleted or renamed, the update path
     * silently goes back to being untested and every test here still passes.
     *
     * That is the failure mode this repository has already had twice (see
     * project_tests_that_never_run): coverage that reports green by not
     * running. Cheap to assert, so it is asserted.
     */
    public function testTheUpgradeCycleIsReachableAndWiredIntoCi(): void
    {
        foreach ( array( 'tests/tools/upgrade_cycle.php',
                         'tests/tools/upgrade_cycle_run.sh',
                         'tests/tools/scratch_guard.php' ) as $file ) {

            $this->assertFileExists( self::root() . $file,
                "$file is gone. It is the only thing that runs the schema updates -- "
                . 'every other environment installs at the current version and never '
                . 'applies one.' );
        }

        $composer = json_decode(
            (string) file_get_contents( self::root() . 'composer.json' ), true );

        $this->assertArrayHasKey( 'test:upgrade', (array) $composer['scripts'],
            'the composer script the CI job calls is gone' );

        $workflow = (string) file_get_contents( self::root() . '.github/workflows/ci.yml' );

        $this->assertStringContainsString( 'test:upgrade', $workflow,
            'No CI job runs the upgrade cycle any more. Without it nothing in this '
            . 'repository ever applies a migration, which is how two of them reached '
            . 'production broken.' );
    }

    /**
     * AN UPDATE THAT BUILDS SOMETHING MUST BE ABLE TO UNBUILD IT.
     *
     * `down() { return true; }` is the worst of the three options: it claims
     * the rollback succeeded and leaves the schema exactly as it was, so an
     * operator who rolls back gets a success message and a database still
     * carrying the change. Either undo it, or say plainly that you will not.
     *
     * Both honest answers are allowed:
     *   - drop what the up() created (dropTable / dropColumn / dropIndex)
     *   - `return false` -- a refusal, which the runner reports as a failed
     *     rollback rather than a silent no-op
     *
     * What is banned is the third: a bare success that did nothing. This test
     * exists because five migrations were written that way in a row, which is a
     * habit rather than an oversight and needed a mechanism rather than a
     * resolution.
     *
     * @dataProvider updateFiles
     */
    public function testAnUpdateThatBuildsSomethingCanUnbuildIt( string $file, string $source ): void
    {
        $relative = str_replace( self::root(), '', $file );

        $code = preg_replace( '~/\*.*?\*/~s', '', $source );
        $code = preg_replace( '~//[^\n]*~', '', (string) $code );

        // What the up() actually builds.
        if ( ! preg_match( '/(createTable|addColumn|addColumnIfMissing|addIndex)/', (string) $code ) ) {

            $this->assertTrue( true, 'nothing structural to undo' );

            return;
        }

        $down = $this->downBody( (string) $code );

        $this->assertNotSame( '', $down, "$relative declares no down() at all" );

        $undoes  = (bool) preg_match( '/(dropTable|dropColumn|dropColumnIfPresent|dropIndex)/', $down );
        $refuses = (bool) preg_match( '/return\s+false/', $down );

        $this->assertTrue( $undoes || $refuses,
            "$relative builds schema in up() but its down() neither undoes it nor refuses.\n"
            . "A bare `return true` reports a rollback that did not happen. Either drop what "
            . "was created -- there are dropColumnIfPresent()/dropTable() helpers for doing it "
            . "idempotently -- or `return false` to say the rollback is not supported." );
    }

    /** The body of down(), brace-matched so a nested block does not end it early. */
    private function downBody( string $code ): string
    {
        $at = strpos( $code, 'function down' );

        if ( $at === false ) {

            return '';
        }

        $open = strpos( $code, '{', $at );

        if ( $open === false ) {

            return '';
        }

        $depth = 0;

        for ( $i = $open; $i < strlen( $code ); $i++ ) {

            if ( $code[ $i ] === '{' ) { $depth++; }
            if ( $code[ $i ] === '}' ) { $depth--; }

            if ( $depth === 0 ) {

                return substr( $code, $open, $i - $open + 1 );
            }
        }

        return substr( $code, $open );
    }
    /**
     * THE PRODUCTION FAILURE, REPRODUCED.
     *
     * The scans above prove the SHAPE of the code. This proves the behaviour:
     * running an update whose column is already present must SUCCEED.
     *
     * That is exactly what killed the live upgrade -- Update023 asked for a
     * column Update021 had already created, got false, and reported failure on
     * a correct database. The source scan alone would pass against a helper
     * that was subtly wrong; this runs the real thing against a real table.
     *
     * Update023's own targets are used because that is the update that failed.
     * The column is already there on any install at schema 23 or above, which
     * is every install this suite runs against -- so the test needs no fixture,
     * only a database.
     */
    public function testAnUpdateSucceedsWhenItsColumnIsAlreadyThere(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';

        if ( ! owa_test_db_available() ) {

            $this->markTestSkipped( 'the behaviour needs a database to be idempotent against' );
        }

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $present = (array) $db->get_results(
            "SHOW COLUMNS FROM " . $property->getTableName() . " LIKE 'archived_date'" );

        $this->assertNotEmpty( $present,
            'this install is below schema 23, so the already-present case cannot be exercised' );

        $update = new \OWA\Module\Base\Update\Update023;

        $this->assertTrue( $update->up(),
            'Update023 reported failure for a column that is already there. That is what '
            . 'stopped a live upgrade at schema 22: addColumn() answers false for "already '
            . 'exists", and reading that as a failure halts an install whose database is '
            . 'already correct.' );

        // And again, because idempotent means repeatable rather than merely
        // survivable once.
        $this->assertTrue( $update->up(), 'the second run reported failure' );
    }

    /**
     * And the down is repeatable too.
     *
     * Run against a scratch table so nothing real loses a column: a rollback
     * that got half way through has to be finishable, and dropColumnIfPresent()
     * is what makes the second attempt a no-op rather than an error.
     */
    public function testDroppingAColumnTwiceIsNotAFailure(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';

        if ( ! owa_test_db_available() ) {

            $this->markTestSkipped( 'the behaviour needs a database' );
        }

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = 'owa_test_drop_' . bin2hex( random_bytes( 4 ) );

        $db->query( "CREATE TABLE $table (id BIGINT NOT NULL, archived_date BIGINT, PRIMARY KEY (id))" );

        try {

            $entity = new class( $table ) extends \OWA\Core\Entity {

                private $t;

                public function __construct( $t ) { $this->t = $t; }

                public function getTableName() { return $this->t; }

                public function dropColumn( $column ) {

                    return \OWA\Core\CoreAPI::dbSingleton()->query(
                        'ALTER TABLE ' . $this->t . ' DROP COLUMN ' . $column );
                }
            };

            $update = new \OWA\Module\Base\Update\Update023;

            $drop = new ReflectionMethod( '\OWA\Core\Update', 'dropColumnIfPresent' );
            $drop->setAccessible( true );

            $this->assertTrue( (bool) $drop->invoke( $update, $entity, 'archived_date' ),
                'the first drop failed' );

            $this->assertTrue( (bool) $drop->invoke( $update, $entity, 'archived_date' ),
                'dropping an already-absent column reported failure, so a rollback that got '
                . 'half way through cannot be finished' );

        } finally {

            $db->query( "DROP TABLE IF EXISTS $table" );
        }
    }
}
