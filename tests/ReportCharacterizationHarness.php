<?php

namespace OWA\Tests;

/**
 * Captures what each report controller DECLARES, so a conversion can be shown
 * to preserve it.
 *
 * A report is defined by the query it will issue and the widgets it declares,
 * not by the pixels it eventually produces. Every one of the config-driven
 * controllers expresses both as a bag of key/value pairs set during action(),
 * so that bag IS the report -- and comparing it before and after a rewrite is
 * the only available definition of "did not change the report".
 *
 * SCOPE: the config-driven reports only. The bespoke ones prefetch result sets
 * through the data-access layer, so what they hold depends on a database and on
 * which rows happen to exist; snapshotting that would produce a fixture that
 * passes on one machine. Those are listed explicitly below rather than filtered
 * by a predicate, so the exclusion is a decision someone made and can revisit
 * rather than an accident of a regex.
 */
final class ReportCharacterizationHarness
{
    /**
     * Reports that prefetch data, and are therefore not characterizable this
     * way. Each keeps its controller through the conversion (see the design
     * note), so none of these is a report the harness needs to protect.
     */
    private const PREFETCHING = array(
        'ReportAttributionHistory', 'ReportCampaigns', 'ReportDashboard',
        'ReportDocument', 'ReportDomClicks', 'ReportDomstreams',
        'ReportGoalFunnel', 'ReportGoals', 'ReportReferralDetail',
        'ReportVisit', 'ReportVisitor', 'ReportVisitors', 'ReportVisits',

        /*
         * Found by this harness rather than by reading the code: it reads two
         * request parameters and puts neither into its configuration, because
         * it builds a raw SQL query with the db singleton and hands the rows to
         * a template. An earlier classification counted it as a parameterised
         * config report on the strength of those getParam() calls, which is
         * what a source-level heuristic sees.
         */
        'ReportVisitorsRoster',

        /*
         * Also found by running it rather than reading it: it fetches a
         * transaction through the REST endpoint, so without a database it reads
         * a property on null. Invisible on a machine that has one.
         */
        'ReportTransactionDetail',
    );

    /**
     * Files under Controller/Report*.php that are not reports.
     *
     * ReportsRest is the REST data-endpoint controller -- its "report names"
     * are hand-written queries, not report configurations.
     *
     * Report is the dispatcher that resolves a reportId. It matches the glob
     * because of where it lives, and this harness noticed the moment it was
     * added, which is the drift detection working rather than a nuisance.
     */
    private const NOT_A_REPORT = array( 'ReportsRest', 'Report' );

    /**
     * A fixed value for every request parameter a report reads.
     *
     * The 20 parameterised reports interpolate a URL parameter into their
     * constraints and title. Snapshotting them with no parameter would leave
     * the one part that differs from a pure config report completely untested,
     * so every parameter is supplied -- the same sentinel everywhere, so the
     * snapshot shows WHERE it lands rather than what it is.
     *
     * LOWERCASE deliberately. Several detail reports normalise the value with
     * strtolower() before building their constraint -- ReportAdDetail,
     * ReportCampaignDetail and ReportSourceDetail among them -- so a
     * mixed-case sentinel would arrive changed and every check for it would
     * have to be loosened to case-insensitive. A sentinel that is a fixed point
     * of that transformation keeps the assertions exact, so a report that
     * mangles the value in any OTHER way still fails loudly.
     */
    public const SENTINEL = 'characterization_sentinel';

    /** @return array<int, string> controller class short-names, sorted */
    public static function reportNames(): array
    {
        $names = array();

        foreach ( glob( OWA_DIR . 'modules/Base/Controller/Report*.php' ) as $file ) {

            $name = basename( $file, '.php' );

            if ( in_array( $name, self::NOT_A_REPORT, true ) ) {
                continue;
            }

            if ( in_array( $name, self::PREFETCHING, true ) ) {
                continue;
            }

            $names[] = $name;
        }

        sort( $names );

        return $names;
    }

    /** Request parameters a controller reads, in source order. */
    public static function paramsFor( string $name ): array
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/' . $name . '.php' );

        preg_match_all( "/getParam\(\s*'([a-zA-Z_]+)'\s*\)/", $src, $m );
        $params = $m[1];

        /*
         * Three controllers name the parameter through a variable --
         *
         *     $dim_name  = 'productSku';
         *     $dim_value = $this->getParam( $dim_name );
         *
         * -- which a literal-string match cannot see. Missing one is not
         * harmless: the controller then reads a parameter that was never
         * supplied and urlencode(null) deprecates, silently on a machine where
         * deprecations are not fatal. Resolved by looking up the variable's
         * literal assignment; anything more dynamic than that is caught by the
         * diagnostics guard in snapshot() instead.
         */
        // Single-quoted on purpose. In a double-quoted PHP string \$ collapses to
        // a bare $, which the regex engine then reads as end-of-subject -- so the
        // pattern silently matches nothing and the parameter goes on being missed.
        preg_match_all( '/getParam\(\s*\$([a-zA-Z_]+)\s*\)/', $src, $vars );

        foreach ( array_unique( $vars[1] ) as $var ) {

            if ( preg_match( '/\$' . $var . '\s*=\s*\'([a-zA-Z_]+)\'\s*;/', $src, $lit ) ) {
                $params[] = $lit[1];
            }
        }

        $params = array_values( array_unique( $params ) );
        sort( $params );

        return $params;
    }

    /**
     * Run one report's action() and return a normalised snapshot.
     *
     * Values are normalised rather than captured raw so the fixture says the
     * same thing on any machine: an object becomes its class name, because its
     * contents depend on a database, while its PRESENCE is part of the
     * contract.
     */
    public static function snapshot( string $name ): array
    {
        $class  = '\\OWA\\Module\\Base\\Controller\\' . $name;
        $params = self::paramsFor( $name );

        $controller = new $class( array_fill_keys( $params, self::SENTINEL ) );

        return array( 'params' => $params ) + self::observe( $controller );
    }

    /**
     * Run a controller's action() and record both what it declared and anything
     * it complained about.
     *
     * Split out from snapshot() so a test can hand it a deliberately noisy
     * object and prove the recording works. Without that, "no report raises a
     * diagnostic" is only as true as this method is honest -- and a guard that
     * cannot be shown to fire is a claim, not a guard.
     *
     * @return array{diagnostics: array<int,string>, config: array}
     */
    public static function observe( object $controller ): array
    {
        /*
         * Diagnostics are part of the snapshot, not noise to be swallowed.
         *
         * These controllers had never been executed by a test before this
         * harness, and the first CI run surfaced three deprecations and a
         * warning that had been there all along. Recording them means a report
         * cannot start warning -- or keep warning -- without the fixture
         * saying so.
         */
        $diagnostics = array();

        set_error_handler( static function ( $no, $msg, $file, $line ) use ( &$diagnostics ) {
            $diagnostics[] = basename( (string) $file ) . ':' . $line . ' ' . $msg;
            return true;
        } );

        try {
            $controller->action();
        } finally {
            restore_error_handler();
        }

        $data = array();

        foreach ( (array) $controller->data as $key => $value ) {
            $data[ $key ] = self::normalise( $value );
        }

        ksort( $data );

        return array(
            'diagnostics' => $diagnostics,
            'config'      => $data,
        );
    }

    /** @param mixed $value */
    private static function normalise( $value )
    {
        if ( is_object( $value ) ) {
            return '<object:' . get_class( $value ) . '>';
        }

        if ( is_array( $value ) ) {

            $out = array();

            foreach ( $value as $k => $v ) {
                $out[ $k ] = self::normalise( $v );
            }

            ksort( $out );

            return $out;
        }

        return $value;
    }

    /** @return array<string, array> every in-scope report, keyed by name */
    public static function captureAll(): array
    {
        $all = array();

        foreach ( self::reportNames() as $name ) {
            $all[ $name ] = self::snapshot( $name );
        }

        return $all;
    }

    public static function goldenPath(): string
    {
        return OWA_DIR . 'tests/fixtures/report-characterization.json';
    }
}
