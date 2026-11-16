<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update087;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * owa_user.session_epoch, on a scratch copy of the user table.
 */
final class Update087Test extends TestCase
{
    const TABLE = 'owa_test_update087_user';

    private Update087 $update;

    protected function setUp(): void
    {
        $this->update = new Update087();
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            \OWA\Core\CoreAPI::dbSingleton()->query('DROP TABLE IF EXISTS ' . self::TABLE);
        }
    }

    /** A copy of the user table as it was before the update, with one user. */
    private function scratchTable(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters a scratch copy of the user table');
        }

        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $user = \OWA\Core\CoreAPI::entityFactory('base.user')->getTableName();

        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->assertNotFalse($db->query(sprintf('CREATE TABLE %s LIKE %s', self::TABLE, $user)));

        if ($this->hasColumn()) {
            $this->assertNotFalse($db->query(sprintf('ALTER TABLE %s DROP COLUMN session_epoch', self::TABLE)));
        }

        $this->assertNotFalse($db->query(sprintf("INSERT INTO %s (user_id, role) VALUES ('owa-test-087', 'viewer')", self::TABLE)));

        $this->update->tables = ['base.user' => self::TABLE];
    }

    private function hasColumn(): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            sprintf("SHOW COLUMNS FROM %s LIKE 'session_epoch'", self::TABLE));
    }

    private function createStatement(): string
    {
        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row('SHOW CREATE TABLE ' . self::TABLE);

        return (string) $row['Create Table'];
    }

    public function testTheUpdateTheModuleAndTheEntityAgree(): void
    {
        $this->assertSame(87, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(87,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);

        $user = \OWA\Core\CoreAPI::entityFactory('base.user');
        $this->assertContains(Update087::COLUMN, $user->getColumns());
        $this->assertSame(Update087::DEFINITION, $user->getColumnDefinition(Update087::COLUMN),
            'an install creates the column the update adds');
    }

    public function testUpAddsTheColumnAtZeroAndIsIdempotent(): void
    {
        $this->scratchTable();

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->hasColumn());
        $after = $this->createStatement();

        $this->assertTrue($this->update->up());
        $this->assertSame($after, $this->createStatement());

        $column = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            sprintf("SHOW COLUMNS FROM %s LIKE 'session_epoch'", self::TABLE));
        $this->assertSame('NO', $column['Null']);
        $this->assertSame('0', (string) $column['Default']);

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            sprintf("SELECT session_epoch FROM %s WHERE user_id = 'owa-test-087'", self::TABLE));
        $this->assertSame('0', (string) $row['session_epoch']);
    }

    public function testDownIsTheExactInverse(): void
    {
        $this->scratchTable();
        $before = $this->createStatement();

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->down());
        $this->assertFalse($this->hasColumn());
        $this->assertSame($before, $this->createStatement());

        $this->assertTrue($this->update->down(), 'down() with nothing to undo');
        $this->assertSame($before, $this->createStatement());
    }
}
