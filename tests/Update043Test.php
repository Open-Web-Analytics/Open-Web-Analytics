<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Unpacking the settings blob, and putting it back, more than once.
 *
 * The schema upgrade cycle rewinds and re-applies every update IN ONE PROCESS
 * -- down(), up(), down() again -- and that is what broke this. Both entities
 * involved are setCachable() and the migration drops and recreates their
 * tables underneath them, so on the second down() a cached object answered
 * with an id, the write took the update() branch, the UPDATE matched no row in
 * the table that had just been rebuilt, and the write reported success having
 * stored nothing.
 *
 * Nothing noticed until the next cold boot, which found no schema_version and
 * sent the upgrade back to Update003 -- against a schema rewound past the
 * tables that update assumes, where it stopped dead.
 *
 * So the cycle is run here rather than only in CI, and the assertion is on the
 * SECOND pass, which is the one that was wrong.
 */
final class Update043Test extends TestCase
{
    /** @var \OWA\Module\Base\Update\Update043 */
    private $update;

    protected function setUp(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'the migration moves rows between two tables' );
        }

        $this->update = new \OWA\Module\Base\Update\Update043();
        $this->update->module_name = 'base';
    }

    /**
     * Leave the install where it was found: unpacked, on rows.
     *
     * up() is idempotent, so running it here is safe whatever the test did.
     */
    protected function tearDown(): void
    {
        if ( $this->update ) {

            \OWA\Core\CoreAPI::configSingleton()->settingStoreRecheck();
            $this->update->up( true );
            \OWA\Core\CoreAPI::configSingleton()->settingStoreRecheck();
        }
    }

    private function blobRow()
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.configuration' );

        if ( ! $db->tableExists( $entity->getTableName() ) ) {

            return null;
        }

        return $db->get_row( sprintf(
            'SELECT id, settings FROM %s', $entity->getTableName() ) );
    }

    private function installRowCount(): int
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        $row = $db->get_row( sprintf(
            "SELECT COUNT(*) AS n FROM %s WHERE scope_type = 'install'",
            $entity->getTableName() ) );

        return (int) ( $row['n'] ?? 0 );
    }

    private function recheck(): void
    {
        \OWA\Core\CoreAPI::configSingleton()->settingStoreRecheck();
    }

    public function testDownAndUpSurviveBeingRunTwiceInOneProcess(): void
    {
        $before = $this->installRowCount();

        $this->assertGreaterThan( 0, $before,
            'the install should be on rows before this starts' );

        $this->recheck();
        $this->assertTrue( $this->update->down(), 'first rollback' );

        $first = $this->blobRow();

        $this->assertNotEmpty( $first, 'the first rollback writes the blob' );
        $this->assertSame( 0, $this->installRowCount(),
            'and takes the install rows with it' );

        $this->recheck();
        $this->assertTrue( $this->update->up( true ), 'forward again' );

        $this->assertSame( $before, $this->installRowCount(),
            're-applying puts every row back' );
        $this->assertNull( $this->blobRow(), 'and drops the blob table again' );

        $this->recheck();
        $this->assertTrue( $this->update->down(), 'second rollback' );

        $second = $this->blobRow();

        $this->assertNotEmpty( $second,
            'THE SECOND ROLLBACK MUST WRITE THE BLOB TOO. It reported success and '
          . 'wrote nothing, because an entity cached across the drop took the '
          . 'update() branch against a table that had just been rebuilt.' );

        $this->assertSame( $first['settings'], $second['settings'],
            'and must write the same settings as the first' );
    }

    /**
     * What a cold boot would read, after the rollback.
     *
     * The failure was only ever visible here: in-process the settings were
     * still in memory and correct, so the rewind reported the schema version it
     * thought it had reached, and the next process found nothing.
     */
    public function testAfterARollbackTheStoredSettingsStillHoldTheSchemaVersion(): void
    {
        $expected = \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' );

        $this->recheck();
        $this->assertTrue( $this->update->down(), 'rollback' );
        $this->recheck();
        $this->assertTrue( $this->update->up( true ), 'forward' );
        $this->recheck();
        $this->assertTrue( $this->update->down(), 'rollback again' );

        $row = $this->blobRow();

        $this->assertNotEmpty( $row );

        $stored = unserialize( (string) $row['settings'], array( 'allowed_classes' => false ) );

        $this->assertIsArray( $stored );
        $this->assertSame( $expected, $stored['base']['schema_version'] ?? null,
            'a cold boot reads schema_version out of this, and starts the upgrade '
          . 'from Update003 if it is not there' );
    }
}
