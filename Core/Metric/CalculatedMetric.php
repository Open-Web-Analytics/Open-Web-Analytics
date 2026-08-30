<?php

namespace OWA\Core\Metric;

/**
 * A metric computed from other metrics rather than from a column.
 *
 * Only the flag now. The children and formula moved to Core\Metric so that
 * ConfigurableMetric -- which extends that class, not this one -- can declare a
 * calculated metric from configuration. Its calculated branch called
 * setChildMetric() on a class that did not have it, which is why the type had
 * never actually worked.
 */
class CalculatedMetric extends \OWA\Core\Metric {

    var $is_calculated = true;
}

?>
