<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TrackerBundle;
use OWA\Module\Base\Classes\TrackerDefaults;
use OWA\Module\Base\Classes\TrackingEventHelpers;
use OWA\Module\Base\Classes\Cube\CampaignStep;
use OWA\Module\Base\Classes\Cube\SourceStep;

/**
 * The generated tracking and attribution wiki sections list every entry of
 * their sources: tracker-features, custom-event-names, campaign-parameters,
 * click-ids, campaign-placeholders and app-sources.
 *
 * Runs the generator as a separate process, the way the publish workflow does,
 * so every module is active there as it is for the wiki.
 */
final class WikiDocsTrackingTest extends TestCase
{
    private static function generate( string $section ): string
    {
        $cmd = escapeshellarg( PHP_BINARY ) . ' -d display_startup_errors=0 '
             . escapeshellarg( OWA_DIR . 'tests/tools/wiki/generate.php' ) . ' '
             . escapeshellarg( $section ) . ' 2>&1';

        exec( $cmd, $lines, $status );
        $out = implode( "\n", $lines );

        self::assertSame( 0, $status, "generate.php $section failed:\n$out" );

        return $out;
    }

    private static function row( string $md, string ...$cells ): void
    {
        $re = '/^\|.*' . implode( '.*\| ', array_map( fn ( $c ) => preg_quote( $c, '/' ), $cells ) ) . '.*\|$/m';

        self::assertMatchesRegularExpression( $re, $md, 'no row with ' . implode( ' | ', $cells ) );
    }

    /** @return array<string,array<string,string>> module class => its FEATURE_SETTINGS */
    private static function moduleFeatureSettings(): array
    {
        $out = array();

        foreach ( glob( OWA_MODULES_DIR . '*/Module.php' ) as $path ) {

            $class = 'OWA\\Module\\' . basename( dirname( $path ) ) . '\\Module';

            if ( basename( dirname( $path ) ) !== 'Hello' && class_exists( $class )
                 && defined( $class . '::FEATURE_SETTINGS' ) ) {
                $out[ $class ] = (array) constant( $class . '::FEATURE_SETTINGS' );
            }
        }

        return $out;
    }

    public function testTrackerFeaturesListsEverySettingCommandAndLabel(): void
    {
        $md = self::generate( 'tracker-features' );

        foreach ( TrackerBundle::HIT_SETTINGS + TrackerBundle::FEATURE_SETTINGS as $setting => $command ) {
            self::row( $md, "(`$setting`)", "`$command`", TrackerBundle::FEATURE_LABELS[ $command ] );
        }

        foreach ( array_keys( TrackerBundle::FEATURE_LABELS ) as $command ) {
            self::assertStringContainsString( "| `$command` |", $md, "$command has no row" );
        }

        $modules = self::moduleFeatureSettings();

        self::assertNotEmpty( $modules, 'no module declares FEATURE_SETTINGS; Domstream does' );

        foreach ( $modules as $class => $settings ) {

            // The label the module's tracker_feature_labels filter adds.
            $labels = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor()->labelFeatures( array() );

            foreach ( $settings as $setting => $command ) {
                self::row( $md, "(`$setting`,", "`$command`", (string) ( $labels[ $command ] ?? $command ) );
            }
        }

        foreach ( TrackerDefaults::get( 'features' ) as $command ) {
            self::assertMatchesRegularExpression( '/^\|.*\| `' . preg_quote( $command, '/' ) . '` \|.*\| yes \|$/m',
                $md, "$command is in the default list" );
        }

        $rows = count( TrackerBundle::HIT_SETTINGS + TrackerBundle::FEATURE_SETTINGS )
              + array_sum( array_map( 'count', $modules ) );

        self::assertSame( $rows, preg_match_all( '/^\| .* \| `track\w+` \| /m', $md ), 'one row per setting' );
    }

    /** A module's feature setting defaults to whether the tracker starts it, as Base's do. */
    public function testAModulesFeatureSettingsDefaultToTheTrackersList(): void
    {
        foreach ( self::moduleFeatureSettings() as $class => $settings ) {

            $declared = ( require dirname( ( new ReflectionClass( $class ) )->getFileName() ) . '/settings.php' )['settings'];

            foreach ( $settings as $setting => $command ) {
                self::assertArrayHasKey( $setting, $declared, "$class names $setting, which it does not declare" );
                self::assertSame( TrackerDefaults::starts( $command ), $declared[ $setting ]['default'], "$class $setting" );
            }
        }
    }

    public function testCustomEventNamesListEveryReservedName(): void
    {
        $md = self::generate( 'custom-event-names' );

        self::assertStringContainsString( '`' . TrackingEventHelpers::CUSTOM_NAME_PATTERN . '`', $md );
        self::assertStringContainsString( '`owa_`', $md );

        $line = function ( string $start ) use ( $md ): string {

            self::assertSame( 1, preg_match( '/^\| ' . preg_quote( $start, '/' ) . ' \|.*$/m', $md, $m ), "no row for: $start" );

            return $m[0];
        };

        $firstClass = $line( 'Is a first-class event' );

        foreach ( TrackingEventHelpers::eventNames() as $name ) {
            self::assertStringContainsString( "`$name`", $firstClass, "$name is reserved" );
        }

        self::assertStringContainsString( '`' . \OWA\Module\Domstream\Module::EVENT_NAME . '`',
            $line( 'Is an event a tracker module sends' ) );
    }

    /**
     * The section reads a module's reserved names from its build manifest, so a
     * tracker plugin that reserves names must be declared there.
     */
    public function testEveryPluginThatReservesNamesIsALazyManifestEntry(): void
    {
        foreach ( glob( OWA_MODULES_DIR . '*/src/tracker/*.js' ) as $file ) {

            $module = basename( dirname( $file, 3 ) );

            if ( $module === 'Base' || strpos( (string) file_get_contents( $file ), 'reservedEventNames:' ) === false ) {
                continue;
            }

            $manifest = json_decode( (string) @file_get_contents( OWA_MODULES_DIR . $module . '/build.manifest.json' ), true );
            $entries  = array();

            foreach ( (array) ( $manifest['contributes_lazy'] ?? array() ) as $plugins ) {
                foreach ( (array) $plugins as $plugin ) {
                    if ( ! empty( $plugin['reservedEventNames'] ) ) {
                        $entries[] = (string) ( $plugin['entry'] ?? '' );
                    }
                }
            }

            self::assertContains( 'src/tracker/' . basename( $file ), $entries,
                "$file reserves event names its module's build manifest does not declare" );
        }
    }

    public function testCampaignParametersListEveryRoleAndUtmKey(): void
    {
        $md = self::generate( 'campaign-parameters' );

        foreach ( TrackingEventHelpers::CAMPAIGN_KEYS as $role => $property ) {

            self::row( $md, "`$role`", "`owa_$role`" );

            foreach ( array_keys( TrackingEventHelpers::UTM_KEYS, $property, true ) as $utm ) {
                self::row( $md, "`$role`", "`$utm`" );
            }
        }

        self::assertSame( count( TrackingEventHelpers::CAMPAIGN_KEYS ), preg_match_all( '/^\| `\w+` \| `owa_/m', $md ) );
        self::row( $md, '`search_terms`', '`tagged_search_terms`' );
    }

    public function testClickIdsListEveryId(): void
    {
        $md = self::generate( 'click-ids' );

        foreach ( TrackingEventHelpers::CLICK_IDS as $param => list( $source, $medium ) ) {
            self::row( $md, "`$param`", "`$source`", "`$medium`" );
        }
    }

    public function testCampaignPlaceholdersListEveryMedium(): void
    {
        $md = self::generate( 'campaign-placeholders' );

        foreach ( CampaignStep::PLACEHOLDERS as $medium => $campaign ) {
            self::assertStringContainsString( "| `$medium` | `$campaign` |", $md );
        }

        self::assertStringContainsString( '| `' . CampaignStep::FALLBACK . '` |', $md );
    }

    public function testAppSourcesListEveryPackage(): void
    {
        $md = self::generate( 'app-sources' );

        foreach ( SourceStep::APP_SOURCES as $package => $source ) {
            self::assertStringContainsString( "| `$package` | `$source` |", $md );
        }
    }
}
