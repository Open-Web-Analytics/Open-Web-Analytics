<?php

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Module\Base\Classes\ResultSetManager;
use PHPUnit\Framework\TestCase;

/**
 * The report grid reads its filters with the server's operators.
 *
 * OWA.dataGrid parses a constraints string into chips, and it has to split a
 * clause where the server does: the first operator in
 * ResultSetManager::CONSTRAINT_OPERATORS found in it. It kept its own copy of
 * that list; the page now hands it the server's, through OWA.config.
 *
 * The filter pills of the report editor and the funnel builder read a stored
 * string back into pills the same way, through OWA.dataGrid.operators().
 */
final class ConstraintOperatorsJsTest extends TestCase
{
    private const EXPLORER = 'modules/Base/src/reporting/v1/owa.resultSetExplorer.js';
    private const PILLS    = 'modules/Base/src/reporting/v1/owa.filterpills.js';

    public function testEveryPageGivesTheGridTheServersOperatorsInOrder(): void
    {
        ob_start();

        try {
            include OWA_DIR . 'modules/Base/templates/config_dom.php';
        } finally {
            $js = (string) ob_get_clean();
        }

        $this->assertStringContainsString(
            'OWA.config.constraint_operators = '
          . json_encode( array_keys( ResultSetManager::CONSTRAINT_OPERATORS ) ) . ';',
            $js );
    }

    public function testTheGridReadsTheOperatorsFromThePage(): void
    {
        $src = (string) file_get_contents( OWA_DIR . self::EXPLORER );

        $this->assertStringContainsString( "OWA.getSetting( 'constraint_operators' )", $src );
        $this->assertStringNotContainsString( 'OWA.dataGrid.OPERATORS =', $src,
            'the grid carries its own copy of the operator list again' );
    }

    /** A chip has words for every operator the server parses, and for no other. */
    public function testEveryOperatorHasAChipPhrase(): void
    {
        $src = (string) file_get_contents( OWA_DIR . self::EXPLORER );

        $this->assertSame( 1, preg_match( '/OWA\.dataGrid\.OPERATOR_PHRASES = \{(.*?)\};/s', $src, $block ) );
        preg_match_all( "/^\s*'([^']+)':/m", $block[1], $keys );

        $this->assertSame( array_keys( ResultSetManager::CONSTRAINT_OPERATORS ), $keys[1] );
    }

    /**
     * The pills split a clause with the grid's reader of the page's list, not
     * with the conditions their form offers.
     */
    public function testThePillsSplitWithTheServersOperators(): void
    {
        $src = (string) file_get_contents( OWA_DIR . self::PILLS );

        $this->assertSame( 1, preg_match( '/OWA\.filterPills\.split = function \( clause \) \{(.*?)\n\};/s', $src, $split ) );
        $this->assertStringContainsString( 'OWA.dataGrid.operators()', $split[1] );
        $this->assertStringNotContainsString( 'OWA.filterPills.OPERATORS', $split[1],
            'the pills split with the operators their form offers again' );
    }

    /** The Jest files stand in for config_dom.php, so their list is the server's. */
    public function testTheGridAndPillsTestsUseTheServersList(): void
    {
        foreach ( array( 'tests/js/DataGrid.test.js', 'tests/js/FilterPills.test.js' ) as $file ) {

            $src = (string) file_get_contents( OWA_DIR . $file );

            $this->assertSame( 1, preg_match( '/const SERVER_OPERATORS = \[(.*?)\];/', $src, $list ), $file );
            preg_match_all( "/'([^']+)'/", $list[1], $ops );

            $this->assertSame( array_keys( ResultSetManager::CONSTRAINT_OPERATORS ), $ops[1], $file );
        }
    }
}
