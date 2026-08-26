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
        'ReportDomstreams',
        'ReportGoalFunnel',
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
    /**
     * Report id => the controller class that used to implement it.
     *
     * These 35 reports are configuration now: modules/Base/reports/<id>.json,
     * rendered by Core\ConfiguredReport. The map is what lets the conversion
     * keep being checked after the controllers are gone -- the golden file
     * records what each of them DECLARED, and that record is still the standard
     * the JSON has to meet.
     *
     * So the fixture stays keyed by class name on purpose. It is a record of
     * what those controllers did, not a directory of reports that exist now;
     * re-keying it to report ids would rewrite the evidence.
     */
    public const CONVERTED = array(
        'action-detail'             => 'ReportActionDetail',
        'action-group'              => 'ReportActionGroup',
        'action-groups'             => 'ReportActionGroups',
        'action-tracking'           => 'ReportActionTracking',
        'attribution-history'       => 'ReportAttributionHistory',
        'document'                  => 'ReportDocument',
        'visitors'                  => 'ReportVisitors',
        'dom-clicks'                => 'ReportDomClicks',
        'campaigns'                 => 'ReportCampaigns',
        'referral-detail'           => 'ReportReferralDetail',
        'ad-detail'                 => 'ReportAdDetail',
        'ad-type-detail'            => 'ReportAdTypeDetail',
        'ad-types'                  => 'ReportAdTypes',
        'ads'                       => 'ReportAds',
        'anchortext'                => 'ReportAnchortext',
        'avg-order-value'           => 'ReportAvgOrderValue',
        'browser-detail'            => 'ReportBrowserDetail',
        'browsers'                  => 'ReportBrowsers',
        'campaign-detail'           => 'ReportCampaignDetail',
        'commerce'                  => 'ReportCommerce',
        'content'                   => 'ReportContent',
        'dashboard'                 => 'ReportDashboard',
        'country-detail'            => 'ReportCountryDetail',
        'creative-performance'      => 'ReportCreativePerformance',
        'days-to-purchase'          => 'ReportDaysToPurchase',
        'ecommerce'                 => 'ReportEcommerce',
        'ecommerce-conversion-rate' => 'ReportEcommerceConversionRate',
        'entry-pages'               => 'ReportEntryPages',
        'exit-pages'                => 'ReportExitPages',
        'feeds'                     => 'ReportFeeds',
        'geolocation'               => 'ReportGeolocation',
        'goals'                     => 'ReportGoals',
        'host-detail'               => 'ReportHostDetail',
        'hosts'                     => 'ReportHosts',
        'keyword-detail'            => 'ReportKeywordDetail',
        'keywords'                  => 'ReportKeywords',
        'os'                        => 'ReportOs',
        'os-detail'                 => 'ReportOsDetail',
        'page-type-detail'          => 'ReportPageTypeDetail',
        'page-types'                => 'ReportPageTypes',
        'pages'                     => 'ReportPages',
        'product-categories'        => 'ReportProductCategories',
        'product-category-detail'   => 'ReportProductCategoryDetail',
        'product-detail'            => 'ReportProductDetail',
        'product-sku-detail'        => 'ReportProductSkuDetail',
        'product-skus'              => 'ReportProductSkus',
        'products'                  => 'ReportProducts',
        'referral-link-text-detail' => 'ReportReferralLinkTextDetail',
        'referring-sites'           => 'ReportReferringSites',
        'revenue'                   => 'ReportRevenue',
        'search-engine-detail'      => 'ReportSearchEngineDetail',
        'search-engines'            => 'ReportSearchEngines',
        'source-detail'             => 'ReportSourceDetail',
        'sources'                   => 'ReportSources',
        'state-detail'              => 'ReportStateDetail',
        'traffic'                   => 'ReportTraffic',
        'transactions'              => 'ReportTransactions',
        'visitors-age'              => 'ReportVisitorsAge',
        'visitors-loyalty'          => 'ReportVisitorsLoyalty',
        'visitors-recency'          => 'ReportVisitorsRecency',
        'visits-to-purchase'        => 'ReportVisitsToPurchase',
    );

    /**
     * Definitions written as definitions, with no controller behind them.
     *
     * CONVERTED maps a report to the controller it replaced, which was every
     * definition while the conversion was the only way one came to exist. It is
     * not any more: the format exists so reports can be AUTHORED, and the first
     * one that is has no predecessor to be equivalent to.
     *
     * Kept separate rather than folded in, because the two mean different
     * things -- a converted report has a recorded standard to meet, an authored
     * one has only its own baseline.
     */
    public const AUTHORED = array( 'latest-visits' );

    public const SENTINEL = 'characterization_sentinel';

    /**
     * Every in-scope report, named the way the fixture names it.
     *
     * Two kinds now: reports still implemented by a controller, and the
     * converted ones, which are JSON. Both are named by the controller class,
     * because the fixture records what those controllers DECLARED and that
     * record is the standard a conversion has to meet -- renaming the keys
     * because the implementation moved would discard the evidence.
     *
     * So a converted report leaves this list only when it stops being a report,
     * never merely because its file was deleted.
     *
     * @return array<int, string> sorted
     */
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

        foreach ( self::CONVERTED as $class ) {
            $names[] = $class;
        }

        $names = array_values( array_unique( $names ) );

        sort( $names );

        return $names;
    }

    /** The report id a converted report is registered under, or '' if it is not one. */
    public static function idForConverted( string $name ): string
    {
        $id = array_search( $name, self::CONVERTED, true );

        return $id === false ? '' : (string) $id;
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
        /*
         * A converted report has no controller to run, so it is run from its
         * definition instead. Dispatching here rather than in the tests keeps
         * "what this report declares" one question with one answer, whichever
         * way the report happens to be implemented.
         */
        $convertedId = self::idForConverted( $name );

        if ( $convertedId !== '' ) {

            return self::snapshotConfigured( $convertedId );
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

            $snapshot = self::snapshot( $name );

            /*
             * `deprecated` is stripped here for the same reason the assertion
             * strips it from actual: this file is the PRE-CONVERSION record,
             * and no controller ever declared the key. Without this, a
             * regeneration bakes it into the baseline and the very next run
             * fails -- the golden claiming a key the comparison has just
             * removed. Regenerating is supposed to be the way OUT of a
             * failure, not a way into one.
             */
            unset( $snapshot['config']['deprecated'] );

            $all[ $name ] = $snapshot;
        }

        return $all;
    }

    /** Absolute path to a converted report's definition file. */
    public static function definitionPath( string $id ): string
    {
        return OWA_DIR . 'modules/Base/reports/' . $id . '.json';
    }

    /**
     * Run a converted report from its JSON and return the same shape snapshot()
     * returns for a controller, so the two are directly comparable.
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
         * Sorted, because the fixture records them sorted and the two have to
         * be comparable.
         */
        $params = array_keys( (array) ( $definition['params'] ?? array() ) );
        sort( $params );

        $controller = new \OWA\Core\ConfiguredReport(
            array_fill_keys( $params, self::SENTINEL ) );

        $controller->setDefinition( $definition );

        return array( 'params' => $params ) + self::observe( $controller );
    }

    public static function goldenPath(): string
    {
        return OWA_DIR . 'tests/fixtures/report-characterization.json';
    }
}
