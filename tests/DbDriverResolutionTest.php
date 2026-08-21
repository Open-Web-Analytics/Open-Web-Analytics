<?php

use PHPUnit\Framework\TestCase;

/**
 * Whatever driver is chosen, the loader has to be able to find it.
 *
 * resolveDbDriver() turns the configured db_type into a driver token, and
 * setupStorageEngine() turns that token into the class owa_db_<token>. The two
 * halves are in different methods, so a token that names nothing loadable is
 * not a syntax error or a failed assertion anywhere -- it is an installation
 * that cannot open a database, reported as "Cannot locate proper db class".
 *
 * That is what made the fallback worth a test of its own. PDO is preferred
 * wherever pdo_mysql is present, which is every developer machine and every CI
 * runner, so the arm taken when it is ABSENT is the one nothing exercises --
 * while being the arm that matters to an installation that has only mysqli, the
 * configuration OWA supports until v2.
 *
 * The mapping assertions below are the readable half. The half that has teeth
 * is testEveryResolvableDriverNamesALoadableClass: it does not restate what the
 * tokens should be, it requires that each one resolves to a class, so a future
 * rename that de-syncs a token from its driver file fails here even if someone
 * updates the mapping expectations to match their change.
 */
final class DbDriverResolutionTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    public function testPdoIsPreferredWhenAvailable() {

        $this->assertSame(
            'pdo_mysql',
            \OWA\Core\CoreAPI::resolveDbDriver( 'mysql', true ),
            'pdo_mysql is the default wherever the extension is present'
        );
    }

    /**
     * An installation with mysqli and no pdo_mysql. Supported until v2.
     */
    public function testFallsBackToTheLegacyDriverWithoutPdo() {

        $this->assertSame(
            'mysql',
            \OWA\Core\CoreAPI::resolveDbDriver( 'mysql', false ),
            'without pdo_mysql the legacy driver must be chosen by its own token'
        );
    }

    /**
     * Naming mysqli in configuration opts out of PDO deliberately, and must do
     * so even where PDO is available -- that is the whole point of writing it.
     */
    public function testConfiguredMysqliOptsOutOfPdo() {

        $this->assertSame(
            'mysql',
            \OWA\Core\CoreAPI::resolveDbDriver( 'mysqli', true ),
            'db_type=mysqli must select the legacy driver even alongside PDO'
        );
    }

    public function testAnUnrecognisedTypePassesThrough() {

        // The third-party driver seam: plugins/db/owa_db_<type>.php.
        $this->assertSame(
            'acme',
            \OWA\Core\CoreAPI::resolveDbDriver( 'acme', true ),
            'an unknown type must be handed on untouched for the plugin seam'
        );
    }

    /**
     * The invariant, stated without reference to which token is which: every
     * driver this can select for a bundled type must resolve to a class that
     * exists. This is the assertion the previous fallback failed.
     */
    public function testEveryResolvableDriverNamesALoadableClass() {

        foreach ( [ 'mysql', 'mysqli' ] as $configured ) {

            foreach ( [ true, false ] as $pdo_available ) {

                $token = \OWA\Core\CoreAPI::resolveDbDriver( $configured, $pdo_available );
                $legacy_class = 'owa_db_' . $token;
                $resolved = \OWA\Core\Lib::resolveNamespacedClass( $legacy_class );

                $this->assertNotNull(
                    $resolved,
                    sprintf(
                        'db_type=%s with pdo_mysql %s resolves to driver "%s", and %s names no '
                      . 'bundled class -- an installation in this configuration cannot connect',
                        $configured,
                        $pdo_available ? 'present' : 'absent',
                        $token,
                        $legacy_class
                    )
                );

                $this->assertTrue(
                    class_exists( $resolved ),
                    sprintf( '%s does not exist, so driver "%s" cannot load', $resolved, $token )
                );
            }
        }
    }
}
