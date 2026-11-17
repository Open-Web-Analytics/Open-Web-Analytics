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
 * The filter pills read a stored string back into pills the same way, through
 * the grid's clause reader, OWA.dataGrid.parseClause().
 */
final class ConstraintOperatorsJsTest extends TestCase
{
    private const EXPLORER = 'modules/Base/src/reporting/owa.resultSetExplorer.js';
    private const PILLS    = 'modules/Base/src/reporting/owa.filterpills.js';

    public function testEveryPageGivesTheGridTheServersOperatorsInOrder(): void
    {
        ob_start();

        try {
            include \OWA\Core\Env::root() . '/modules/Base/templates/config_dom.php';
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
        $src = (string) file_get_contents( \OWA\Core\Env::root() . '/' . self::EXPLORER );

        $this->assertStringContainsString( "OWA.getSetting( 'constraint_operators' )", $src );
        $this->assertStringNotContainsString( 'OWA.dataGrid.OPERATORS =', $src,
            'the grid carries its own copy of the operator list again' );
    }

    /** A chip has words for every operator the server parses, and for no other. */
    public function testEveryOperatorHasAChipPhrase(): void
    {
        $src = (string) file_get_contents( \OWA\Core\Env::root() . '/' . self::EXPLORER );

        $this->assertSame( 1, preg_match( '/OWA\.dataGrid\.OPERATOR_PHRASES = \{(.*?)\};/s', $src, $block ) );
        preg_match_all( "/^\s*'([^']+)':/m", $block[1], $keys );

        $this->assertSame( array_keys( ResultSetManager::CONSTRAINT_OPERATORS ), $keys[1] );
    }

    /** The pills split a clause with the grid's reader of the page's list. */
    public function testThePillsSplitWithTheGridsReader(): void
    {
        $src = (string) file_get_contents( \OWA\Core\Env::root() . '/' . self::PILLS );

        $this->assertSame( 1, preg_match( '/OWA\.filterPills\.split = function \( clause \) \{(.*?)\n\};/s', $src, $split ) );
        $this->assertStringContainsString( 'OWA.dataGrid.parseClause( clause )', $split[1] );
    }

    /**
     * The reporting scripts read a clause in one place, and word its operator
     * from one table of operators and one of empty tests.
     */
    public function testTheReportingScriptsHaveOneReaderAndOneTableOfEach(): void
    {
        $src = '';

        foreach ( glob( \OWA\Core\Env::root() . '/modules/Base/src/reporting/*.js' ) as $file ) {

            $src .= (string) file_get_contents( $file );
        }

        $this->assertSame( 1, substr_count( $src, 'OWA.dataGrid.operators()' ), 'one clause reader' );
        $this->assertSame( 1, preg_match_all( '/EMPTY_TESTS\s*=\s*\{/', $src ), 'one table of empty tests' );
        $this->assertSame( 1, preg_match_all( '/OPERATOR_PHRASES\s*=\s*\{/', $src ), 'one table of operator words' );
        $this->assertSame( 0, preg_match_all( '/\bOPERATORS\s*=\s*\{/', $src ), 'a second table of operator words' );
    }

    /** The Jest files stand in for config_dom.php, so their list is the server's. */
    public function testTheGridAndPillsTestsUseTheServersList(): void
    {
        foreach ( array( 'tests/js/DataGrid.test.js', 'tests/js/FilterPills.test.js' ) as $file ) {

            $src = (string) file_get_contents( \OWA\Core\Env::root() . '/' . $file );

            $this->assertSame( 1, preg_match( '/const SERVER_OPERATORS = \[(.*?)\];/', $src, $list ), $file );
            preg_match_all( "/'([^']+)'/", $list[1], $ops );

            $this->assertSame( array_keys( ResultSetManager::CONSTRAINT_OPERATORS ), $ops[1], $file );
        }
    }
}
