<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * makeUrlCanonical() removes the filtered query parameters and nothing else
 * (#895).
 *
 * It used a regex that took a filtered parameter and everything after it,
 * matched a name inside a longer one, and read an empty name -- a trailing
 * comma in the list -- as every parameter. So
 * index.php?route=product/product&path=59_65&product_id=50 was stored as
 * index.php?route=product/product.
 */
final class CanonicalUrlQueryFiltersTest extends TestCase
{
    /** @var mixed the install's query_string_filters before the test */
    private $filters;

    protected function setUp(): void
    {
        $this->filters = \OWA\Core\CoreAPI::getSetting('base', 'query_string_filters');
    }

    protected function tearDown(): void
    {
        \OWA\Core\CoreAPI::setSetting('base', 'query_string_filters', $this->filters);
    }

    private function canonical(string $url, string $filters = ''): string
    {
        \OWA\Core\CoreAPI::setSetting('base', 'query_string_filters', $filters);

        $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        $event->setSiteId('no-such-site-for-this-test');

        return (string) Helpers::makeUrlCanonical($url, $event);
    }

    public function testParametersSurviveWhenNoneIsFiltered(): void
    {
        $url = 'http://shop.example.com/index.php?route=product/product&path=59_65&product_id=50';

        $this->assertSame($url, $this->canonical($url));
    }

    public function testAFilteredParameterLeavesTheOnesAfterIt(): void
    {
        $this->assertSame('http://shop.example.com/index.php?route=product/product&product_id=50',
            $this->canonical('http://shop.example.com/index.php?route=product/product&path=59_65&product_id=50', 'path'));
    }

    public function testANameIsMatchedWhole(): void
    {
        $this->assertSame('http://shop.example.com/p?product_id=50',
            $this->canonical('http://shop.example.com/p?product_id=50&id=7', 'id'));
    }

    /** Spaces around names, and a trailing comma, in the list an operator typed. */
    public function testTheListIsTrimmedAndAnEmptyNameMatchesNothing(): void
    {
        $this->assertSame('http://shop.example.com/p?a=1&b=2',
            $this->canonical('http://shop.example.com/p?a=1&sessionid=x&b=2&path=3', 'path, sessionid,'));
    }

    public function testOwasOwnParametersGoWhereverTheyAre(): void
    {
        $this->assertSame('http://shop.example.com/p?a=1',
            $this->canonical('http://shop.example.com/p?owa_source=x&a=1&owa_medium=y'));
    }

    public function testNoQuestionMarkIsLeftWithNothingAfterIt(): void
    {
        $this->assertSame('http://shop.example.com/p', $this->canonical('http://shop.example.com/p?owa_campaign=z'));
    }

    /** As the regex did, 1.x ignores case in a name. */
    public function testANameMatchesIgnoringCase(): void
    {
        $this->assertSame('http://shop.example.com/p?a=1',
            $this->canonical('http://shop.example.com/p?SessionID=x&a=1', 'sessionid'));
    }

    public function testAFilteredNameInsideAValueIsNotAParameter(): void
    {
        $this->assertSame('http://shop.example.com/p?q=path%3D1&id=2',
            $this->canonical('http://shop.example.com/p?q=path%3D1&id=2', 'path'));
    }
}
