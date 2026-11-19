<?php

namespace OWA\Module\Base\Metric;

/**
 * A metric built from a definition (Module::registerMetricDefinition(),
 * config/metrics.php): an aggregate of one column of an entity, or a ratio or
 * difference of two other metrics.
 */

class ConfigurableMetric extends \OWA\Core\Metric {

    public function __construct( $params ) {
        
        $this->setMetricType( $params['metric_type'] );
        $this->setName( $params['name'] );
        $this->setLabel( $params['label'] );
        $this->setDataType( $params['data_type'] );
        
        if ( $this->isCalculated() ) {

            /*
             * A calculated metric is a difference or a ratio, each naming its
             * two sides. A formula -- an expression eval()'d with the children
             * substituted in by name -- was the 1.x form and is refused.
             */
            if ( ! empty( $params['minuend'] ) ) {

                $this->setDifference( $params['minuend'], $params['subtrahend'] );

            } elseif ( ! empty( $params['numerator'] ) ) {

                $this->setRatio(
                    $params['numerator'],
                    $params['denominator'],
                    isset( $params['precision'] ) && $params['precision'] !== ''
                        ? $params['precision'] : null );

            } else {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'Metric %s is calculated but declares neither a ratio (numerator, denominator) nor a '
                  . 'difference (minuend, subtrahend). A formula is not supported in v2.0.', $params['name'] ) );
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
        
        parent::__construct();
    }
}
