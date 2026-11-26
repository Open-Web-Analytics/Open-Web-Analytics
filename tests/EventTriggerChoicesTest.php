<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\GoalVocabulary;
use OWA\Module\Base\Entity\GoalEventDefinition;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The event picker's choices (a goal's trigger, a funnel step's event), as
 * the field chooser groups them: "Built-in events", each described, then
 * "Your events" and "Another event of yours…".
 */
final class EventTriggerChoicesTest extends TestCase
{
    public function testBuiltInEventsAreDescribedAndTheSitesOwnFollow(): void
    {
        $choices = GoalVocabulary::triggerChoices(['click', 'page_view'], ['newsletter_signup']);

        $this->assertSame(['click', 'page_view', 'newsletter_signup', GoalEventDefinition::TRIGGER_CUSTOM],
            array_column($choices, 'value'));
        $this->assertSame(['Built-in events', 'Built-in events', 'Your events', 'Your events'],
            array_column($choices, 'group_label'));
        $this->assertSame(['built_in', 'built_in', 'yours', 'yours'], array_column($choices, 'group'));

        $this->assertStringStartsWith('A page was viewed.', $choices[1]['description']);
        $this->assertSame('', $choices[2]['description'], 'a site\'s own event says nothing about itself');
        $this->assertSame('Another event of yours…', $choices[3]['label']);
        $this->assertNotSame('', $choices[3]['description']);
    }

    public function testADescriptionIsTranslated(): void
    {
        $english = \OWA\Module\Base\Classes\TrackingEventHelpers::eventDescription('page_view');

        \OWA\Core\Strings::useTranslations([$english => 'Une page a été vue.']);

        try {
            $this->assertSame('Une page a été vue.', \OWA\Module\Base\Classes\TrackingEventHelpers::eventDescription('page_view'));
            $this->assertSame('', \OWA\Module\Base\Classes\TrackingEventHelpers::eventDescription('newsletter_signup'));
        } finally {
            \OWA\Core\Strings::useTranslations(null);
        }
    }
}
