<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * One store for settings, and one query to resolve a scope chain.
 *
 * Install-wide values used to be a serialized blob in owa_configuration and
 * scoped values were rows in owa_setting. Update043 made install one more scope
 * in the one table. What is asserted here is the behaviour that motivated it:
 *
 *   - a value stored at three levels at once resolves to the narrowest, and it
 *     does so without a priority column -- the rank is in the ORDER BY
 *   - resolving a chain costs ONE query, not one per key per level
 *   - a stored false is a value, not an absence
 *
 * The rank is exercised through settingRowsForChain() with a hand-built chain
 * rather than through real Property and Profile rows: the ordering is the thing
 * under test, and building a hierarchy to reach it would make a failure here
 * ambiguous between the ranking and the walk that produced the chain.
 */
final class SettingsStoreConsolidationTest extends TestCase
{
    /** Distinctive so a leaked row is obvious in the table. */
    private const MODULE = 'zz_consolidation_test';

    /** @var array list of array{type,id,name} written by a test */
    private $written = array();

    private function requireDb(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'settings resolution is a query' );
        }
    }

    protected function tearDown(): void
    {
        if ( ! owa_test_db_available() ) {
            return;
        }

        foreach ( $this->written as $row ) {

            \OWA\Core\CoreAPI::clearScopedSetting(
                $row['type'], $row['id'], self::MODULE, $row['name'] );
        }

        $this->written = array();

        \OWA\Core\CoreAPI::settingCacheFlush();
    }

    private function write( $type, $id, $name, $value ): void
    {
        \OWA\Core\CoreAPI::setScopedSetting( $type, $id, self::MODULE, $name, $value );

        $this->written[] = array( 'type' => $type, 'id' => $id, 'name' => $name );
    }

    private function chain( array $levels ): array
    {
        $chain = array();

        foreach ( $levels as $type => $id ) {

            $chain[] = array( 'type' => $type, 'id' => $id );
        }

        return $chain;
    }

    /**
     * Three rows for one key, and the narrowest scope wins.
     *
     * This is the case that decided against a priority column: all three rows
     * exist simultaneously, none was refused at write, and the answer comes
     * from the ORDER BY over scope_type.
     */
    public function testTheNarrowestScopeHoldingARowWins(): void
    {
        $this->requireDb();

        $this->write( 'install',  \OWA\Module\Base\Classes\Settings::INSTALL_SCOPE_ID,  'persistence', '30days' );
        $this->write( 'property', 'P1', 'persistence', '400days' );
        $this->write( 'profile',  'S1', 'persistence', '7days' );

        \OWA\Core\CoreAPI::settingCacheFlush();

        $rows = \OWA\Core\CoreAPI::settingRowsForChain( $this->chain( array(
            'profile' => 'S1', 'property' => 'P1', 'install' => \OWA\Module\Base\Classes\Settings::INSTALL_SCOPE_ID ) ) );

        $this->assertSame( '7days', $rows['effective'][ self::MODULE . '|persistence' ],
            'the profile row outranks the property and install rows it coexists with' );
    }

    /** A level with no row of its own inherits, and the next one down answers. */
    public function testAScopeWithNoRowInheritsTheNextOneUp(): void
    {
        $this->requireDb();

        $this->write( 'install',  \OWA\Module\Base\Classes\Settings::INSTALL_SCOPE_ID,  'persistence', '30days' );
        $this->write( 'property', 'P1', 'persistence', '400days' );

        \OWA\Core\CoreAPI::settingCacheFlush();

        $rows = \OWA\Core\CoreAPI::settingRowsForChain( $this->chain( array(
            'profile' => 'S9', 'property' => 'P1', 'install' => \OWA\Module\Base\Classes\Settings::INSTALL_SCOPE_ID ) ) );

        $this->assertSame( '400days', $rows['effective'][ self::MODULE . '|persistence' ],
            'S9 holds nothing, so its Property answers' );

        $rows = \OWA\Core\CoreAPI::settingRowsForChain( $this->chain( array(
            'profile' => 'S9', 'property' => 'P9', 'install' => \OWA\Module\Base\Classes\Settings::INSTALL_SCOPE_ID ) ) );

        $this->assertSame( '30days', $rows['effective'][ self::MODULE . '|persistence' ],
            'neither the Profile nor its Property holds one, so install answers' );
    }

    /**
     * The per-level answer is available from the same result.
     *
     * A settings screen needs it to show whether a value is set HERE or coming
     * from above -- without it every field shows a value and none can be unset.
     */
    public function testTheOwnValueOfALevelIsDistinctFromTheEffectiveOne(): void
    {
        $this->requireDb();

        $this->write( 'property', 'P1', 'persistence', '400days' );

        \OWA\Core\CoreAPI::settingCacheFlush();

        $this->assertNull(
            \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', 'S1', self::MODULE, 'persistence' ),
            'the Profile owns nothing' );

        $this->assertSame( '400days',
            \OWA\Core\CoreAPI::getScopedSettingRow( 'property', 'P1', self::MODULE, 'persistence' ),
            'its Property does' );
    }

    /**
     * A stored false is a value.
     *
     * The distinction the blob could not make: it said "not set" by omitting a
     * key, so "inherit from my Property" and "override my Property to off" were
     * the same statement.
     */
    public function testAStoredFalseIsAValueAndNotAnAbsence(): void
    {
        $this->requireDb();

        $this->write( 'property', 'P1', 'log_robots', false );

        \OWA\Core\CoreAPI::settingCacheFlush();

        $rows = \OWA\Core\CoreAPI::settingRowsForChain( $this->chain( array(
            'property' => 'P1', 'install' => \OWA\Module\Base\Classes\Settings::INSTALL_SCOPE_ID ) ) );

        $key = self::MODULE . '|log_robots';

        $this->assertArrayHasKey( $key, $rows['effective'], 'the row exists' );
        $this->assertFalse( $rows['effective'][ $key ], 'and it holds false, not nothing' );

        $this->assertNull(
            \OWA\Core\CoreAPI::getScopedSettingRow( 'property', 'P2', self::MODULE, 'log_robots' ),
            'while a scope with no row still answers null' );
    }

    /**
     * ONE query for a whole chain, whatever the number of keys.
     *
     * The shape this replaced loaded an entity per key per level, so a screen
     * reading a dozen settings for a Profile cost up to thirty-six of them.
     * Counted rather than asserted: see Db::$num_queries, which was declared in
     * 1.0 and never incremented until this claim needed checking.
     */
    public function testResolvingAChainCostsOneQueryForAnyNumberOfKeys(): void
    {
        $this->requireDb();

        foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $name ) {

            $this->write( 'property', 'P1', $name, 'value-' . $name );
        }

        \OWA\Core\CoreAPI::settingCacheFlush();

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $chain = $this->chain( array( 'profile' => 'S1', 'property' => 'P1', 'install' => \OWA\Module\Base\Classes\Settings::INSTALL_SCOPE_ID ) );

        $before = (int) $db->num_queries;

        $rows = \OWA\Core\CoreAPI::settingRowsForChain( $chain );

        $this->assertSame( 1, (int) $db->num_queries - $before,
            'one query builds the whole chain' );

        foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $name ) {

            $this->assertSame( 'value-' . $name,
                $rows['effective'][ self::MODULE . '|' . $name ] );
        }

        $before = (int) $db->num_queries;

        \OWA\Core\CoreAPI::settingRowsForChain( $chain );
        \OWA\Core\CoreAPI::getScopedSettingRow( 'property', 'P1', self::MODULE, 'a' );

        $this->assertSame( 0, (int) $db->num_queries - $before,
            'and the chain is answered from memory for the rest of the request' );

        /*
         * Including for a scope in the chain that holds NOTHING. That scope
         * produced no rows, so it is only in the cache because the chain walk
         * put it there empty -- and without that, every key asked of the
         * Profile would go back to the database to be told "no" again, which
         * is the majority of what a settings screen asks.
         */
        $before = (int) $db->num_queries;

        $this->assertNull(
            \OWA\Core\CoreAPI::getScopedSettingRow( 'profile', 'S1', self::MODULE, 'a' ),
            'S1 holds nothing of its own' );

        $this->assertSame( 0, (int) $db->num_queries - $before,
            'and being told so costs no query, because the chain walk already asked' );
    }

    /**
     * An install row stores the scope_id it was given.
     *
     * It did not, for the first version of this: the id was '0', scope_id is a
     * string column, and Entity::set() drops a falsy value on one of those. The
     * rows were written, load() still found them -- it filters on scope_type
     * alone -- and every SCOPED read fell through to the code default, because
     * the chain query matches on scope_id and nothing matched. Silent, and only
     * visible from a scope.
     */
    public function testAnInstallRowKeepsItsScopeId(): void
    {
        $this->requireDb();

        $this->write( 'install', \OWA\Module\Base\Classes\Settings::INSTALL_SCOPE_ID,
            'persistence', '30days' );

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        $entity->load( $entity->makeId( 'install',
            \OWA\Module\Base\Classes\Settings::INSTALL_SCOPE_ID, self::MODULE, 'persistence' ) );

        $this->assertSame(
            \OWA\Module\Base\Classes\Settings::INSTALL_SCOPE_ID,
            (string) $entity->get( 'scope_id' ),
            'a falsy scope id would be written as an empty string and never match again' );

        $this->assertNotSame( '', (string) $entity->get( 'scope_id' ) );
    }

    /**
     * Everything written install-wide is autoloaded, for now.
     *
     * The column exists so that stops being true, but nothing yet decides a key
     * is lazy: load() fetches the autoloaded rows and getSetting() answers
     * install-wide reads out of that merged array, so a row with autoload = 0
     * would currently be INVISIBLE rather than fetched on demand. The on-demand
     * read belongs with the registration that knows a key is lazy, and until
     * then both write paths -- Settings::save() and Update043 -- must store 1.
     */
    public function testEveryInstallRowIsWrittenAutoloaded(): void
    {
        $this->requireDb();

        $c = \OWA\Core\CoreAPI::configSingleton();

        $c->persistSetting( self::MODULE, 'autoload_probe', 'stored' );
        $c->save();

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        $row = $db->get_row( sprintf(
            "SELECT autoload FROM %s WHERE scope_type = 'install'"
          . " AND module = '%s' AND name = 'autoload_probe' LIMIT 1",
            $entity->getTableName(), self::MODULE ) );

        // Cleaned up here rather than in tearDown: it was written through the
        // install path, not through write(), so it is not on that list.
        unset( $c->db_settings[ self::MODULE ] );
        \OWA\Core\CoreAPI::clearScopedSetting(
            'install', \OWA\Module\Base\Classes\Settings::INSTALL_SCOPE_ID,
            self::MODULE, 'autoload_probe' );

        $this->assertNotEmpty( $row, 'persistSetting + save must write a row' );
        $this->assertSame( 1, (int) $row['autoload'],
            'an install setting nothing has declared lazy is read at boot, as it was '
          . 'when every setting shared one blob' );
    }

    /**
     * The rank is a CASE, not MySQL's FIELD().
     *
     * FIELD() would read better and does not exist in SQLite or Postgres. The
     * dialect layer is the reason this is worth pinning: a query written here
     * in MySQL-only syntax is not refused, it just narrows what OWA runs on,
     * and nothing else would notice.
     */
    public function testTheScopeRankIsWrittenPortably(): void
    {
        $body = file_get_contents( __DIR__ . '/../Core/CoreAPI.php' );

        $this->assertStringContainsString( 'CASE scope_type', $body,
            'the rank is a CASE expression' );

        $this->assertDoesNotMatchRegularExpression(
            '/\bFIELD\s*\(\s*scope_type/i', $body,
            'FIELD() is MySQL-only; the scope rank must not depend on it' );
    }

    /**
     * There is no priority column, and adding one would mean a second copy of
     * the rank on every row.
     *
     * Asserted because the argument for it keeps coming back: the ordering is
     * a property of the scope, and the scope is already stored.
     */
    public function testTheSettingTableCarriesNoPriorityColumn(): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        $this->assertNotContains( 'priority', (array) $entity->getColumns(),
            'the scope rank is passed at query time, not stored per row' );
    }
}
