<?php
namespace OWA\Module\Base\Controller;

/**
 * The custom report builder.
 *
 * Renders the form; base.customReportSave stores what it produces.
 *
 * THE CHOICES COME FROM THE REGISTRY
 *
 * Every metric and dimension offered here is read out of the reporting stack
 * rather than listed in this file. A list of our own would eventually offer a
 * name the validator then refuses, and the author would have no way to tell a
 * typo from a name that was never real. It is the same reason a segment
 * filter (ReportSegment) reads its options from the result-set manager.
 */
/*
 * A REPORTING screen, not an options screen.
 *
 * An options screen configures the INSTALLATION -- settings, users,
 * modules -- and a custom report is something the author makes, which is
 * why the roster is a reporting screen too. The reporting stylesheet is
 * loaded by report pages only, so the builder's rules and its widget dialog
 * need the reporting family.
 *
 * It also gives the site. A report is viewed against one, and the site the
 * author is building against is the one the View link and the share URL
 * use -- the reporting chrome puts that filter on the page instead of it
 * travelling invisibly.
 */
class CustomReportEdit extends \OWA\Core\ReportController {

    /**
     * What the builder's template reads, each by name: a value set here and not
     * listed reaches it as false (CustomReportsTest holds the two together).
     */
    const SCREEN_VARS = [
        'propertyId', 'custom_report_id', 'custom_report_name', 'custom_report_definition',
        'custom_report_error', 'custom_report_is_shared', 'metric_choices', 'dimension_choices',
        'widget_types', 'max_widgets', 'max_metrics', 'max_dimensions', 'full_width_max_dimensions', 'full_width_types',
        'single_field_types', 'single_metric_types', 'chart_types', 'fixed_dimensions',
        'fixed_dimension_extra', 'default_colspans', 'grid_columns', 'link_targets', 'more_targets',
    ];

    /**
     * Why base.customReportSave refused the submission it hands back; '' when
     * the builder is opened. Set only in-process (refusedWith()), never from
     * the request.
     *
     * @var string
     */
    private $refusal = '';

    public function __construct( $params ) {

        parent::__construct( $params );

        // Authoring, not viewing. A reader who may open a shared report does
        // not necessarily get to build one.
        $this->setRequiredCapability( 'edit_reports' );

        /*
         * A report is edited on its own Property: the one the request names,
         * else the report's (CustomReports::requestProperty()). Set before
         * pre() resolves one, so it does not fall back to the last reported on.
         */
        $propertyId = \OWA\Module\Base\Classes\CustomReports::requestProperty(
            (string) $this->getParam( 'customReportId' ), $this->getParam( 'propertyId' ), $this->getCurrentSiteId() );

        if ( $propertyId !== '' ) {

            $this->setParam( 'propertyId', $propertyId );
        }
    }

    /**
     * Opens the builder on a refused save: $params are what was submitted,
     * and $message is shown as the reason.
     *
     * @param  string $message why, already translated
     * @return void
     */
    public function refusedWith( $message ) {

        $this->refusal = (string) $message;
    }

    public function action() {

        $id     = (string) $this->getParam( 'customReportId' );
        $report = null;

        if ( $id !== '' ) {

            // One of another Property is not found here.
            $report = \OWA\Module\Base\Classes\CustomReports::loadOf( $id, (string) $this->getParam( 'propertyId' ) );

            if ( ! $report ) {

                $this->set( 'custom_report_error', \OWA\Core\CoreAPI::t( 'That report no longer exists.' ) );

            } else {

                $user = \OWA\Core\CoreAPI::getCurrentUser();

                $may = \OWA\Module\Base\Classes\CustomReports::mayEdit(
                    $report,
                    (string) $user->getUserData( 'user_id' ),
                    (bool) $user->isCapable( 'edit_users' )
                );

                if ( ! $may ) {

                    /*
                     * Editing is narrower than viewing: a report can be opened
                     * by anyone it is shared with, and changed only by its
                     * author or by someone who administers every report.
                     */
                    $report = null;
                    $this->set( 'custom_report_error',
                        \OWA\Core\CoreAPI::t( 'That report belongs to somebody else. You can view it, but not change it.' ) );
                }
            }
        }

        $this->set( 'custom_report', $report );
        $this->set( 'custom_report_id', $report ? $report['id'] : '' );

        /*
         * The definition the form starts from. A NEW report starts from NONE --
         * no widgets and no report metric set -- so the first thing an author
         * does is choose what to build.
         */
        // As it is drawn (CustomReports::drawable()): a widget value a save
        // refuses is not offered back, so saving it again is accepted.
        $definition = $report ? \OWA\Module\Base\Classes\CustomReports::drawable( (array) $report['definition'] ) : [];

        $name = $report ? $report['name'] : '';

        /*
         * A REFUSED save comes back through here, carrying what the author
         * typed. Those win over the stored row -- redrawing the last saved
         * version would quietly discard the edit they are being asked to fix.
         */
        if ( $this->refusal !== '' ) {

            $this->set( 'custom_report_error', $this->refusal );

            $submitted = json_decode( (string) $this->getParam( 'customReportDefinition' ), true );

            if ( is_array( $submitted ) ) {

                $definition = $submitted;
            }

            if ( (string) $this->getParam( 'customReportName' ) !== '' ) {

                $name = (string) $this->getParam( 'customReportName' );
            }
        }

        $this->set( 'custom_report_name', $name );
        $this->set( 'custom_report_definition', $definition );

        /*
         * Shared, or listed only to its author. A refused save carries the
         * checkbox back rather than redrawing the stored value, for the same
         * reason the name and definition do.
         */
        $this->set( 'custom_report_is_shared',
            $this->refusal !== ''
                ? (bool) $this->getParam( 'isShared' )
                : ( $report ? ! empty( $report['is_shared'] ) : false ) );

        /*
         * The Property the author is looking at, carried through the form.
         *
         * It ends up on the URL the author lands on after saving, which is the
         * URL they share, so it must name the Property the report was built
         * on. The form action alone does not carry it, so it travels as a
         * field.
         *
         * The dimensions offered below are its: the modules' and its applied
         * custom ones. pre() has resolved it by now, from the URL or else the
         * one last reported on, so a builder opened with no propertyId still
         * offers them, and a widget grouped by one keeps it selected.
         */
        $propertyId = (string) $this->getParam( 'propertyId' );

        $this->set( 'propertyId', $propertyId );

        $this->set( 'metric_choices',    self::metricChoices() );
        $this->set( 'dimension_choices', self::dimensionChoices( $propertyId ) );
        $this->set( 'widget_types',      \OWA\Module\Base\Classes\CustomReports::translatedTable( \OWA\Module\Base\Classes\CustomReports::WIDGET_TYPES ) );
        $this->set( 'max_widgets',       \OWA\Module\Base\Classes\CustomReports::MAX_WIDGETS );
        $this->set( 'max_metrics',       \OWA\Module\Base\Classes\CustomReports::MAX_METRICS );
        $this->set( 'max_dimensions',    \OWA\Module\Base\Classes\CustomReports::MAX_DIMENSIONS );
        $this->set( 'full_width_max_dimensions', \OWA\Module\Base\Classes\CustomReports::FULL_WIDTH_MAX_DIMENSIONS );

        /*
         * Which types decide their own layout, so the builder can stop
         * offering the choice rather than offering it and then overruling it on
         * save. Passed through rather than repeated in the template: the rule
         * is enforced server-side either way, and two copies of a list is how
         * they come to disagree.
         */
        $this->set( 'full_width_types',   \OWA\Module\Base\Classes\CustomReports::FULL_WIDTH_TYPES );
        $this->set( 'single_field_types', \OWA\Module\Base\Classes\CustomReports::SINGLE_FIELD_TYPES );
        $this->set( 'single_metric_types', \OWA\Module\Base\Classes\CustomReports::SINGLE_METRIC_TYPES );
        $this->set( 'chart_types',        \OWA\Module\Base\Classes\CustomReports::CHART_TYPES );
        $this->set( 'fixed_dimensions', \OWA\Module\Base\Classes\CustomReports::FIXED_DIMENSIONS );
        $this->set( 'fixed_dimension_extra', \OWA\Module\Base\Classes\CustomReports::FIXED_DIMENSION_EXTRA );
        $this->set( 'default_colspans',   \OWA\Core\ReportGrid::DEFAULT_COLSPANS );
        $this->set( 'grid_columns',       \OWA\Core\ReportGrid::COLUMNS );

        /*
         * Where a card's rows can lead, per dimension. Derived from what each
         * detail report says it is constrained on, so the builder offers a card
         * grouped by browserType only the report that reads a browserType.
         */
        $this->set( 'link_targets', \OWA\Module\Base\Classes\CustomReports::linkTargetsByDimension() );

        /*
         * ...and where a "View Full Report" link under a widget can go. The
         * same rule, minus the constraint: scoped to the widget's dimension the
         * same way, but the destination must need no value, because this link
         * carries none.
         */
        $this->set( 'more_targets', \OWA\Module\Base\Classes\CustomReports::moreTargetsByDimension() );
    }

    /**
     * The pickers' options.
     *
     * Public and static because the save controller re-renders this same form
     * when it refuses a definition, and a second list built somewhere else
     * would eventually disagree with this one.
     *
     * @return list<array{name: string, label: string, description: string, group: string, group_label: string, type: string}>
     */
    public static function metricChoices() {

        return self::choices( \OWA\Core\CoreAPI::getAllMetrics() );
    }

    /**
     * The dimensions a person may pick: every one of the Property's
     * (Classes\DimensionRegistry) except those declared internal, which a
     * feature queries and nobody groups by (the heatmap's coordinates and
     * element anchor).
     *
     * @param  string|null $property_id null for the modules' dimensions alone
     * @return list<array{name: string, label: string, description: string, group: string, group_label: string, type: string}>
     */
    public static function dimensionChoices( $property_id ) {

        return self::choices( array_filter(
            \OWA\Module\Base\Classes\DimensionRegistry::forProperty( $property_id )->all(),
            static fn( $dim ) => empty( $dim['internal'] ) ) );
    }

    /**
     * A registry map reduced to what the chooser shows, in the registry's
     * order (the field chooser groups them, in the order the groups first
     * come): the name and label, the description, the group (a
     * dimension's family, a metric's group) and its name, and the type (a
     * dimension's data_type, a metric's metric_type).
     *
     * The registry entries carry far more than the picker needs -- the SQL
     * expression among it -- and handing the whole thing to a template would
     * put the query internals into the page source.
     *
     * @param mixed $registry
     * @return list<array{name: string, label: string, description: string, group: string, group_label: string, type: string}>
     */
    private static function choices( $registry ) {

        $out = [];

        foreach ( (array) $registry as $name => $entry ) {

            $entry = (array) $entry;

            // A metric's entry is the list of its registrations; they share a label.
            if ( isset( $entry[0] ) && is_array( $entry[0] ) ) {

                $entry = $entry[0];
            }

            $label = '';

            foreach ( [ 'label', 'name' ] as $key ) {

                if ( ! empty( $entry[ $key ] ) && is_string( $entry[ $key ] ) ) {

                    $label = $entry[ $key ];
                    break;
                }
            }

            $group = (string) ( $entry['family'] ?? $entry['group'] ?? '' );
            $type  = (string) ( $entry['params']['metric_type'] ?? $entry['metric_type'] ?? $entry['data_type'] ?? '' );

            $out[] = [
                'name'        => (string) $name,
                'label'       => $label !== '' ? $label : (string) $name,
                'description' => (string) ( $entry['description'] ?? '' ),
                'group'       => $group,
                'group_label' => \OWA\Module\Base\Classes\FieldGroups::label( $group ),
                'type'        => $type,
            ];
        }

        return $out;
    }

    public function success() {

        $this->setSubview( 'base.customReportEdit', [ 'template' => 'custom_report_edit.php', 'vars' => self::SCREEN_VARS ] );
        $this->setView( 'base.report' );
        // $this->data, not $this->get() -- get() reads the request, and this
        // was set by action().
        $this->set( 'title', ! empty( $this->data['custom_report_id'] )
            ? \OWA\Core\CoreAPI::t( 'Edit Custom Report' ) : \OWA\Core\CoreAPI::t( 'New Custom Report' ) );

        /*
         * The reporting NAV is hidden: this is one screen about one report, and
         * the left menu is for moving between reports.
         *
         * The period picker and Live View go too -- the builder runs no query,
         * so neither would change anything on it. The sites filter STAYS,
         * because the site is a real input here: it decides which site the View
         * link and the saved report's URL name.
         */
        /*
         * The reporting navigation STAYS on this screen: the builder is reached
         * from the roster and left for the report it just made, and both of those
         * are in that nav.
         */
        $this->hideTimeControls();

        // The name field, Shared and Save are the screen's header.
        $this->hideReportHead();
    }
}
