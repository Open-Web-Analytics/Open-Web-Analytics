<div id="<?php echo $dom_id;?>" class="owa_metricInfobox">
	<p class="owa_metricInfoboxLabel"><?php echo $label?></p>
	<p class="owa_metricInfoboxLargeNumber"><?php echo $count;?></p>
	<p><?php echo $this->displaySparkline($dom_id, $trend);?></p>
</div>