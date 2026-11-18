<?php

use PHPUnit\Framework\TestCase;

use OWA\Module\Base\Classes\Migration\ActionMigrator;

/**
 * A 1.x action name becomes the same event name whether the upgrade migrates
 * it or the tracker's trackAction() sends it. tests/js/Tracker.test.js reads
 * the same table for the tracker's half.
 */
final class ActionEventNameTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    public function testTheMigrationGivesTheSharedNames(): void
    {
        $table = json_decode((string) file_get_contents(__DIR__ . '/fixtures/action_event_names.json'), true);

        $this->assertNotEmpty($table['names']);

        foreach ($table['names'] as [$action, $event]) {

            $this->assertSame($event, ActionMigrator::eventName($action), "for '$action'");
        }
    }
}
