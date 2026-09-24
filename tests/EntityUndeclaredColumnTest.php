<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * An entity refuses a column it does not declare, by name.
 *
 * THE RECURRING MISTAKE THIS NAMES. An update that drops a column has a down()
 * that must put it back -- and by then the entity no longer declares it,
 * because removing it is what up() did. addColumn() and addColumnIfMissing()
 * both ask the entity for the definition, get an undefined array key and a null
 * DbColumn, and the down() fails without saying why.
 *
 * It surfaces only in the schema upgrade cycle, which is the one CI job that
 * runs a down() at all -- so it lands far from the change that caused it, in a
 * log nobody reads by choice. Twice now.
 *
 * The fix is always the same, so the message states it: a down() restoring a
 * removed column spells out its own type, the way Update039 and Update046 do.
 */
final class EntityUndeclaredColumnTest extends TestCase
{
    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; entityFactory needs the driver.');
        }
    }

    public function testAnUndeclaredColumnIsRefusedByName(): void
    {
        $entity = owa_coreAPI::entityFactory('base.event_raw');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not declare a column called "no_such_column"/');

        $entity->getColumn('no_such_column');
    }

    /** And the message says what to do, because the fix is always the same. */
    public function testTheMessageNamesTheFix(): void
    {
        $entity = owa_coreAPI::entityFactory('base.event_raw');

        try {
            $entity->getColumn('no_such_column');
            $this->fail('an undeclared column must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('down()', $e->getMessage());
            $this->assertStringContainsString('spell the type out', $e->getMessage());
        }
    }

    /**
     * A declared column still resolves, so the guard is not refusing everything.
     */
    public function testADeclaredColumnStillResolves(): void
    {
        $entity = owa_coreAPI::entityFactory('base.event_raw');

        $this->assertInstanceOf(
            \OWA\Module\Base\Classes\DbColumn::class,
            $entity->getColumn('event_seq'));
    }
}
