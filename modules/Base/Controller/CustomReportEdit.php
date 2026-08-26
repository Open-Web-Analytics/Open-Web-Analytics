<?php
namespace OWA\Module\Base\Controller;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//


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
 * typo from a name that was never real. It is the same reason the segment
 * filter on the funnel and domstreams reports reads its options from the
 * result-set manager.
 *
 * @since owa 1.8.0
 */
/*
 * A REPORTING screen, not an options screen.
 *
 * It was an options screen first, copied from the goal forms, and that was
 * wrong twice over. An options screen configures the INSTALLATION -- settings,
 * users, modules -- and a custom report is not a setting; it is something the
 * author makes, which is why the roster is already a reporting screen.
 *
 * It was also wrong concretely. View/Options.php loads owa.admin.css and
 * nothing else, so the builder's own rules never reached it and the whole page
 * rendered unstyled; and jQuery UI's stylesheet is loaded by report pages only,
 * so the widget dialog opened as an unstyled block. Both were symptoms of the
 * screen being in the wrong family, and both go away here rather than being
 * patched around.
 *
 * The third thing it fixes is the site. A report is viewed against one, and the
 * site the author is building against is the one the View link and the share
 * URL use -- the reporting chrome puts that filter on the page instead of it
 * travelling invisibly.
 */
class CustomReportEdit extends \OWA\Core\ReportController {

    function __construct( $params ) {

        parent::__construct( $params );

        // Authoring, not viewing. A reader who may open a shared report does
        // not necessarily get to build one.
        $this->setRequiredCapability( 'edit_reports' );
    }

    function action() {

        $id     = (string) $this->getParam( 'customReportId' );
        $report = null;

        if ( $id !== '' ) {

            $report = \OWA\Module\Base\Classes\CustomReports::load( $id );

            if ( ! $report ) {

                $this->set( 'custom_report_error', 'That report no longer exists.' );

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
                        'That report belongs to somebody else. You can view it, but not change it.' );
                }
            }
        }

        $this->set( 'custom_report', $report );
        $this->set( 'custom_report_id', $report ? $report['id'] : '' );

        /*
         * The definition the form starts from. A NEW report starts from one
         * empty widget rather than none, because a report with no widgets
         * cannot be saved and an empty form gives the author nothing to react
         * to.
         */
        $definition = $report ? (array) $report['definition'] : array();

        $name = $report ? $report['name'] : '';

        /*
         * A REFUSED save comes back through here, carrying what the author
         * typed. Those win over the stored row -- redrawing the last saved
         * version would quietly discard the edit they are being asked to fix.
         */
        if ( $this->getParam( 'customReportError' ) ) {

            $this->set( 'custom_report_error', $this->getParam( 'customReportError' ) );

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
         * The site the author is looking at, carried through the form.
         *
         * It ends up on the URL the author lands on after saving, which is the
         * URL they share -- and a report URL naming no site is refused for
         * everyone who is not an admin, because view_reports is only ever
         * satisfied against a particular site. The form action alone does not
         * carry it, so it travels as a field.
         */
        $this->set( 'siteId', $this->getParam( 'siteId' ) );

        /*
         * Which fact tables each metric can be measured in.
         *
         * The builder narrows the picker with it: once a metric is chosen, only
         * metrics sharing a table stay offered, so an impossible set cannot be
         * assembled. Sent as data because the filtering has to happen per
         * keystroke -- asking the server on every selection would make the
         * picker feel broken, and the answer never changes within a page.
         *
         * Server-side validation still refuses an illegal set on save. This is
         * the same answer arriving earlier, not a replacement for it.
         */
        $this->set( 'metric_entities', self::metricEntities() );
        $this->set( 'dimension_entities', self::dimensionEntities() );

        $this->set( 'metric_choices',    self::metricChoices() );
        $this->set( 'dimension_choices', self::dimensionChoices() );
        $this->set( 'widget_types',      \OWA\Module\Base\Classes\CustomReports::WIDGET_TYPES );
        $this->set( 'max_widgets',       \OWA\Module\Base\Classes\CustomReports::MAX_WIDGETS );
        $this->set( 'max_metrics',       \OWA\Module\Base\Classes\CustomReports::MAX_METRICS );
        $this->set( 'max_dimensions',    \OWA\Module\Base\Classes\CustomReports::MAX_DIMENSIONS );

        /*
         * Which types decide their own layout, so the builder can stop
         * offering the choice rather than offering it and then overruling it on
         * save. Passed through rather than repeated in the template: the rule
         * is enforced server-side either way, and two copies of a list is how
         * they come to disagree.
         */
        $this->set( 'full_width_types',   \OWA\Module\Base\Classes\CustomReports::FULL_WIDTH_TYPES );
        $this->set( 'single_field_types', \OWA\Module\Base\Classes\CustomReports::SINGLE_FIELD_TYPES );
        $this->set( 'default_colspans',   \OWA\Core\ReportGrid::DEFAULT_COLSPANS );
        $this->set( 'grid_columns',       \OWA\Core\ReportGrid::COLUMNS );
    }

    /**
     * The pickers' options.
     *
     * Public and static because the save controller re-renders this same form
     * when it refuses a definition, and a second list built somewhere else
     * would eventually disagree with this one.
     *
     * @return array
     */
    public static function metricChoices() {

        return self::choices( \OWA\Core\CoreAPI::getAllMetrics() );
    }

    /** @return array */
    public static function dimensionChoices() {

        return self::choices( \OWA\Core\CoreAPI::getAllDimensions() );
    }

    /**
     * metric name => the fact tables it can be measured in.
     *
     * Read from ResultSetManager, which is what actually decides, so the
     * builder cannot come to a different conclusion from the query engine.
     *
     * @return array<string,array>
     */
    public static function metricEntities() {

        $out = array();

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        foreach ( array_keys( (array) \OWA\Core\CoreAPI::getAllMetrics() ) as $name ) {

            $out[ $name ] = $rsm->compatibleEntities( array( $name ) );
        }

        return $out;
    }

    /**
     * dimension name => the fact tables it is related to.
     *
     * The same relation ResultSetManager checks when it picks a base entity, so
     * the builder narrows on exactly what the query engine would refuse.
     *
     * @return array<string,array>
     */
    public static function dimensionEntities() {

        $rsm = new \OWA\Module\Base\Classes\ResultSetManager;

        /*
         * The fact tables a query can be answered from. Taken from the metric
         * registry rather than listed, so a module adding a fact table and
         * metrics for it is covered without a change here.
         */
        $entities = array();

        foreach ( array_keys( (array) \OWA\Core\CoreAPI::getAllMetrics() ) as $metric ) {

            foreach ( $rsm->compatibleEntities( array( $metric ) ) as $entity ) {

                $entities[ $entity ] = true;
            }
        }

        $entities = array_keys( $entities );

        $out = array();

        foreach ( array_keys( (array) \OWA\Core\CoreAPI::getAllDimensions() ) as $name ) {

            $related = array();

            foreach ( $entities as $entity ) {

                if ( $rsm->isDimensionRelated( $name, $entity ) ) {

                    $related[] = $entity;
                }
            }

            $out[ $name ] = $related;
        }

        return $out;
    }

    /**
     * A registry map reduced to {name, label}, sorted by what a person reads.
     *
     * The registry entries carry far more than the picker needs -- the SQL
     * expression among it -- and handing the whole thing to a template would
     * put the query internals into the page source.
     *
     * @param mixed $registry
     * @return array
     */
    private static function choices( $registry ) {

        $out = array();

        foreach ( (array) $registry as $name => $entry ) {

            $entry = (array) $entry;

            $label = '';

            foreach ( array( 'label', 'name' ) as $key ) {

                if ( ! empty( $entry[ $key ] ) && is_string( $entry[ $key ] ) ) {

                    $label = $entry[ $key ];
                    break;
                }
            }

            $out[] = array(
                'name'  => (string) $name,
                'label' => $label !== '' ? $label : (string) $name,
            );
        }

        usort( $out, static function ( $a, $b ) {

            return strcasecmp( $a['label'], $b['label'] );
        } );

        return $out;
    }

    function success() {

        $this->setSubview( 'base.customReportEdit' );
        $this->setView( 'base.report' );
        // $this->data, not $this->get() -- get() reads the request, and this
        // was set by action().
        $this->set( 'title', ! empty( $this->data['custom_report_id'] )
            ? 'Edit Custom Report' : 'New Custom Report' );

        /*
         * The reporting NAV is hidden: this is one screen about one report, and
         * the left menu is for moving between reports.
         *
         * The period picker and Live View go too -- the builder runs no query,
         * so neither would change anything on it. The sites filter STAYS,
         * because the site is a real input here: it decides which site the View
         * link and the saved report's URL name.
         */
        $this->hideReportingNavigation();
        $this->hideTimeControls();
    }
}
