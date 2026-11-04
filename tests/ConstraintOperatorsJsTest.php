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
 */
final class ConstraintOperatorsJsTest extends TestCase
{
    private const EXPLORER = 'modules/Base/src/reporting/v1/owa.resultSetExplorer.js';

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

    /** The Jest file stands in for config_dom.php, so its list is the server's. */
    public function testTheGridTestsUseTheServersList(): void
    {
        $src = (string) file_get_contents( OWA_DIR . 'tests/js/DataGrid.test.js' );

        $this->assertSame( 1, preg_match( '/const SERVER_OPERATORS = \[(.*?)\];/', $src, $list ) );
        preg_match_all( "/'([^']+)'/", $list[1], $ops );

        $this->assertSame( array_keys( ResultSetManager::CONSTRAINT_OPERATORS ), $ops[1] );
    }
}
