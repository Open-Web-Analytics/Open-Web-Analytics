<?php

namespace OWA\Module\Base\Metric;


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

class ConfigurableMetric extends \OWA\Core\Metric {

    function __construct( $params ) {
        
        $this->setMetricType( $params['metric_type'] );
        $this->setName( $params['name'] );
        $this->setLabel( $params['label'] );
        $this->setDataType( $params['data_type'] );
        
        if ( $this->isCalculated() ) {

            /*
             * A ratio names its two sides; a formula names an expression and
             * the children in it separately. Both are calculated metrics as far
             * as everything else is concerned -- the ratio just has nothing to
             * substitute.
             */
            if ( ! empty( $params['numerator'] ) ) {

                $this->setRatio(
                    $params['numerator'],
                    $params['denominator'],
                    isset( $params['precision'] ) && $params['precision'] !== ''
                        ? $params['precision'] : null );

            } else {

                foreach ( $params['child_metrics'] as $child ) {
                    $this->setChildMetric( $child );
                }

                $this->setFormula( $params['formula']);
            }
        } else {
            $this->setEntity( $params['entity'] );
            $this->setColumn( $params['column'] );

            /*
             * Only the avg_difference kind uses this, and it is set from the
             * definition rather than defaulted, so a definition that names the
             * kind without supplying the column fails loudly instead of
             * subtracting nothing.
             */
            if ( array_key_exists( 'subtrahend_column', $params ) ) {

                $this->setSubtrahendColumn( $params['subtrahend_column'] );
            }

            /*
             * What the metric counts, when it counts only some rows. Absent
             * means every row, which is what every definition meant before
             * conditions existed.
             */
            if ( ! empty( $params['condition'] ) ) {

                $this->setCondition( (array) $params['condition'] );
            }
        }
        
        return parent::__construct();
    }
}

?>