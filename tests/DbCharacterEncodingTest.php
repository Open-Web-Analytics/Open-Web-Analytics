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

    public function testTheConnectionUsesTheDeclaredEncoding(): void {

        $driver = \OWA\Core\CoreAPI::dbSingleton();

        $m = new ReflectionMethod( $driver, 'connectionCharacterEncoding' );
        $m->setAccessible( true );

        $this->assertSame(
            (string) OWA_DTD_CONNECTION_ENCODING,
            $m->invoke( $driver ),
            'the connection must negotiate the widest encoding, not any one table\'s'
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
            'charset=' . OWA_DTD_CONNECTION_ENCODING,
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

    /**
     * The connection is a CEILING, so it is the widest encoding rather than any
     * particular table's -- and raising it must cost the older tables nothing.
     */
    public function testTheConnectionIsWiderThanTheDefaultTableEncoding(): void
    {
        $this->assertSame( 'utf8mb4', (string) OWA_DTD_CONNECTION_ENCODING,
            'the connection should negotiate the widest encoding available' );

        $this->assertSame( 'utf8', (string) OWA_DTD_CHARACTER_ENCODING_UTF8,
            'the default for NEW tables must stay what existing tables already are' );
    }

    /**
     * The point of the change. An entity naming its own encoding is how a v2
     * table gets created as utf8mb4 beside v1 tables that stay utf8, in one
     * database, with nothing converted.
     *
     * setCharacterEncoding() has existed all along and did nothing:
     * getTableOptions() returned the table_type VALUE rather than the options
     * array, so a second key could never be read.
     */
    public function testAnEntityCanNameItsOwnTableEncoding(): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.click' );

        $this->assertArrayNotHasKey( 'character_encoding', $entity->getTableOptions(),
            'absent must mean "use the installation default", not a value' );

        $entity->setCharacterEncoding( 'utf8mb4' );

        $options = $entity->getTableOptions();

        $this->assertSame( 'utf8mb4', $options['character_encoding'] ?? null,
            'an encoding set on the entity must reach the table options' );
        $this->assertSame( 'disk', $options['table_type'] ?? null,
            'the other options must survive alongside it' );
    }

    /**
     * End to end: a table really is created in the encoding its entity named,
     * over the shared connection, while the default stays what it was.
     */
    public function testATableIsCreatedInTheEncodingItsEntityNamed(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'No database available.' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( [ 'owa_enc_probe_default' => null, 'owa_enc_probe_mb4' => 'utf8mb4' ] as $table => $encoding ) {

            $db->query( sprintf( 'DROP TABLE IF EXISTS %s', $table ) );

            $charset = $encoding ?: OWA_DTD_CHARACTER_ENCODING_UTF8;
            $db->query( sprintf(
                'CREATE TABLE %s (id BIGINT) %s %s',
                $table,
                sprintf( OWA_DTD_TABLE_TYPE, OWA_DTD_TABLE_TYPE_DEFAULT ),
                sprintf( OWA_DTD_TABLE_CHARACTER_ENCODING, $charset )
            ) );
        }

        $row = $db->get_results(
            'SELECT TABLE_NAME t, CCSA.CHARACTER_SET_NAME c '
          . 'FROM information_schema.TABLES T '
          . 'JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA '
          . 'ON CCSA.COLLATION_NAME = T.TABLE_COLLATION '
          . 'WHERE T.TABLE_SCHEMA = DATABASE() AND T.TABLE_NAME LIKE "owa_enc_probe%"' );

        $found = [];
        foreach ( (array) $row as $r ) { $found[ $r['t'] ] = $r['c']; }

        foreach ( [ 'owa_enc_probe_default', 'owa_enc_probe_mb4' ] as $t ) {
            $db->query( sprintf( 'DROP TABLE IF EXISTS %s', $t ) );
        }

        $this->assertSame( 'utf8mb4', $found['owa_enc_probe_mb4'] ?? null,
            'a table naming utf8mb4 must be created as utf8mb4' );
        $this->assertStringStartsWith( 'utf8', (string) ( $found['owa_enc_probe_default'] ?? '' ),
            'the default table encoding must be unchanged' );
        $this->assertNotSame( $found['owa_enc_probe_mb4'] ?? null, $found['owa_enc_probe_default'] ?? null,
            'the two must genuinely differ -- that is the whole point' );
    }
}
