<?php
require_once 'PHPUnit/Framework/TestCase.php';

class Net_GeoIP_LocationTest extends PHPUnit_Framework_TestCase
{

    public function testShouldHaveBetterTestCoverage() {
        $this->markTestIncomplete('public function __get($name)
public function __isset($name)
public function __toString()
public function distance(Net_GeoIP_Location $loc)
public function getData()
public function serialize()
public function set($name, $val)
public function unserialize($serialized)');
    }
}