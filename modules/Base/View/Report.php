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
// $Id$
//


/**
 * View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Report extends \OWA\Core\View {
    var $report_params;

    function render($data) {

        // Set Page title
        $this->t->set('page_title', $this->get('title'));

        // Set Page headline
        $this->body->set('title', $this->get('title'));
        $this->body->set('titleSuffix', $this->get('titleSuffix'));

        // Set reporting period
        $this->setPeriod($this->data['period']);
        $this->subview->body->set('is_default_period', $this->get('is_default_period'));

        //create the report control params array
        // TODO: this is evil as it may contain xss. Kill it's use downstream with fire, then nuke it here.
        $this->report_params = $this->data['params'];

        unset($this->report_params['guid']);
        unset($this->report_params['caller']);
		$this->t->set('params', $this->report_params);
        $this->body->set('params', $this->report_params);
        $this->subview->body->set('params', $this->report_params);


        // set site filter list
        $this->body->set('sites', $this->get('sites') );
        $this->body->set('site_hierarchy', $this->get('site_hierarchy') );

        $this->body->set('dom_id', $this->get( 'dom_id' ) );
        // add if here
        $this->subview->body->set('dom_id', $this->get( 'dom_id' ) );
        $this->body->set('do', $this->data['do']);

        // Set navigation
        $this->body->set('hideReportingNavigation', $this->get('hideReportingNavigation') );
        $this->body->set('top_level_report_nav', $this->get('top_level_report_nav'));

        $this->body->set('hideSitesFilter', $this->get('hideSitesFilter') );
        $this->body->set('hideTimeControls', $this->get('hideTimeControls') );
        $this->body->set('title_actions', $this->get('title_actions') );
        $this->body->set('title_count', $this->get('title_count') );

        $this->body->set('currentSiteId', $this->get('currentSiteId'));


        // load body template
        $this->body->set_template('report.php');

        // set link state used by report navigation
        $period = $this->get('period');

        $link_state = array(
            'siteId' => $this->get('currentSiteId')
        );

        if ( $period->get() === 'date_range' ) {

            $link_state[ 'startDate' ] = $period->getStartDate()->getYyyymmdd();
            $link_state[ 'endDate' ] = $period->getEndDate()->getYyyymmdd();

        } else {

            $link_state[ 'period' ] = $period->get();
        }

        $this->_setLinkState( $link_state );

        // set Js libs to be loaded
        $this->setJs('owa.reporting', 'base/dist/owa.reporting-combined-min.js');
        
        // css libs to be loaded
        
        /*
		$this->setCss('base/css/smoothness-1.8.12/jquery-ui.css');
        $this->setCss('base/css/jquery.ui.selectmenu.css');
        $this->setCss('base/css/ui.jqgrid.css');
        $this->setCss('base/css/chosen/chosen.css');
        $this->setCss("base/css/owa.admin.css");
        $this->setCss("base/css/owa.report.css");
		*/
		$this->setCss("base/css/font-awesome/css/all.min.css");
        $this->setCss("base/css/owa.reporting-css-combined.css");
        $additionalCss = $this->c->get('base','additionalCss');
        if (is_array($additionalCss)) {
            foreach ($additionalCss as $css) {
                $this->setCss($css);
            }
        }
    }

    /**
     * Set report period
     *
     * @access public
     * @param string $period
     */
    function setPeriod( $period ) {

        // set in various templates and params
        $this->data['params']['period'] = $period->get();
        $this->body->set( 'period_obj', $period);
        $this->subview->body->set( 'period_obj', $period);
        $this->body->set( 'period', $period->get() );
        $this->subview->body->set( 'period', $period->get() );
        // set period label
        $period_label = $period->getLabel();
        $this->body->set('period_label', $period_label);
        $this->subview->body->set('period_label', $period_label);
        $start_date = $period->get('startDate');
        $this->body->set( 'startDate', $start_date );
        $this->subview->body->set('startDate', $start_date );
        $end_date =  $period->get('endDate');
        $this->body->set('endDate', $end_date );
        $this->subview->body->set('endDate', $end_date );
    }

    /**
     * Applies calling params
     *
     * @access     private
     * @param     array $properties
     */
    function _setParams($params = null) {

        if(!empty($params)) {
            foreach ($params as $key => $value) {
                if(!empty($value)) {
                    $this->params[$key] = $value;
                }
            }
        }
    }
}







?>
