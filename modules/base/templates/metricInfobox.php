<div id="<?php echo $dom_id;?>" class="owa_metricInfobox">
	<p class="owa_metricInfoboxLabel"><?php echo $count->getlabel($count->aggregates[$metric_name]['name']);?></p>
	<p class="owa_metricInfoboxLargeNumber"><?php echo $count->aggregates[$metric_name]['value'];?></p>
	<p><?php echo $this->displaySeriesAsSparkline($count->aggregates[$metric_name]['name'], $trend, $dom_id);?></p>
</div>