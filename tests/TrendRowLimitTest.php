<?php

use PHPUnit\Framework\TestCase;

/**
 * A query by date that names no page size gets one row per day of the period.
 *
 * A trend card asks for no page size, so this is the limit every trend runs
 * under. It was the day DIFFERENCE: a week of days returned seven rows of
 * eight and dropped the last, which sorted by date was today.
 *
 * Clicks, fired as real events, because they are the fact a test here can
 * produce through logEvent with the fewest moving parts; `date` is on every
 * fact table.
 */
final class TrendRowLimitTest extends TestCase
{
    private static $siteId = '';
    private static $siteRowId = '';
    private static $propertyId = '';

    private const DOMAIN = 'https://trend-row-limit-test.example.com';

    private static $seeded = false;

    /** One click per day at local noon, 20 to 13 days ago: eight days. */
    private static function days(): array
    {
        $out = array();

        for ($ago = 20; $ago >= 13; $ago--) {
            $out[] = mktime(12, 0, 0, (int) date('n'), (int) date('j') - $ago, (int) date('Y'));
        }

        return $out;
    }

    /** Fourteen clicks on fourteen elements, 10 days ago. */
    private static function oneDay(): array
    {
        $out = array();

        for ($i = 0; $i < 14; $i++) {
            $out[] = mktime(6 + $i, 15, 0, (int) date('n'), (int) date('j') - 10, (int) date('Y'));
        }

        return $out;
    }

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';

        if (!owa_test_db_available()) {
            return;
        }

        \OWA\Core\CoreAPI::forgetRegisteredSites();

        $sm   = \OWA\Core\CoreAPI::supportClassFactory('base', 'siteManager');
        $site = $sm->createNewSite(self::DOMAIN, 'Trend row limit test');

        self::$siteId     = (string) $site->get('site_id');
        self::$siteRowId  = (string) $site->get('id');
        self::$propertyId = (string) $site->get('property_id');

        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('these rows are read out of the database');
        }

        $this->seed();
    }

    public static function tearDownAfterClass(): void
    {
        if (!function_exists('owa_test_db_available') || !owa_test_db_available()) {
            return;
        }

        $db = owa_coreAPI::dbSingleton();

        foreach (array('owa_click', 'owa_request', 'owa_session') as $table) {
            $db->query("DELETE FROM $table WHERE site_id = ?", array(self::$siteId));
        }

        if (self::$siteRowId) {
            $db->query('DELETE FROM owa_site WHERE id = ?', array(self::$siteRowId));
        }

        if (self::$propertyId) {
            $db->query('DELETE FROM owa_property WHERE id = ?', array(self::$propertyId));
        }

        \OWA\Core\CoreAPI::forgetRegisteredSites();
    }

    private function seed(): void
    {
        if (self::$seeded) {
            return;
        }

        $rc      = owa_coreAPI::requestContainerSingleton();
        $visitor = $this->guid();
        $session = $this->guid();

        foreach (array_merge(self::days(), self::oneDay()) as $i => $instant) {

            $rc->timestamp = $instant;

            $event = owa_coreAPI::supportClassFactory('base', 'event');
            $event->setEventType('dom.click');
            $event->setProperties(array(
                'site_id'          => self::$siteId,
                'session_id'       => $session,
                'visitor_id'       => $visitor,
                'guid'             => $this->guid(),
                'page_url'         => self::DOMAIN . '/',
                'page_title'       => 'Trend row limit',
                'target_url'       => self::DOMAIN . '/#el' . $i,
                'click_x'          => 10 + $i,
                'click_y'          => 20 + $i,
                'page_width'       => 1280,
                'page_height'      => 2000,
                'dom_element_id'   => 'el' . $i,
                'dom_element_tag'  => 'a',
                'HTTP_USER_AGENT'  => 'owa-trend-row-limit-test',
                'ip_address'       => '203.0.113.78',
            ));

            owa_coreAPI::logEvent('dom.click', $event);
        }

        $rc->timestamp = time();

        self::$seeded = true;
    }

    /** Numeric, because visitor_id and session_id are BIGINT. */
    private function guid(): string
    {
        return ((string) time())
            . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
            . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }

    /** A query over the dates, with no page size set. */
    private function results(string $dimension, int $start, int $end)
    {
        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        $rsm->metrics = $rsm->metricsStringToArray('domClicks');
        $rsm->setDimensions(array($dimension));
        $rsm->setTimePeriod('date_range', date('Ymd', $start), date('Ymd', $end));
        $rsm->setSiteId(self::$siteId);
        $rsm->setSorts($rsm->sortStringToArray($dimension));

        return $rsm->getResults();
    }

    public function testADailyTrendKeepsItsLastDay(): void
    {
        $days = self::days();
        $rs   = $this->results('date', $days[0], end($days));

        $got = array_map(function ($row) {
            return (string) $row['date']['value'];
        }, (array) $rs->resultsRows);

        $this->assertSame(array_map(function ($t) {
            return date('Ymd', $t);
        }, $days), $got);
    }

    /**
     * A query by anything but date is not a trend: over one day it has no
     * limit, so a breakdown pages at the result set's default rather than
     * being cut to one row per day.
     */
    public function testABreakdownOfOneDayPagesRatherThanStoppingAtOneRow(): void
    {
        $day = self::oneDay()[0];
        $rs  = $this->results('domElementId', $day, $day);

        $this->assertCount(10, (array) $rs->resultsRows);
        $this->assertTrue((bool) $rs->more, 'fourteen elements, so there is a next page');
    }
}
