<?php

require_once __DIR__ . '/bootstrap_owa.php';

use OWA\Core\ConfiguredReport;
use OWA\Core\MetricSets;
use OWA\Module\Base\Classes\CustomReports;
use OWA\Module\Base\Classes\ResultSetManager;
use OWA\Module\Base\Classes\TimePeriod;
use PHPUnit\Framework\TestCase;

/**
 * The generated sections of the Reports and Metrics and Dimensions pages list
 * every entry of the code they are read from: the predefined periods, the
 * metric sets, the row actions, the custom report widget types, the
 * capabilities and the constraint operators.
 *
 * Each section runs in its own process, as the publish workflow runs it: the
 * generator loads every shipped module, which must not leak into the tests
 * that follow. The report table's sections are JavaScript and are held to
 * their source by tests/js/WikiDocsReportTable.test.js.
 */
final class WikiDocsReportingTest extends TestCase
{
    private static array $out = array();

    private static function section( string $name ): string
    {
        if ( ! isset( self::$out[ $name ] ) ) {

            $cmd = escapeshellarg( PHP_BINARY ) . ' -d display_startup_errors=0 '
                 . escapeshellarg( OWA_DIR . 'tests/tools/wiki/generate.php' )
                 . ' ' . escapeshellarg( $name ) . ' 2>/dev/null';

            exec( $cmd, $lines, $status );

            self::assertSame( 0, $status, "generate.php $name failed" );

            self::$out[ $name ] = implode( "\n", $lines );
        }

        return self::$out[ $name ];
    }

    public function testEveryPredefinedPeriodIsListedWithWhatItCovers(): void
    {
        $periods = ( new TimePeriod() )->getPeriodLabels();
        $md      = self::section( 'report-periods' );

        $this->assertGreaterThan( 10, count( $periods ) );

        foreach ( $periods as $period => $p ) {

            $this->assertNotSame( '', trim( (string) ( $p['covers'] ?? '' ) ), "$period says nothing about what it covers" );
            $this->assertStringContainsString( "| {$p['label']} | `$period` | {$p['covers']} |", $md );
        }
    }

    /**
     * The date picker has no list of periods of its own: it is handed the
     * server's (ReportController::periodPickerConfig), so it cannot offer one
     * the server does not accept or the wiki does not print.
     */
    public function testThePickerOffersThePeriodsTheServerAccepts(): void
    {
        $src = (string) file_get_contents( OWA_DIR . 'modules/Base/src/reporting/v1/owa.periodpicker.js' )
             . (string) file_get_contents( OWA_DIR . 'modules/Base/src/reporting/v1/owa.report.js' );

        // Words for the reader (a calendar day's spoken name says "today")
        // are text, not period keys.
        $src = preg_replace( "/OWA\\.l\\(\\s*'(?:[^'\\\\]|\\\\.)*'/", 'OWA.l(', $src );

        foreach ( array_keys( ( new TimePeriod() )->getPeriodLabels() ) as $period ) {

            $this->assertStringNotContainsString( "'$period'", $src, "the picker names $period itself instead of reading the server's list" );
        }

        $period = new TimePeriod();
        $period->set( 'today' );

        $config = \OWA\Core\ReportController::periodPickerConfig( $period, array() );

        $this->assertSame( array_keys( ( new TimePeriod() )->getPeriodLabels() ), array_column( $config['presets'], 'key' ) );
    }

    public function testEveryMetricSetIsListedWithWhenItIsShown(): void
    {
        $md = self::section( 'report-metric-sets' );

        $this->assertSame( array_keys( MetricSets::SETS ), array_keys( MetricSets::SHOWN_WHEN ),
            'every metric set says when a Property has it' );

        foreach ( MetricSets::SETS as $key => $set ) {

            $this->assertMatchesRegularExpression(
                '/^\| \*\*' . preg_quote( $set['label'], '/' ) . '\*\* \| ' . preg_quote( MetricSets::SHOWN_WHEN[ $key ], '/' ) . ' \| /m',
                $md, "metric set $key is not on the page" );
        }

        $this->assertStringContainsString( 'Goal Events', $md, 'metrics are shown by label' );
    }

    public function testEveryRowActionIsListedAndDescribed(): void
    {
        $md = self::section( 'report-row-actions' );

        foreach ( ConfiguredReport::ROW_ACTIONS as $name => $action ) {

            $this->assertNotSame( '', trim( (string) ( $action['description'] ?? '' ) ), "row action $name has no description" );
            $this->assertStringContainsString( "| **{$action['label']}** (`$name`) | {$action['description']} |", $md );
        }

        // Domstream's, though Domstream is not active on a configless boot.
        $this->assertMatchesRegularExpression( '/^\| \*\*Recordings\*\* \(`recordings`\) \|.*Needs the Domstream module \|$/m', $md );
    }

    public function testEveryRowActionAShippedWidgetNamesIsListedWithThatReport(): void
    {
        $md    = self::section( 'report-row-actions' );
        $named = 0;

        foreach ( glob( OWA_DIR . 'modules/*/reports/*.json' ) as $path ) {

            if ( basename( dirname( $path, 2 ) ) === 'Hello' ) {
                continue; // OWA_WIKI_EXCLUDED_MODULES
            }

            $d = json_decode( (string) file_get_contents( $path ), true );

            foreach ( (array) ( $d['widgets'] ?? array() ) as $widget ) {

                foreach ( (array) ( $widget['rowActions'] ?? array() ) as $name ) {

                    $named++;
                    $this->assertMatchesRegularExpression(
                        '/^\| \*\*[^*]+\*\* \(`' . preg_quote( $name, '/' ) . '`\) \|.*\*\*' . preg_quote( rtrim( $d['title'], ': ' ), '/' ) . '\*\*/m',
                        $md, basename( $path ) . " names $name, and the page does not say so" );
                }
            }
        }

        $this->assertGreaterThan( 0, $named, 'no shipped widget names a row action: the check is vacuous' );
    }

    public function testEveryWidgetTypeIsListedWithItsLimits(): void
    {
        $md = self::section( 'report-widgets' );

        $this->assertSame( array_keys( CustomReports::WIDGET_TYPES ), array_keys( CustomReports::WIDGET_TYPE_HINTS ),
            'every widget type has a hint' );

        foreach ( CustomReports::WIDGET_TYPES as $type => $label ) {

            $this->assertStringContainsString(
                "| **$label** (`$type`) | " . CustomReports::WIDGET_TYPE_HINTS[ $type ] . ' | ', $md );
        }

        $this->assertStringContainsString( 'up to ' . CustomReports::MAX_WIDGETS . ' widgets', $md );

        foreach ( array_merge( CustomReports::WIDTHS, CustomReports::HEIGHTS ) as $word ) {
            $this->assertStringContainsString( "**$word**", $md );
        }
    }

    public function testEveryCapabilityIsListedWithItsRoles(): void
    {
        $md    = self::section( 'capabilities' );
        $decl  = include OWA_DIR . 'modules/Base/settings.php';
        $roles = $decl['settings']['capabilities']['default'];

        $this->assertNotEmpty( $roles );

        foreach ( $roles as $role => $capabilities ) {

            foreach ( $capabilities as $capability ) {

                $this->assertMatchesRegularExpression(
                    '/^\| `' . preg_quote( $capability, '/' ) . '` \| [^|]*`' . preg_quote( $role, '/' ) . '`[^|]* \|/m',
                    $md, "$role's $capability is not on the page" );
            }
        }
    }

    /** The limits the page states are the ones regexProblem() and the query time cap apply. */
    public function testTheRegexLimitsAreTheOnesTheCodeApplies(): void
    {
        $md = self::section( 'regex-limits' );

        $this->assertStringContainsString( 'Longer than ' . ResultSetManager::MAX_REGEX_LENGTH . ' characters', $md );
        $this->assertStringContainsString( 'more than ' . ResultSetManager::MAX_REGEX_BOUND, $md );
        $this->assertStringContainsString( 'more than ' . ResultSetManager::QUERY_SECONDS . ' seconds', $md );

        foreach ( array( '(a+)+', '(a)\\1', 'a{' . ( ResultSetManager::MAX_REGEX_BOUND + 1 ) . '}' ) as $refused ) {
            $this->assertNotNull( ResultSetManager::regexProblem( $refused ),
                "the page names $refused as refused, and the code refuses it" );
        }
    }

    public function testEveryConstraintOperatorIsListedWithItsMeaning(): void
    {
        $md = self::section( 'constraint-operators' );

        $this->assertSame( array_keys( ResultSetManager::CONSTRAINT_OPERATORS ),
            ( new ResultSetManager() )->constraint_operators,
            'the operators a constraint is parsed with are the ones the page lists' );

        foreach ( ResultSetManager::CONSTRAINT_OPERATORS as $operator => $meaning ) {
            $this->assertStringContainsString( "| `$operator` | $meaning |", $md );
        }

        foreach ( ConfiguredReport::EMPTY_TESTS as $name => $form ) {
            $this->assertMatchesRegularExpression( '/^\| `' . preg_quote( $form, '/' ) . '` \|.*`' . $name . '`/m', $md );
        }
    }
}
