<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Controller\GoalEventDefinitionEdit;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * What the goal event definition editor offers a condition: the trigger's properties,
 * each described, and any property already in use kept even when the trigger
 * does not carry it. owa.goalbuilder.js applies the same rule as the event
 * changes; GoalBuilder.test.js holds it to that.
 */
final class GoalEventDefinitionEditVocabularyTest extends TestCase
{
    public function testEachPropertyIsDescribedAndCarried(): void
    {
        foreach (GoalEventDefinitionEdit::conditionProperties('click') as $p) {
            $this->assertTrue($p['carried'], $p['name']);
            $this->assertNotSame('', $p['description'], $p['name']);
        }
    }

    /** Each is in the field chooser's group of the dimension that reads it, named. */
    public function testEachPropertyIsGrouped(): void
    {
        $props = array_column(GoalEventDefinitionEdit::conditionProperties('click'), null, 'name');

        $this->assertSame(['event', 'Event'], [$props['is_outbound']['group'], $props['is_outbound']['group_label']]);
        $this->assertSame(['device', 'Device'], [$props['browser_type']['group'], $props['browser_type']['group_label']]);

        foreach ($props as $p) {
            $this->assertNotSame('', $p['group_label'], $p['name']);
        }
    }

    public function testAPropertyInUseIsKeptAndFlaggedWhenTheTriggerDoesNotCarryIt(): void
    {
        $props = array_column(GoalEventDefinitionEdit::conditionProperties('click',
            [['condition_property' => 'tagged_source']]), null, 'name');

        $this->assertArrayHasKey('tagged_source', $props);
        $this->assertFalse($props['tagged_source']['carried']);
        $this->assertStringContainsString('not carried by click', $props['tagged_source']['label']);
        $this->assertNotSame('', $props['tagged_source']['description']);
        $this->assertSame('traffic source', $props['tagged_source']['group'], 'a kept property keeps its group');
    }
}
