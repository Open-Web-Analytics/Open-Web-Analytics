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
}
