<?php
namespace OWA\Module\Base\View;

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
 * A report that lays its widgets out in a grid.
 *
 * The other report subviews each hard-code one arrangement of widgets, which
 * is why there are nine of them for what is nearly the same page. This one
 * renders whatever the definition's `widgets` list says, positioned by
 * Core\ReportGrid.
 *
 * It passes the list through rather than interpreting it -- the template knows
 * what a trend is and what a grid is, because those are markup decisions. What
 * this class owns is that the widgets and the report-wide constraint reach the
 * template at all.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 */
class ReportWidgets extends \OWA\Core\View {

    function render() {

        $this->body->set( 'widgets', $this->get( 'widgets' ) );

        // Absent on almost every report; the template draws nothing for it.
        $this->body->set( 'deprecated', $this->get( 'deprecated' ) );

        /*
         * Report-wide, not per widget. Every widget in a report looks at the
         * same rows -- a detail report constrains to one host, and its trend
         * and its grid both have to. Repeating it in each widget's query would
         * let them disagree, which is a report that shows one thing and charts
         * another.
         */
        $this->body->set( 'constraints', $this->get( 'constraints' ) );

        /*
         * Report-wide for the same reason, and the seam a metric set will
         * swap: every widget queries the same metrics, so they are held once.
         */
        $this->body->set( 'metrics', $this->get( 'metrics' ) );

        /*
         * The metric sets this report is shown for. A report is one dimension
         * measured several ways, so the widgets are rendered once per set --
         * each set supplying the metrics and the chart metric.
         *
         * Derived per site by Core\MetricSets, so a definition never lists
         * them: which exist depends on the site's settings and goals.
         */
        $this->body->set( 'metricSets', $this->get( 'metricSets' ) );

        $this->body->set_template( 'report_widgets.php' );
    }
}
