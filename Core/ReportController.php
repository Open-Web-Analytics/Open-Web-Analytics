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
// $Id$
//


/**
 * Abstract Report Controller Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class ReportController extends \OWA\Core\AdminController {
	
	/**
	 * An array of request param keys that
	 * should be passed downstream as state
	 */
	var $state_keys = [];
	
    /**
     * Constructor
     *
     * @param array $params
     * @return
     */
    function __construct($params) {
        $this->setControllerType('report');
        $this->setRequiredCapability('view_reports');
        parent::__construct($params);

        // set a siteId is none is set on the request params
        $siteId = $this->getCurrentSiteId();

        if ( ! $siteId ) {
            //$siteId = $this->getDefaultSiteId();
        }

        $this->setParam( 'siteId', $siteId );
    }

    /**
     * An invalid period is refused rather than quietly replaced.
     *
     * It used to fall back to the default reporting period and say so only in a
     * debug line, which is off unless debugging is on. That silence is not
     * hypothetical harm: the visitor roster linked to `period=all_time` for
     * years, all_time was never an accepted value, and every one of those links
     * served seven days while claiming to show a visitor's whole history.
     * Nobody could see it.
     *
     * The picker constrains the choice, so an invalid period means the URL was
     * edited by hand -- exactly the case where an answer is better than a
     * guess. This is also what the REST API already does, against the same
     * list, so the two paths now agree on what a period may be AND on what
     * happens when it is not one.
     *
     * setFromMap() keeps its fallback. It is the backstop for callers that do
     * not come through a controller, and defence in depth is worth more than
     * the tidiness of removing it.
     */
    function validate() {

        /*
         * A range is both bounds or neither, and ordered. One bound alone is
         * not a range: an end date on its own resolved its missing start to
         * today, so "up to the 10th" ran from today BACKWARDS to the 10th.
         *
         * Constructed rather than named, because addValidation() resolves a
         * name through the legacy owa_*Validation compat map, and this class
         * has no legacy name to bridge. setValidation() takes the object.
         */
        $range = new \OWA\Core\Validation\DateRange();

        $range->setValues( array(
            'period'    => $this->getParam('period'),
            'startDate' => $this->getParam('startDate'),
            'endDate'   => $this->getParam('endDate'),
        ) );

        $this->setValidation( 'dateRange', $range );

        $period = $this->getParam('period');

        if ( $period ) {

            $timePeriod = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'timePeriod' );

            $this->addValidation(
                'period',
                $period,
                'inArray',
                array(
                    'possible_values' => $timePeriod->getValidPeriods(),
                    'stopOnError'     => true,
                    'errorMsg'        => sprintf(
                        '"%s" is not a reporting period. Choose one from the date picker.',
                        htmlspecialchars( (string) $period, ENT_QUOTES ) ),
                )
            );
        }
    }

    /**
     * Where a failed validation lands.
     *
     * Core\Controller has no errorAction() of its own -- doAction() calls it
     * when validations fail, so a controller that validates without defining
     * one fatals instead of refusing. Defining it here is part of adding the
     * validation above, not an extra.
     */
    function errorAction() {

        if ( ! headers_sent() ) {
            http_response_code( 400 );
        }

        $this->set( 'error_msg', 'The report could not be shown: '
            . implode( ' ', (array) $this->getValidationErrorMsgs() ) );

        $this->setView( 'base.error' );

        return $this->data;
    }

    /**
     * Pre Action
     * Current user is fully authenticated and loaded by this point
     *
     */
    function pre() {

        $sites = $this->getSitesAllowedForCurrentUser();
        $this->set('sites', $sites);

        $this->set( 'currentSiteId', $this->getParam('siteId') );

        // pass full set of params to view
        $this->data['params'] = $this->params;

        // setup the time period object in $this->period
        $this->setPeriod();
        // check to see if the period is a default period. TODO move this ot view where needed.
        $this->set('is_default_period', $this->period->isDefaultPeriod() );
        $this->setView('base.report');
        $this->setViewMethod('delegate');

        /*
         * Derived from the report's identity, not from the action that reached
         * it.
         *
         * Every report used to have its own action, so hyphenating `do` gave
         * each one a distinct container id -- base-reportPages,
         * base-reportEntryPages. Reaching them all through base.report collapses
         * that to the single value "base-report" for every report on the
         * installation.
         *
         * Nothing keys persistence off dom_id today, so nothing is broken by the
         * collision right now. It is still worth not introducing: the id is what
         * OWA.report binds to and what anything remembering per-report UI state
         * would naturally key on, and a collision that costs nothing today is
         * the kind that costs a confusing afternoon later.
         *
         * The direct route is unchanged -- there is no reportId on it -- so this
         * adds a distinct id for the new route rather than altering the old one.
         */
        $reportId = $this->getParam('reportId');

        $this->dom_id = $reportId
            ? 'report-' . str_replace( array( '.', '_' ), '-', (string) $reportId )
            : str_replace('.', '-', (string) $this->getParam('do'));
        $this->data['dom_id'] = $this->dom_id;
        $this->data['do'] = $this->getParam('do');
        $nav = \OWA\Core\CoreAPI::getGroupNavigation('Reports');
        
        /*
         * The metric sets this site offers -- site usage, e-commerce if the
         * site has it, one per active goal group.
         *
         * Derived by Core\MetricSets rather than built here: a report shows one
         * dimension measured several ways, and which ways exist depends on the
         * site, not on the report. The interface draws them as tabs today,
         * which is why this used to be called $tabs; that is a presentation
         * choice and is expected to change.
         */
        $siteId = $this->get('siteId');

        $metricSets = \OWA\Core\MetricSets::forSite( $siteId );

        $tabs = \OWA\Core\MetricSets::toLegacyTabs( $metricSets );

        if ( $siteId && ! \OWA\Core\CoreAPI::getSiteSetting( $siteId, 'enableEcommerceReporting' ) ) {

            unset( $nav['Ecommerce'] );
        }

        $this->set('metricSets', $metricSets);

        $this->set('tabs', $tabs);
        $this->set('tabs_json', json_encode($tabs));

        $nav = $this->withCustomReports( $nav );

        $this->set('top_level_report_nav', $nav);
    }

    function post() {
		
		// pass the state_keys var to views
        $this->set( 'state_keys', $this->state_keys );
    }
    
    /**
	 * Used to designate a request param as state for
     * use by downstream views and templates
     */
    function addState( $param_name ) {
	    
	    $this->state_keys[] = $param_name;
    }

    function setTitle($title, $suffix = '') {

        $this->set('title', $title);
        $this->set('titleSuffix', $suffix);
    }

    /**
     * Chooses a siteId from a list of AllowedSites
     *
     * needed jsut in case a siteId is not passed on the request.
     * @return string
     */
    protected function getDefaultSiteId() {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->select('site_id');
        $db->from('owa_site');
        $db->limit(1);
        $ret = $db->getOneRow();

        return $ret['site_id'];
    }

    /**
     * The reader's own custom reports, as a nav group.
     *
     * Added here rather than through registerNavigation() because these are
     * ROWS, not registrations: they differ per reader, they change while the
     * install is running, and half of them are invisible to anyone but their
     * author. A module registering navigation is answering "what does this
     * install have"; this is answering "what does this person have".
     *
     * The list ends with "See more..." rather than relying on the group heading
     * -- the heading is a toggle as much as a link, so a reader with more than
     * ten needs a line that plainly says where the rest are.
     *
     * One small indexed query per report render. Worth it for the thing being
     * bought -- a custom report that is not in the nav is a custom report
     * nobody finds twice -- but it is the reason this reads ten rows and not
     * five hundred.
     *
     * @param array $nav
     * @return array
     */
    private function withCustomReports( array $nav ) {

        $user = \OWA\Core\CoreAPI::getCurrentUser();

        // The roster's own capability: an entry that leads to a screen the
        // reader cannot open is worse than no entry.
        if ( ! $user->isCapable( 'view_site_list' ) ) {

            return $nav;
        }

        $reports = \OWA\Module\Base\Classes\CustomReports::recent(
            (string) $user->getUserData( 'user_id' ),
            (bool) $user->isCapable( 'edit_users' )
        );

        $links = array();

        foreach ( $reports as $i => $report ) {

            $links[] = array(
                'ref' => array(
                    'do'       => 'base.report',
                    'reportId' => \OWA\Module\Base\Controller\Report::CUSTOM_PREFIX . $report['id'],
                ),
                'anchortext' => $report['name'],
                'order'      => $i,
                'priviledge' => 'view_reports',
                'icon_class' => '',
            );
        }

        /*
         * ...and the way to all of them, last.
         *
         * Always present, including when there are none: the roster is where a
         * report gets built, so an empty group still has somewhere to send a
         * reader.
         */
        $links[] = array(
            'ref'        => array( 'do' => 'base.customReports' ),
            'anchortext' => 'See more...',
            'order'      => count( $links ),
            'priviledge' => 'view_site_list',
            'icon_class' => '',
        );

        $nav['Custom Reports'] = array(
            'ref'        => array( 'do' => 'base.customReports' ),
            'anchortext' => 'Custom Reports',
            'order'      => 999,
            'priviledge' => 'view_site_list',
            'icon_class' => 'fa fa-th-list',
            'subgroup'   => $links,
        );

        return $nav;
    }

    protected function hideReportingNavigation() {

        $this->set('hideReportingNavigation', true);
    }

    /**
     * Hide the period picker and the Live View switch.
     *
     * For a screen that is inside the reporting UI but is not a report of a
     * time range -- the custom report roster lists reports, and neither a date
     * range nor a refresh timer means anything to a list of them. Offering
     * controls that change nothing is worse than offering none.
     */
    protected function hideTimeControls() {

        $this->set('hideTimeControls', true);
    }

    protected function hideSitesFilter() {

        $this->set('hideSitesFilter', true);
    }
}

?>