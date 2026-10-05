<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Controller\GoalEventEdit;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What the goal event editor offers a condition: the trigger's properties,
 * each described, and any property already in use kept even when the trigger
 * does not carry it. owa.goalbuilder.js applies the same rule as the event
 * changes; GoalBuilder.test.js holds it to that.
 */
final class GoalEventEditVocabularyTest extends TestCase
{
    public function testEachPropertyIsDescribedAndCarried(): void
    {
        foreach (GoalEventEdit::conditionProperties('click') as $p) {
            $this->assertTrue($p['carried'], $p['name']);
            $this->assertNotSame('', $p['description'], $p['name']);
        }
    }

    public function testAPropertyInUseIsKeptAndFlaggedWhenTheTriggerDoesNotCarryIt(): void
    {
        $props = array_column(GoalEventEdit::conditionProperties('click',
            [['condition_property' => 'tagged_source']]), null, 'name');

        $this->assertArrayHasKey('tagged_source', $props);
        $this->assertFalse($props['tagged_source']['carried']);
        $this->assertStringContainsString('not carried by click', $props['tagged_source']['label']);
        $this->assertNotSame('', $props['tagged_source']['description']);
    }
}
