<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Every setting, and every owa-config.php constant that is not a setting, has
 * a test of its effect, or is listed as having none yet.
 *
 * A test proves an item's effect when it would fail if OWA stopped honouring
 * it. Such a test carries one docblock line per item it proves, of the form
 * "* @covers-setting base.anonymize_ips" for a setting (module.key, as in
 * settings.php) or "* @covers-setting OWA_DEBUG" for a constant. PHPUnit
 * ignores the tag.
 *
 * tests/fixtures/settings_without_effect_tests.json holds the rest:
 *
 *   untested   items no test proves yet. The list only shrinks: an item that
 *              gains a marked test, or no longer exists, must leave it.
 *   exempt     items with no effect a test could observe, each with a reason.
 *
 * The items, read from code:
 *
 *   settings   Core\Module::settingsRegistry(): each module's settings.php,
 *              internal ones included, and is_active and schema_version.
 *   constants  every OWA_* name the shipped code reads with defined(),
 *              constant() or getenv(), and Core\Requirements' configuration
 *              constants and the modules' activated_by constants; less the
 *              OWA_DTD_* and OWA_SQL_* names the database driver defines,
 *              the flags OWA's own code defines (OWA_CLI, OWA_INSTALLING,
 *              OWA_UPDATING), the constants that set a setting (counted under
 *              the setting) and Requirements::RETIRED_CONSTANTS.
 */
final class SettingEffectTestsTest extends TestCase
{

    private const FIXTURE = 'tests/fixtures/settings_without_effect_tests.json';

    /** @return array{untested: list<string>, exempt: array<string, string>} */
    private static function fixture(): array
    {

        $file = \OWA\Core\Env::root() . '/' . self::FIXTURE;
        $data = json_decode( (string) file_get_contents( $file ), true );

        self::assertIsArray( $data, self::FIXTURE . ' is not valid JSON' );
        self::assertIsArray( $data['untested'] ?? null, self::FIXTURE . ' has no untested list' );
        self::assertIsArray( $data['exempt'] ?? null, self::FIXTURE . ' has no exempt section' );

        return [ 'untested' => array_values( $data['untested'] ), 'exempt' => $data['exempt'] ];
    }

    /**
     * Every setting id (module.key) and its paired constant, or ''.
     *
     * @return array<string, string>
     */
    private static function settings(): array
    {

        $out = [];

        foreach ( array_keys( \OWA\Core\Module::settingsRegistry()['fields'] ) as $id ) {

            [ $module, $key ] = explode( '|', $id, 2 );

            $out[ $module . '.' . $key ] = \OWA\Module\Base\Classes\Settings::constantForSetting( $module, $key );
        }

        return $out;
    }

    /**
     * The shipped PHP files: Core/, modules/, api/ and the entry points.
     * Not owa-config-dist.php, the example of the operator's own file.
     *
     * @return list<string>
     */
    private static function shippedSources(): array
    {

        $root  = \OWA\Core\Env::root() . '/';
        $files = array_diff( glob( $root . '*.php' ) ?: [], [ $root . 'owa-config-dist.php' ] );

        foreach ( [ 'Core', 'modules', 'api' ] as $dir ) {

            $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . $dir,
                FilesystemIterator::SKIP_DOTS ) );

            foreach ( $it as $f ) {

                if ( substr( (string) $f, -4 ) === '.php' ) {

                    $files[] = (string) $f;
                }
            }
        }

        return $files;
    }

    /**
     * The owa-config.php constants that are not settings.
     *
     * @param  array<string, string> $settings
     * @return list<string>
     */
    private static function constants( array $settings ): array
    {

        $names = array_merge( \OWA\Core\Requirements::DATABASE_CONSTANTS,
            \OWA\Core\Requirements::URL_CONSTANTS, \OWA\Core\Requirements::SECRET_CONSTANTS );

        foreach ( \OWA\Core\Module::settingsRegistry()['activation'] as $activation ) {

            $names = array_merge( $names, $activation['activated_by'] );
        }

        $own = [];

        foreach ( self::shippedSources() as $file ) {

            $code = (string) file_get_contents( $file );

            preg_match_all( '/\b(?:defined|constant|getenv)\(\s*[\'"](OWA_[A-Z0-9_]+)[\'"]/', $code, $m );
            $names = array_merge( $names, $m[1] );

            // A statement that defines one: OWA's own flag, such as OWA_CLI.
            preg_match_all( '/^\s*define\(\s*[\'"](OWA_[A-Z0-9_]+)[\'"]/m', $code, $m );
            $own = array_merge( $own, $m[1] );
        }

        $paired  = array_flip( array_filter( $settings ) );
        $own     = array_flip( $own );
        $retired = \OWA\Core\Requirements::RETIRED_CONSTANTS;

        $names = array_filter( array_unique( $names ), fn ( $c ) => ! preg_match( '/^OWA_(DTD|SQL)_/', $c )
            && ! isset( $paired[ $c ] ) && ! isset( $retired[ $c ] ) && ! isset( $own[ $c ] ) );

        sort( $names );

        return $names;
    }

    /**
     * The items each test marks: id => list of "file::line".
     *
     * @return array<string, list<string>>
     */
    private static function markers(): array
    {

        $root = \OWA\Core\Env::root() . '/';
        $it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . 'tests',
            FilesystemIterator::SKIP_DOTS ) );
        $out  = [];

        foreach ( $it as $f ) {

            if ( substr( (string) $f, -4 ) !== '.php' ) {

                continue;
            }

            foreach ( file( (string) $f ) ?: [] as $n => $line ) {

                if ( preg_match( '/^\s*\*\s*@covers-setting\s+(\S+)/', $line, $m ) ) {

                    $out[ $m[1] ][] = substr( (string) $f, strlen( $root ) ) . ':' . ( $n + 1 );
                }
            }
        }

        return $out;
    }

    /** @return list<string> every setting id and every constant that is not a setting */
    private static function items(): array
    {

        $settings = self::settings();

        return array_merge( array_keys( $settings ), self::constants( $settings ) );
    }

    public function testTheItemsAreReadFromCode(): void
    {

        $items = self::items();

        // A setting, an internal setting, a mechanical one, a constant that is
        // not a setting, and none that is paired with a setting or retired.
        $this->assertContains( 'base.anonymize_ips', $items );
        $this->assertContains( 'base.install_complete', $items );
        $this->assertContains( 'sqs.schema_version', $items );
        $this->assertContains( 'OWA_DEBUG', $items );
        $this->assertContains( 'OWA_NONCE_KEY', $items );
        $this->assertNotContains( 'OWA_PUBLIC_URL', $items, 'counted under base.public_url' );
        $this->assertNotContains( 'OWA_QUEUE_EVENTS', $items, 'retired' );
        $this->assertNotContains( 'OWA_DTD_BIGINT', $items, 'defined by the database driver' );
        $this->assertNotContains( 'OWA_CLI', $items, 'defined by cli.php' );
    }

    public function testEveryMarkerNamesAnItem(): void
    {

        $items   = array_flip( self::items() );
        $unknown = [];

        foreach ( self::markers() as $id => $where ) {

            if ( ! isset( $items[ $id ] ) ) {

                $unknown[] = $id . ' (' . implode( ', ', $where ) . ')';
            }
        }

        $this->assertSame( [], $unknown, 'these @covers-setting markers name no setting or constant' );
    }

    public function testEveryItemIsMarkedListedOrExempt(): void
    {

        $fixture = self::fixture();
        $marked  = self::markers();
        $known   = array_flip( array_merge( $fixture['untested'], array_keys( $fixture['exempt'] ) ) );
        $missing = [];

        foreach ( self::items() as $id ) {

            if ( ! isset( $marked[ $id ] ) && ! isset( $known[ $id ] ) ) {

                $missing[] = $id;
            }
        }

        $this->assertSame( [], $missing, 'these settings or constants have no test of their effect: '
            . 'add one marked "@covers-setting <id>"' );
    }

    public function testTheUntestedListOnlyShrinks(): void
    {

        $fixture = self::fixture();
        $items   = array_flip( self::items() );
        $marked  = self::markers();
        $stale   = [];

        foreach ( $fixture['untested'] as $id ) {

            if ( ! isset( $items[ $id ] ) ) {

                $stale[] = $id . ': no longer exists';
            } elseif ( isset( $marked[ $id ] ) ) {

                $stale[] = $id . ': now tested (' . implode( ', ', $marked[ $id ] ) . ')';
            } elseif ( isset( $fixture['exempt'][ $id ] ) ) {

                $stale[] = $id . ': also exempt';
            }
        }

        $this->assertSame( [], $stale, 'remove these from ' . self::FIXTURE . ' untested' );
        $this->assertSame( count( $fixture['untested'] ), count( array_unique( $fixture['untested'] ) ),
            'an item is listed twice' );
    }

    public function testEveryExemptionExistsHasAReasonAndNoTest(): void
    {

        $fixture = self::fixture();
        $items   = array_flip( self::items() );
        $marked  = self::markers();
        $wrong   = [];

        foreach ( $fixture['exempt'] as $id => $reason ) {

            if ( ! isset( $items[ $id ] ) ) {

                $wrong[] = $id . ': no longer exists';
            } elseif ( isset( $marked[ $id ] ) ) {

                $wrong[] = $id . ': has a test (' . implode( ', ', $marked[ $id ] ) . ')';
            } elseif ( trim( (string) $reason ) === '' ) {

                $wrong[] = $id . ': no reason given';
            }
        }

        $this->assertSame( [], $wrong, 'fix these in ' . self::FIXTURE . ' exempt' );
    }
}
