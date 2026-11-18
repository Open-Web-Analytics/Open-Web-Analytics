<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Db::select() passes its alias on to selectColumn().
 *
 * It called selectColumn($name, $as = ''), an assignment, so the alias given
 * was replaced by '' before the call.
 */
final class DbSelectAliasTest extends TestCase
{
    private function db(): \OWA\Core\Db
    {
        // Unconnected: select() only records the column.
        return (new ReflectionClass(\OWA\Core\Db\Mysql::class))->newInstanceWithoutConstructor();
    }

    public function testTheAliasGivenIsKept(): void
    {
        $db = $this->db();
        $db->select('count(*)', 'n');

        $this->assertSame([['name' => 'count(*)', 'as' => 'n']], $db->_sqlParams['select_values']);
    }

    public function testNoAliasIsNone(): void
    {
        $db = $this->db();
        $db->select('*');

        $this->assertSame([['name' => '*', 'as' => '']], $db->_sqlParams['select_values']);
    }

    /** A [column, alias] pair names its own alias, as selectColumn() reads it. */
    public function testAPairKeepsItsAlias(): void
    {
        $db = $this->db();
        $db->select(['session_id', 'sid']);

        $this->assertSame([['name' => 'session_id', 'as' => 'sid']], $db->_sqlParams['select_values']);
    }
}
