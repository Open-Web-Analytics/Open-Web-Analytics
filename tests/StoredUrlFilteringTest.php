<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Event;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Every URL a row stores is filtered as page_location is: no fragment, none
 * of the query_string_filters parameters, none of OWA's control parameters
 * (owa_overlay, owa_state), and at most 1024 characters
 * (TrackingEventHelpers::filterStoredUrls()).
 *
 * Driven from the property registry, so a URL property added later is
 * filtered or this fails. No database: the Profile's query_string_filters
 * is the install's.
 */
final class StoredUrlFilteringTest extends TestCase
{
    /** @var mixed */
    private $filters;

    protected function setUp(): void
    {
        $this->filters = \OWA\Core\CoreAPI::getSetting('base', 'query_string_filters');
        \OWA\Core\CoreAPI::setSetting('base', 'query_string_filters', 'secret, email');
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'query_string_filters', $this->filters);
    }

    private function filtered(string $property, string $url)
    {
        $event = new Event();
        $event->setEventType('form_submit');
        $event->set('site_id', md5('stored-url-test'));
        $event->set($property, $url);

        Helpers::filterStoredUrls($event);

        return $event->get($property);
    }

    /** Each URL property the registry declares is stored, or is internal and stored by nothing. */
    public function testEveryUrlPropertyIsFilteredOrNotStored(): void
    {
        $stored = Helpers::storedUrlProperties();

        foreach (['page_location', 'HTTP_REFERER', 'target_url', 'form_destination'] as $known) {
            $this->assertContains($known, $stored);
        }

        foreach (Helpers::allProperties() as $name => $definition) {
            if (($definition['data_type'] ?? '') !== 'url' || in_array($name, $stored, true)) {
                continue;
            }

            $this->assertTrue(Helpers::isInternal($name), "$name is a URL with no column or param, and not internal");
            $this->assertSame('', Helpers::columnFor($name), $name);
        }
    }

    public function testEachStoredUrlLosesItsFragmentTheFilteredAndTheControlParameters(): void
    {
        foreach (Helpers::storedUrlProperties() as $property) {
            $this->assertSame('https://example.test/p?keep=1&owa_campaign=spring',
                $this->filtered($property,
                    'https://example.test/p?keep=1&secret=s3&owa_state=v.123.s.456&owa_campaign=spring&owa_overlay=x#access_token=abc'),
                $property);

            $this->assertSame('https://example.test/app', $this->filtered($property, 'https://example.test/app#/route/a'), $property);
            $this->assertSame('https://example.test/a?b=1', $this->filtered($property, 'https://example.test/a?b=1'), $property);
        }
    }

    public function testEachStoredUrlIsCutTo1024Characters(): void
    {
        $long = 'https://example.test/' . str_repeat('é', 1100);

        foreach (Helpers::storedUrlProperties() as $property) {
            $stored = $this->filtered($property, $long);

            $this->assertSame(1024, mb_strlen($stored, 'UTF-8'), $property);
            $this->assertStringStartsWith('https://example.test/éé', $stored, $property);
        }
    }

    /** A URL that is nothing but a fragment is absent. */
    public function testAFragmentAloneIsAbsent(): void
    {
        $this->assertFalse($this->filtered('form_destination', '#top'));
    }
}
