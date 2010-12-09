<?php
if (!defined('PHPUnit_MAIN_METHOD')) {
    define('PHPUnit_MAIN_METHOD', 'Net_GeoIP_AllTests::main');
}


/*
 * Files needed by PhpUnit
 */
require_once 'PHPUnit/Framework.php';
require_once 'PHPUnit/TextUI/TestRunner.php';

/**
 * File_IMC_ParseTest
 */
require_once 'Net_GeoIPTest.php';
require_once 'Net_GeoIP_LocationTest.php';
require_once 'Net_GeoIP_DMATest.php';


/**
 * Master Unit Test Suite class for File_IMC
 *
 * This top-level test suite class organizes
 * all class test suite files,
 * so that the full suite can be run
 * by PhpUnit or via "pear run-tests -up File_IMC".
 *
 * @category   File
 * @package    File_IMC
 * @subpackage UnitTesting
 * @author     Chuck Burgess <ashnazg@php.net>
 * @license    http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @version    Release: @package_version@
 * @link       http://pear.php.net/package/File_IMC
 * @since      0.8.0
 */
class Net_GeoIP_AllTests
{

    /**
     * Launches the TextUI test runner
     *
     * @return void
     * @uses PHPUnit_TextUI_TestRunner
     */
    public static function main()
    {
        PHPUnit_TextUI_TestRunner::run(self::suite());
    }


    /**
     * Adds all class test suites into the master suite
     *
     * @return PHPUnit_Framework_TestSuite a master test suite
     *                                     containing all class test suites
     * @uses PHPUnit_Framework_TestSuite
     */
    public static function suite()
    {
        $suite = new PHPUnit_Framework_TestSuite('Net_GeoIP Tests');

        /*
         * You must add each additional class-level test suite name here
         */
        $suite->addTestSuite('Net_GeoIPTest');
        $suite->addTestSuite('Net_GeoIP_LocationTest');
        $suite->addTestSuite('Net_GeoIP_DMATest');

        return $suite;
    }
}


if (PHPUnit_MAIN_METHOD == 'Net_GeoIP_AllTests::main') {
    Net_GeoIP_AllTests::main();
}