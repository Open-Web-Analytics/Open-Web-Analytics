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
class CustomReportEdit extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';

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

        $this->set( 'custom_report_name', $report ? $report['name'] : '' );
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

        $this->set( 'metric_choices',    self::metricChoices() );
        $this->set( 'dimension_choices', self::dimensionChoices() );
        $this->set( 'widget_types',      \OWA\Module\Base\Classes\CustomReports::WIDGET_TYPES );
        $this->set( 'max_widgets',       \OWA\Module\Base\Classes\CustomReports::MAX_WIDGETS );
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
        $this->setView( 'base.options' );
        $this->set( 'title', $this->get( 'custom_report_id' ) ? 'Edit Custom Report' : 'New Custom Report' );
    }
}
