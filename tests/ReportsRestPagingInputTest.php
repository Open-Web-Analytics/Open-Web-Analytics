<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * A REST request's page size and page are whole numbers, and the page size has
 * a ceiling.
 *
 * resultsPerPage was passed through as given: no ceiling, so any reader of
 * reports could have a query fetch ten pages of any size into PHP; and text,
 * which PHP 8 refuses in arithmetic, ended the request in a TypeError.
 */
final class ReportsRestPagingInputTest extends TestCase
{
    /** @return array<string, array{mixed, int, int}> value, max, expected */
    public static function values(): array
    {
        $max = \OWA\Module\Base\Controller\ReportsRest::MAX_RESULTS_PER_PAGE;

        return array(
            'a number'          => array( '25', $max, 25 ),
            'past the ceiling'  => array( '1000000', $max, $max ),
            'text'              => array( 'abc', $max, 1 ),
            'zero'              => array( '0', $max, 1 ),
            'negative'          => array( '-5', $max, 1 ),
            'a fraction'        => array( '2.9', $max, 2 ),
            'a page, uncapped'  => array( '1000000', PHP_INT_MAX, 1000000 ),
        );
    }

    /**
     * @dataProvider values
     */
    public function testAPageValueIsAWholeNumberWithinItsRange( $value, int $max, int $expected ): void
    {
        $this->assertSame( $expected, \OWA\Module\Base\Controller\ReportsRest::wholeNumber( $value, $max ) );
    }

    public function testTheCeilingCoversTheWidgetsThatAskForMost(): void
    {
        $this->assertGreaterThanOrEqual( 1000, \OWA\Module\Base\Controller\ReportsRest::MAX_RESULTS_PER_PAGE );
    }

    /** A card's Show more stops at the ceiling, so every page tells the grid what it is. */
    public function testEveryPageGivesTheGridTheCeiling(): void
    {
        ob_start();

        try {
            include OWA_DIR . 'modules/Base/templates/config_dom.php';
        } finally {
            $js = (string) ob_get_clean();
        }

        $this->assertStringContainsString(
            'OWA.config.max_results_per_page = ' . \OWA\Module\Base\Controller\ReportsRest::MAX_RESULTS_PER_PAGE . ';',
            $js );

        $this->assertStringContainsString( "OWA.getSetting( 'max_results_per_page' )",
            (string) file_get_contents( OWA_DIR . 'modules/Base/src/reporting/v1/owa.resultSetExplorer.js' ) );
    }
}
