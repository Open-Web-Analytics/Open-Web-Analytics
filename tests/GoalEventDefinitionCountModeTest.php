<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Entity\GoalEventDefinition;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A goal event definition's count mode, as the save stores it and the screens
 * show it. What marking does with it is GoalMarkingTest's.
 *
 * No database: the save's choice is a pure function, and the templates are
 * rendered with the helpers that would need a request stubbed.
 */
final class GoalEventDefinitionCountModeTest extends TestCase
{
    /** One of the two constants is stored as given; anything else is the default, once per event. */
    public function testTheSaveStoresOneOfTheTwoModes(): void
    {
        $save = '\OWA\Module\Base\Controller\GoalEventDefinitionSave';

        $this->assertSame(GoalEventDefinition::COUNT_PER_SESSION, $save::countMode('once_per_session'));
        $this->assertSame(GoalEventDefinition::COUNT_PER_EVENT, $save::countMode('once_per_event'));

        foreach (['', null, false, 'once_per_visit', 'ONCE_PER_SESSION', ['once_per_session']] as $other) {
            $this->assertSame(GoalEventDefinition::COUNT_PER_EVENT, $save::countMode($other),
                var_export($other, true) . ' is not a count mode');
        }
    }

    /** A stored count mode reads as itself, and anything else -- every 1.x definition -- as once per session. */
    public function testAStoredModeReadsAsItself(): void
    {
        $this->assertSame(GoalEventDefinition::COUNT_PER_EVENT, GoalEventDefinition::countModeOf('once_per_event'));
        $this->assertSame(GoalEventDefinition::COUNT_PER_SESSION, GoalEventDefinition::countModeOf('once_per_session'));

        foreach (['', null, false, 'anything'] as $falsy) {
            $this->assertSame(GoalEventDefinition::COUNT_PER_SESSION, GoalEventDefinition::countModeOf($falsy));
        }

        $this->assertSame([GoalEventDefinition::COUNT_PER_EVENT, GoalEventDefinition::COUNT_PER_SESSION],
            array_keys(GoalEventDefinition::countModes()), 'both offered, once per event first');
    }

    /** A new definition's form offers both, with once per event chosen. */
    public function testANewDefinitionDefaultsToOncePerEvent(): void
    {
        $out = $this->form([]);

        $this->assertStringContainsString('value="once_per_event" selected', $out);
        $this->assertStringContainsString('value="once_per_session" ', $out);
        $this->assertStringNotContainsString('value="once_per_session" selected', $out);
        $this->assertStringNotContainsString('How often a match counts: once per session.', $out,
            'the copy from when once per session was the only choice');
    }

    /** An existing one shows what it stores; nothing stored is once per session. */
    public function testAnExistingDefinitionShowsWhatItStores(): void
    {
        foreach (['once_per_event' => 'once_per_event', 'once_per_session' => 'once_per_session', '' => 'once_per_session']
                 as $stored => $shown) {

            $out = $this->form(['id' => '7000000000000002', 'name' => 'Signup', 'count_mode' => $stored,
                'value' => 0, 'is_active' => 1, 'condition_match' => 'all'], '7000000000000002');

            $this->assertStringContainsString('value="' . $shown . '" selected', $out, var_export($stored, true));
            $this->assertSame(1, preg_match_all('/value="once_per_(?:event|session)" selected/', $out), 'one mode chosen');
        }
    }

    /** A refused new form comes back with the mode that was submitted. */
    public function testARefusedFormKeepsTheSubmittedMode(): void
    {
        $out = $this->form(['id' => '', 'name' => 'Signup', 'count_mode' => 'once_per_session', 'value' => 0,
            'is_active' => 1, 'condition_match' => 'all']);

        $this->assertStringContainsString('value="once_per_session" selected', $out);
    }

    /** The list says how each one counts. */
    public function testTheListShowsTheCountMode(): void
    {
        $row = ['id' => '7000000000000003', 'name' => 'Signup', 'trigger_event_type' => 'newsletter_signup',
            'conditions' => [], 'condition_match' => 'all', 'value' => 0, 'is_active' => 1];

        $out = $this->render('goal_event_definitions.php', [
            'headline' => 'Goal Event Definitions', 'propertyId' => '7000000000000001',
            'goalEventDefinitions' => [
                $row + ['count_mode' => 'once_per_event'],
                ['id' => '7000000000000004', 'name' => 'Migrated'] + $row + ['count_mode' => null],
            ],
        ]);

        $this->assertStringContainsString('<td class="owa_goalEventCountMode">Once per event</td>', $out);
        $this->assertStringContainsString('<td class="owa_goalEventCountMode">Once per session</td>', $out);
    }

    /* ---------------- helpers ---------------- */

    /** The edit form, for this record. */
    private function form(array $record, string $id = ''): string
    {
        return $this->render('goal_event_definition_edit.php', [
            'headline' => 'Goal Event Definition', 'propertyId' => '7000000000000001',
            'goalEventDefinition' => $record, 'goalEventDefinitionId' => $id,
            'conditionProperties' => [['name' => 'page_path', 'label' => 'Page path',
                'description' => 'The path of the page.', 'carried' => true]],
            'conditionVocabulary' => ['page_view' => []],
            'savedProperties' => [],
            'triggerEvent' => 'page_view',
            'triggerEvents' => ['page_view', 'click'],
            'customTriggerEvents' => [], 'customTriggerEvent' => '',
            'conditions' => [], 'validation_errors' => [],
        ]);
    }

    private function render(string $file, array $vars): string
    {
        $t = new class('base') extends \OWA\Core\Template {

            function createNonceFormField($action) { return "<!--nonce:$action-->"; }

            function makeLink($params = [], $add_state = false, $url = '', $xml = false, $add_nonce = false) {

                return 'LINK';
            }

            function getNs() { return \OWA\Core\CoreAPI::appNs(); }
        };

        foreach ($vars as $k => $v) {
            $t->set($k, $v);
        }

        $this->assertTrue($t->set_template($file), "could not locate template $file");

        return $t->fetch();
    }
}
