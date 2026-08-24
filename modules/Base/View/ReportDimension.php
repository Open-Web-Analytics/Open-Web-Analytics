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
 *  Dimension Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class ReportDimension extends \OWA\Core\View {

    function render($data) {

        // Assign Data to templates
        $this->body->set('tabs', $this->get('tabs') );
        $this->body->set('metrics', $this->get('metrics'));
        $this->body->set('dimensions', $this->get('dimensions'));
        $this->body->set('sort', $this->get('sort'));
        $this->body->set('resultsPerPage', $this->get('resultsPerPage'));
        $this->body->set('dimensionLink', $this->get('dimensionLink'));
        $this->body->set('trendChartMetric', $this->get('trendChartMetric'));
        $this->body->set('trendTitle', $this->get('trendTitle'));
        $this->body->set('constraints', $this->get('constraints'));
        $this->body->set('gridTitle', $this->get('gridTitle'));
        $this->body->set('excludeColumns', $this->get('excludeColumns'));
        $this->body->set_template('report_dimensionalTrend.php');
    }
}
