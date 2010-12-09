<?php
require_once 'PHPUnit/Framework/TestCase.php';
require_once 'Net/GeoIP.php';

class Net_GeoIPTest extends PHPUnit_Framework_TestCase
{

    public function testShouldHaveBetterTestCoverage() {
        $this->markTestIncomplete('
    protected function getOrg($ipnum)
    protected function getRecord($ipnum)
    protected function getRegion($ipnum)
    protected function loadSharedMemory($filename)
    protected function lookupCountryId($addr)
    protected function seekCountry($ipnum)
    protected function setupSegments()
    public function __construct($filename = null, $flags = null)
    public function __destruct()
    public function close()
    public function lookupCountryCode($addr)
    public function lookupCountryName($addr)
    public function lookupLocation($addr)
    public function lookupOrg($addr)
    public function lookupRegion($addr)
    public function open($filename, $flags = null)
    public static function getInstance($filename = null, $flags = null)');
    }

    public function testBug17262() {
        $path = dirname(dirname(__FILE__)) . '/data/GeoLiteCity.dat';

        if (!file_exists($path)) {
            $this->markTestSkipped("Could not find GeoLiteCity.dat in " . $path . "\nObtain from http://www.maxmind.com/app/geolitecity");
        }

        $geoip = Net_GeoIP::getInstance($path);
        $location = $geoip->lookupLocation('24.24.24.24');

        $this->assertSame(array('countryCode' => 'US',
                                'countryCode3' => 'USA',
                                'countryName' => 'United States',
                                'region' => 'NY',
                                'city' => 'Jamaica',
                                'postalCode' => '11434',
                                'latitude' => 40.6763,
                                'longitude' => -73.7752,
                                'areaCode' => 718,
                                'dmaCode' => 501.00
                                ),
                        $location->getData());
    }
}