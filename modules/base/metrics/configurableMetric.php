<?php

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
 * Configurable Metric
 *
 * This metric produces a count of goal completions for a specific goal number
 * Goal number is passed into the object dynamicaly when the metric is created.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2012 Peter Adams http://www.openwebanalytics.com
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.5.3
 */

class owa_configurableMetric extends owa_metric {

    function __construct( $params ) {
        
        $this->setMetricType( $params['metric_type'] );
        $this->setName( $params['name'] );
        $this->setLabel( $params['label'] );
        $this->setDataType( $params['data_type'] );
        
        if ( $this->isCalculated() ) {
            foreach ( $params['child_metrics'] as $child ) {
                $this->setChildMetric( $child );
            }
            
            $this->setFormula( $params['formula']);    
        } else {
            $this->setEntity( $params['entity'] );
            $this->setColumn( $params['column'] );
        }
        
        return parent::__construct();
    }
}

?>