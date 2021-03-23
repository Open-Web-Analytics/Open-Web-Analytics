<?php 

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
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
 * Chart Data Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2008 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class owa_chartData {

    var $series_data = array();
    var $series_labels = array();

    function __construct() {

        return;
    }

    function owa_chartData() {

        return owa_chartData::__construct();
    }

    function setSeries($name, $data, $label = '') {

        $this->series_data[$name] = $data;
        $this->series_label[$name] = $label;
        return;
    }

    function getSeriesData($name) {

        if (array_key_exists($name, $this->series_data)) {
            return $this->series_data[$name];
        } else {
            return array();
        }

    }

    function getSeriesLabel($name) {

        if (array_key_exists($name, $this->series_label)) {
            return $this->series_label[$name];
        } else {
            return false;
        }
    }

    function getMin($name) {

        $min = min($this->getSeriesData($name));

        if ($min >= 0) {
            return 0;
        } else {
            return $min - 2;
        }

    }

    function getMax($name, $name2 = null) {

        $max_values = array();

        $max_values[] = max($this->getSeriesData($name));

        if (!empty($name2)) {
            $max_values[] = max($this->getSeriesData($name2));
        }

        $max = max($max_values);

        return $max + 2;
    }

    function checkForSeries() {

        $counts = array();
        foreach ($this->series_data as $series) {

            $counts[] = count($series);
        }

        if (array_sum($counts) > 0) {
            return true;
        } else {
            return false;
        }
    }


}



?>