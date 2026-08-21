<?php

use PHPUnit\Framework\TestCase;

/**
 * Values reach the database as they arrived.
 *
 * Sanitize::stripSql() presented itself as an SQL defence and was not one. It
 * deleted parentheses, commas, and any word matching a list of SQL keywords
 * from the value:
 *
 *   "How to update your camera"        -> "How to  your camera"
 *   "https://example.com/select-a-print/" -> "https://example.com/-a-print/"
 *   "Mozilla/5.0 (Macintosh; ...)"     -> "Mozilla/5.0 Macintosh; ..."
 *
 * It defended nothing -- values are escaped by the driver, identifiers are
 * checked against the registry -- and it corrupted whatever passed through it.
 * On this installation it ran on user agents for six months, giving every
 * browser two identities and roughly quadrupling the cardinality of the
 * user-agent dimension. Reports over that window are still wrong.
 *
 * It was reachable because Db::prepare() offered it as the base-class escape,
 * so any driver that did not override prepare() silently mangled every value it
 * wrote. There is no correct database-agnostic escape -- escaping depends on
 * the connection's character set -- so the base class now refuses instead of
 * offering a plausible-looking one.
 */
final class NoContentManglingTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    public function testTheManglingHelperIsGone(): void {

        $this->assertFalse(
            method_exists( \OWA\Module\Base\Classes\Sanitize::class, 'stripSql' ),
            'stripSql corrupts every value it touches and defends nothing. It must not come back.'
        );
    }

    /**
     * A driver that does not escape is a defect, and must say so rather than
     * quietly writing whatever it was given.
     */
    public function testTheBaseClassRefusesToPretendToEscape(): void {

        // Without the constructor: prepare() does not depend on a connection,
        // and the base class takes five arguments a test has no business
        // inventing.
        $driver = ( new ReflectionClass( \OWA\Core\Db::class ) )->newInstanceWithoutConstructor();

        $this->expectException( \RuntimeException::class );

        $driver->prepare( 'anything' );
    }

    /**
     * The real drivers escape rather than delete. A stripped quote and an
     * escaped quote both produce valid SQL, so only the round trip below tells
     * them apart.
     */
    public function testTheDriverEscapesRatherThanRemoves(): void {

        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'No database available.' );
        }

        $prepared = \OWA\Core\CoreAPI::dbSingleton()->prepare( "O'Brien (photographer), update" );

        foreach ( [ 'O', 'Brien', '(', ')', ',', 'update' ] as $fragment ) {

            $this->assertStringContainsString(
                $fragment,
                $prepared,
                sprintf( 'escaping must preserve %s, not delete it', var_export( $fragment, true ) )
            );
        }
    }

    /**
     * End to end, on the shapes that were actually corrupted: a user agent, a
     * page title containing an SQL keyword, and a URL containing one.
     */
    public function testRealContentSurvivesAWriteAndReadIntact(): void {

        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'No database available.' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query( 'CREATE TEMPORARY TABLE owa_mangle_probe (id BIGINT, v VARCHAR(255))' );

        $values = [
            1 => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko)',
            2 => 'How to update your camera settings',
            3 => 'https://example.com/select-a-print/?merge=1',
            4 => "Landscapes, Portraits (2026) - O'Brien",
        ];

        foreach ( $values as $id => $v ) {

            $db->query( sprintf(
                "INSERT INTO owa_mangle_probe (id, v) VALUES (%d, '%s')", $id, $db->prepare( $v ) ) );
        }

        foreach ( $values as $id => $expected ) {

            $row = $db->get_row( sprintf( 'SELECT v FROM owa_mangle_probe WHERE id = %d', $id ) );

            $this->assertSame(
                $expected,
                (string) ( $row['v'] ?? '' ),
                'the value must come back exactly as it went in'
            );
        }
    }
}
