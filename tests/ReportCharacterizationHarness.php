<?php

namespace OWA\Tests;

/**
 * Runs a report and records what it DECLARES, and anything it complained about.
 *
 * A report is defined by the query it will issue and the widgets it declares, not
 * by the pixels it eventually produces, so that declaration IS the report. Tests
 * built on this ask two questions of every report: does running it raise a
 * diagnostic, and does each parameter it reads reach what it declares.
 *
 * It began as the recording that held the controller-to-JSON conversion to what
 * each controller had declared. The conversion is finished and the recording is
 * gone; what is left never depended on it.
 *
 * SCOPE: reports that declare, not reports that prefetch. The bespoke ones fetch
 * result sets through the data-access layer, so what they hold depends on which
 * rows happen to exist. Those are listed explicitly below rather than filtered by
 * a predicate, so the exclusion is a decision someone made and can revisit.
 */
final class ReportCharacterizationHarness
{
    /**
     * Reports that prefetch data, and are therefore not characterizable this
     * way. Each keeps its controller through the conversion (see the design
     * note), so none of these is a report the harness needs to protect.
     */
    private const PREFETCHING = array(
        'ReportDomstreams',
        'VisualizationFunnel',
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

    /**
     * Every report the harness runs: each definition file by the id it is
     * registered under, and any report still implemented by a controller by its
     * class name.
     *
     * Asked of the tree as it is, not of a list kept beside it, so a report that
     * is added is covered without anyone remembering this file exists.
     *
     * @return array<int, string> sorted
     */
    public static function reportNames(): array
    {
        $names = self::definitionIds();

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

        $names = array_values( array_unique( $names ) );

        sort( $names );

        return $names;
    }

    /**
     * Every report that is configuration, by the id it is registered under.
     *
     * THE DIRECTORY IS THE LIST. There used to be a hand-kept map here, of report
     * id to the controller each one replaced, plus a second list for reports
     * authored as JSON from the start. Both were scaffolding for the conversion,
     * and a report authored afterwards had to be added to one of them before any
     * test would look at it -- so the four that were never converted, Scroll Depth
     * among them, had never been checked for diagnostics at all.
     *
     * @return string[]
     */
    public static function definitionIds(): array
    {
        $ids = array();

        foreach ( glob( OWA_DIR . 'modules/Base/reports/*.json' ) as $file ) {
            $ids[] = basename( $file, '.json' );
        }

        sort( $ids );

        return $ids;
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
     * Values are normalised rather than captured raw so a snapshot says the
     * same thing on any machine: an object becomes its class name, because its
     * contents depend on a database, while its PRESENCE is part of the
     * contract.
     */
    public static function snapshot( string $name ): array
    {
        /*
         * A report that is configuration has no controller to run, so it is run
         * from its definition instead. Dispatching here rather than in the tests
         * keeps "what this report declares" one question with one answer,
         * whichever way the report happens to be implemented.
         */
        if ( is_file( self::definitionPath( $name ) ) ) {

            return self::snapshotConfigured( $name );
        }

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
         * cannot start warning -- or keep warning -- without a test saying so.
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


    /** Absolute path to a report's definition file. */
    public static function definitionPath( string $id ): string
    {
        return OWA_DIR . 'modules/Base/reports/' . $id . '.json';
    }

    /**
     * Run a report from its JSON and return the same shape snapshot() returns for
     * a controller, so the two are directly comparable.
     */
    public static function snapshotConfigured( string $id ): array
    {
        $definition = json_decode(
            (string) file_get_contents( self::definitionPath( $id ) ), true );

        $definition = (array) $definition;

        /*
         * A definition DECLARES the parameters it reads, so they can be
         * supplied without parsing anything -- which is what paramsFor() had to
         * do against a controller, variable-named getParam() calls included.
         *
         * Sorted, so a snapshot of the same report is the same every time.
         */
        $params = array_keys( (array) ( $definition['params'] ?? array() ) );
        sort( $params );

        $controller = new \OWA\Core\ConfiguredReport(
            array_fill_keys( $params, self::SENTINEL ) );

        $controller->setDefinition( $definition );

        return array( 'params' => $params ) + self::observe( $controller );
    }

}
