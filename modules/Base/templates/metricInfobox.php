<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div id="<?php echo $view->dom_id;?>" class="owa_metricInfobox">
    <p class="owa_metricInfoboxLabel"><?php echo $view->count->getlabel($view->count->aggregates[$view->metric_name]['name']);?></p>
    <p class="owa_metricInfoboxLargeNumber"><?php echo $view->count->aggregates[$view->metric_name]['value'];?></p>
    <p><?php echo $view->displaySeriesAsSparkline($view->count->aggregates[$view->metric_name]['name'], $view->trend, $view->dom_id);?></p>
</div>