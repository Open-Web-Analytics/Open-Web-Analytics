<div id="<?php echo $dom_id;?>" class="owa_metricInfobox">
	<p class="owa_metricInfoboxLabel"><?php echo $count->getlabel($count->aggregates[0]['name']);?></p>
	<p class="owa_metricInfoboxLargeNumber"><?php echo $count->aggregates[0]['value'];?></p>
	<p><?php echo $this->displaySeriesAsSparkline($count->aggregates[0]['name'], $trend, $dom_id);?></p>
</div>