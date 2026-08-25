<?php
namespace OWA\Core;

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
 * The metric sets a site offers.
 *
 * A report shows one dimension measured several ways -- site usage, e-commerce,
 * each goal group. Those are METRIC SETS. The interface currently draws them as
 * tabs; that is a presentation choice and is expected to change, so nothing
 * here is named after it.
 *
 * They are NOT configuration and a report cannot enumerate them: which sets
 * exist depends on the site, and a new one appears the moment someone adds a
 * goal. So they are derived here, per site, and merged with whatever a report
 * declares.
 *
 * Extracted from ReportController::pre(), where this was built inline as
 * `$tabs`. One source, two consumers: the widget renderer reads the shape
 * below, and toLegacyTabs() derives the array the older report templates still
 * read. When those templates are gone, so is that method.
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 */
class MetricSets {

    /**
     * The set every site has.
     *
     * Named rather than inlined because it is also the answer to "what does a
     * report show when the site has nothing else configured".
     */
    const DEFAULT_KEY = 'site_usage';

    /**
     * Every metric set available for a site, keyed by name.
     *
     * Each is: label, metrics, chartMetric.
     *
     * `sort` is deliberately absent. The runtime sets have always carried one
     * and nothing has ever used it: the only template that reads a set's sort
     * does `$view->sort ?: $tab['sort']`, and all 20 reports with a grid
     * declare their own sort, so the set's never applies. The other 9 build no
     * grid at all. Carrying it forward would be preserving a field that has
     * never had an effect.
     *
     * @param string|int $siteId
     * @return array<string, array>
     */
    public static function forSite( $siteId ) {

        $sets = array();

        if ( ! $siteId ) {

            return $sets;
        }

        $sets[ self::DEFAULT_KEY ] = array(
            'label'       => 'Site Usage',
            'metrics'     => 'visits,pagesPerVisit,visitDuration,bounceRate,uniqueVisitors',
            'chartMetric' => 'visits',
        );

        if ( \OWA\Core\CoreAPI::getSiteSetting( $siteId, 'enableEcommerceReporting' ) ) {

            $sets['ecommerce'] = array(
                'label'       => 'e-commerce',
                'metrics'     => 'visits,transactions,transactionRevenue,revenuePerVisit,revenuePerTransaction,ecommerceConversionRate',
                'chartMetric' => 'transactions',
            );
        }

        $goals = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );

        foreach ( (array) $goals->getActiveGoalGroups() as $group ) {

            $sets[ self::goalGroupKey( $group ) ] = self::goalGroupSet(
                $goals->getGoalGroupLabel( $group ),
                (array) $goals->getActiveGoalsByGroup( $group )
            );
        }

        return $sets;
    }

    /**
     * One metric per ACTIVE GOAL, flat, across every group.
     *
     * Not a metric set and deliberately not registered as one: sets become
     * tabs, and this is a panel of boxes inside one report. Adding it to
     * forSite() would grow a spurious tab on every tabbed report in the
     * install.
     *
     * It is the list `goals` draws its Goal Performance boxes from, which its
     * controller assembled inline. A site with no active goals yields an empty
     * string -- the report drops the panel rather than asking for no metrics,
     * which is what the controller's `if ($view->goal_metrics)` did.
     *
     * @param string $siteId
     * @return string comma-separated metric names, or '' when the site has no active goals
     */
    public static function activeGoalCompletions( $siteId ) {

        $manager = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'goalManager', $siteId );

        $metrics = array();

        foreach ( (array) $manager->getActiveGoals() as $goal ) {

            if ( isset( $goal['goal_number'] ) ) {

                $metrics[] = sprintf( 'goal%sCompletions', $goal['goal_number'] );
            }
        }

        return implode( ',', $metrics );
    }

    /** The set name for a goal group. */
    public static function goalGroupKey( $group ) {

        return 'goal_group_' . $group;
    }

    /**
     * One goal group's metric set.
     *
     * Split out from forSite() because this is the only part with any logic in
     * it -- assembling a metric name per active goal -- and it was otherwise
     * only reachable on a site that has goals configured. Dropping the whole
     * goal-group loop changed nothing observable on a site without them, which
     * is a branch nothing was checking.
     *
     * @param string $label the group's display name
     * @param array $activeGoals goal numbers active in the group
     * @return array
     */
    public static function goalGroupSet( $label, array $activeGoals ) {

        $metrics = 'visits';

        foreach ( $activeGoals as $goal ) {

            $metrics .= sprintf( ',goal%sCompletions', $goal );
        }

        /*
         * Always last, and always present. A group with no active goals still
         * has a total, and the grid's columns follow the order of this list --
         * so appending per-goal metrics after it would move the total column
         * depending on how many goals a group happens to have.
         */
        $metrics .= ',goalValueAll';

        return array(
            'label'       => $label,
            'metrics'     => $metrics,
            'chartMetric' => 'visits',
        );
    }

    /**
     * The same sets in the shape the older report templates read.
     *
     * They index `tab_label` and `trendchartmetric`, and one of them reads
     * `sort`. Kept so both renderers work from one source while the conversion
     * is in progress; it goes when the last template that reads it does.
     *
     * @param array $sets
     * @return array
     */
    public static function toLegacyTabs( array $sets ) {

        $tabs = array();

        foreach ( $sets as $key => $set ) {

            $tabs[ $key ] = array(
                'tab_label'        => $set['label'],
                'metrics'          => $set['metrics'],
                // Never read -- see forSite(). Present because the template
                // indexes it, and a missing key is a warning on every render.
                'sort'             => '',
                // Same reasoning as `sort` above: a set that arrives without one
                // is a missing key on every render, not a fatal. Report
                // definitions get a default filled in before they reach here.
                'trendchartmetric' => $set['chartMetric'] ?? '',
            );
        }

        return $tabs;
    }
}
