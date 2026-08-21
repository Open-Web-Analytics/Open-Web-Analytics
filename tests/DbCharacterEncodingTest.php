<?php

use PHPUnit\Framework\TestCase;

/**
 * The schema encoding and the connection encoding must be one decision.
 *
 * They were three separate hard-coded 'utf8' literals -- the PDO DSN, the
 * mysqli set_charset call, and its SET NAMES fallback -- none of which read the
 * constant the TABLES are declared with. So the schema encoding looked
 * configurable and was not: changing the constant would have altered the DDL
 * while every connection still negotiated three-byte utf8.
 *
 * That matters because the connection is the BINDING constraint of the two. A
 * four-byte character is mangled in transit by a three-byte connection however
 * the column is declared, so a utf8mb4 schema reached over a utf8 connection
 * still loses emoji -- and loses them in the confusing way, where the schema
 * says the value should fit.
 *
 * Both now come from OWA_DTD_CHARACTER_ENCODING_UTF8, which a new installation
 * can set through OWA_DB_CHARACTER_ENCODING in owa-config.php before its first
 * table exists. No migration is involved, which is the point: converting a 1.x
 * schema in place is work that v2's schema throws away.
 */
final class DbCharacterEncodingTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    public function testTheDefaultIsUnchanged(): void {

        // Existing installations must not silently start declaring something
        // else: new tables in a new encoding alongside old tables in the old
        // one is worse than either, and there is no migration to reconcile them.
        if ( ! defined( 'OWA_DB_CHARACTER_ENCODING' ) ) {

            $this->assertSame( 'utf8', OWA_DTD_CHARACTER_ENCODING_UTF8,
                'the default encoding must stay utf8 for installations that set nothing' );
        } else {

            $this->assertSame( OWA_DB_CHARACTER_ENCODING, OWA_DTD_CHARACTER_ENCODING_UTF8,
                'a configured encoding must be what the schema is declared with' );
        }
    }

    public function testTheConnectionUsesTheDeclaredEncoding(): void {

        $driver = \OWA\Core\CoreAPI::dbSingleton();

        $m = new ReflectionMethod( $driver, 'connectionCharacterEncoding' );
        $m->setAccessible( true );

        $this->assertSame(
            (string) OWA_DTD_CHARACTER_ENCODING_UTF8,
            $m->invoke( $driver ),
            'the connection must negotiate the same encoding the schema is declared with'
        );
    }

    /**
     * The DSN is where PDO's quote() gets its charset too, so a wrong value
     * there is an escaping bug as well as an encoding one.
     */
    public function testThePdoDsnCarriesTheDeclaredEncoding(): void {

        $driver = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $driver instanceof \OWA\Core\Db\PdoMysql ) {

            $this->markTestSkipped( 'not running on the PDO driver' );
        }

        $m = new ReflectionMethod( $driver, 'dsn' );
        $m->setAccessible( true );

        $this->assertStringContainsString(
            'charset=' . OWA_DTD_CHARACTER_ENCODING_UTF8,
            $m->invoke( $driver )
        );
    }

    /**
     * The guard that actually holds. While the declared encoding IS utf8, a
     * hard-coded 'utf8' and the constant produce identical behaviour, so no
     * behavioural assertion can separate them -- what is checkable is that the
     * drivers carry no encoding literal of their own.
     *
     * Single-quoted needles: a double-quoted one containing $this-> would
     * interpolate away and pass unconditionally.
     */
    public function testTheDriversHardCodeNoEncoding(): void {

        $root = dirname( __DIR__ );

        foreach ( [ 'Core/Db/PdoMysql.php', 'Core/Db/Mysql.php' ] as $relative ) {

            $source = (string) file_get_contents( $root . '/' . $relative );

            // Strip comments before looking: the explanation of why utf8 is the
            // wrong thing to hard-code necessarily mentions utf8.
            $code = '';

            foreach ( token_get_all( $source ) as $token ) {

                if ( is_array( $token ) && in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
                    continue;
                }

                $code .= is_array( $token ) ? $token[1] : $token;
            }

            foreach ( [ "'utf8'", '"utf8"', 'charset=utf8', "'utf8mb4'", "'utf8mb3'" ] as $needle ) {

                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    sprintf(
                        '%s hard-codes %s. The connection encoding must come from '
                      . 'OWA_DTD_CHARACTER_ENCODING_UTF8, or it silently stops matching the schema '
                      . 'the moment an installation configures a different one.',
                        $relative,
                        $needle
                    )
                );
            }
        }
    }

    /**
     * The admin wrapper declared UTF-8 and the public one declared ISO-8859-1,
     * while both render the same UTF-8 content. The public wrapper is the login
     * form, the password reset and the installer -- the pages most likely to
     * carry a name with an accent in it.
     */
    public function testEveryTemplateWrapperDeclaresUtf8(): void {

        $dir = dirname( __DIR__ ) . '/modules/Base/templates';

        foreach ( glob( $dir . '/wrapper_*.php' ) as $file ) {

            $html = (string) file_get_contents( $file );

            if ( stripos( $html, 'charset=' ) === false ) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/charset=["\']?utf-?8/i',
                $html,
                sprintf( '%s declares a charset that is not UTF-8, but renders UTF-8 content',
                    basename( $file ) )
            );
        }
    }
}
