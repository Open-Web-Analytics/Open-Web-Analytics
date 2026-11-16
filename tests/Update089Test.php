<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Update\Update089;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Update089: owa_user.api_key is dropped, and down() puts the column back in
 * its place, empty. On a scratch copy of the user table.
 */
final class Update089Test extends TestCase
{
    const TABLE = 'owa_test_update089_user';

    /** user_id => api_key as 1.x left it. */
    const KEYS = [
        'owa-test-089-a' => '0123456789abcdef0123456789abcdef',
        'owa-test-089-b' => null,
        'owa-test-089-c' => '',
    ];

    private Update089 $update;

    protected function setUp(): void
    {
        $this->update = new Update089();
        $this->update->tables = ['base.user' => self::TABLE];
    }

    protected function tearDown(): void
    {
        if (owa_test_db_available()) {
            $db = \OWA\Core\CoreAPI::dbSingleton();
            $db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        }
    }

    /**
     * A copy of the user table as 1.x left it: api_key after last_update_date,
     * and the columns v2 added after it.
     */
    private function scratchTable(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('alters a scratch copy of the user table');
        }

        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $user = \OWA\Core\CoreAPI::entityFactory('base.user')->getTableName();

        $db->query('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->assertNotFalse($db->query(sprintf('CREATE TABLE %s LIKE %s', self::TABLE, $user)));

        if (!$this->hasColumn()) {
            $this->assertNotFalse($db->query(sprintf(
                'ALTER TABLE %s ADD COLUMN api_key VARCHAR(255) DEFAULT NULL AFTER last_update_date', self::TABLE)));
        }

        foreach (self::KEYS as $userId => $key) {
            $this->assertNotFalse($db->query(sprintf("INSERT INTO %s (user_id, role, api_key) VALUES (?, 'viewer', ?)", self::TABLE),
                [$userId, $key]));
        }
    }

    private function hasColumn(): bool
    {
        return (bool) \OWA\Core\CoreAPI::dbSingleton()->get_results(
            sprintf("SHOW COLUMNS FROM %s LIKE 'api_key'", self::TABLE));
    }

    private function createStatement(): string
    {
        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row('SHOW CREATE TABLE ' . self::TABLE);

        // The next AUTO_INCREMENT value is not part of the table's shape.
        return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) $row['Create Table']);
    }

    /** @return array<string,string|null> user_id => api_key */
    private function keys(): array
    {
        $out = [];

        foreach ((array) \OWA\Core\CoreAPI::dbSingleton()->get_results(sprintf('SELECT user_id, api_key FROM %s ORDER BY id', self::TABLE)) as $row) {
            $row = (array) $row;
            $out[(string) $row['user_id']] = $row['api_key'] === null ? null : (string) $row['api_key'];
        }

        return $out;
    }

    public function testTheModuleAsksForItAndTheEntityHasNoColumn(): void
    {
        $this->assertSame(89, $this->update->schema_version);
        $this->assertFalse($this->update->is_cli_mode_required);
        $this->assertGreaterThanOrEqual(89,
            \OWA\Core\CoreAPI::serviceSingleton()->getModule('base')->required_schema_version);

        $user = \OWA\Core\CoreAPI::entityFactory('base.user');
        $this->assertNotContains(Update089::COLUMN, $user->getColumns(), 'an install creates no api_key column');
    }

    /** Nothing outside the updates names the column. */
    public function testNoCodeNamesTheColumn(): void
    {
        $found = [];

        foreach (['Core', 'modules'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(\OWA\Core\Env::root() . '/' . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = (string) $file;

                if (substr($path, -4) !== '.php' || strpos($path, '/Update/') !== false) {
                    continue;
                }

                if (preg_match("/['\"]api_key['\"]/", (string) file_get_contents($path))) {
                    $found[] = str_replace(\OWA\Core\Env::root() . '/', '', $path);
                }
            }
        }

        // Auth names the refused credential 'api_key' as its auth method.
        $this->assertSame(['Core/Auth.php'], $found);
    }

    public function testUpDropsTheColumnAndIsIdempotent(): void
    {
        $this->scratchTable();

        $this->assertTrue($this->update->up());
        $this->assertFalse($this->hasColumn());
        $after = $this->createStatement();

        $this->assertTrue($this->update->up(), 'a second run');
        $this->assertSame($after, $this->createStatement());
    }

    /** The column comes back defined as it was and where it was, with no keys. */
    public function testDownAddsTheColumnBackEmpty(): void
    {
        $this->scratchTable();
        $before = $this->createStatement();

        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->down());

        $this->assertSame($before, $this->createStatement());
        $this->assertSame(array_fill_keys(array_keys(self::KEYS), null), $this->keys());
        $this->assertNotSame('', $this->update->down_does_not_restore);

        $this->assertTrue($this->update->down(), 'a second run');
        $this->assertSame($before, $this->createStatement());
    }
}
