<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

/**
 * A built DELETE or UPDATE with no WHERE is refused.
 *
 * Db::where() drops a condition whose value is empty, so Entity::delete()
 * with an empty value on an entity with no id built `DELETE FROM owa_user`
 * and emptied the table. That emptied the dev install's users.
 */
final class UnfilteredWriteGuardTest extends RestControllerTestCase
{
    private function userCount(): int
    {
        $table = \OWA\Core\CoreAPI::entityFactory('base.user')->getTableName();

        return (int) (\OWA\Core\CoreAPI::dbSingleton()->get_row("SELECT COUNT(*) AS c FROM $table")['c'] ?? 0);
    }

    public function testAnEntityWithNothingToNameItsRowDeletesNothing(): void
    {
        $this->makeUser('viewer', 'a');
        $this->makeUser('viewer', 'b');
        $before = $this->userCount();

        $this->assertFalse(\OWA\Core\CoreAPI::entityFactory('base.user')->delete());
        $this->assertFalse(\OWA\Core\CoreAPI::entityFactory('base.user')->delete('', 'id'));
        $this->assertFalse(\OWA\Core\CoreAPI::entityFactory('base.user')->delete(null, 'user_id'));

        $this->assertSame($before, $this->userCount());
    }

    public function testTheBuilderRefusesADeleteWithNoWhere(): void
    {
        $this->makeUser('viewer', 'c');
        $before = $this->userCount();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->deleteFrom(\OWA\Core\CoreAPI::entityFactory('base.user')->getTableName());
        $db->where('id', '');

        $this->assertFalse($db->executeQuery());
        $this->assertSame($before, $this->userCount());
    }

    public function testTheBuilderRefusesAnUpdateWithNoWhere(): void
    {
        $user = $this->makeUser('viewer', 'd');
        $table = \OWA\Core\CoreAPI::entityFactory('base.user')->getTableName();

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->updateTable($table);
        $db->set('real_name', 'Overwritten');
        $db->where('id', null);

        $this->assertFalse($db->executeQuery());

        $row = $db->get_row("SELECT real_name FROM $table WHERE id = ?", array($user['id']));
        $this->assertNotSame('Overwritten', $row['real_name']);
    }

    /** The control: a delete that names its row still deletes that row only. */
    public function testADeleteByIdStillDeletesOneRow(): void
    {
        $keep = $this->makeUser('viewer', 'keep');
        $gone = $this->makeUser('viewer', 'gone');
        $before = $this->userCount();

        \OWA\Core\CoreAPI::entityFactory('base.user')->delete($gone['id'], 'id');

        $this->assertSame($before - 1, $this->userCount());
        $this->assertTrue($this->userExists($keep['user_id']));
        $this->assertFalse($this->userExists($gone['user_id']));
    }
}
