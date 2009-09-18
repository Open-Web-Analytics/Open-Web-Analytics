<div class="owa_reportSectionHeader">
	There were <?php echo $feed_counts['fetch_count'];?> requests of this web site's feeds.
</div>

<div class="owa_reportSectionContent">
	<?php echo $this->displayChart('feed_trend', $feed_chart_data);?>
	<?php include('report_feed_core_metrics.tpl');?>
</div>

<?php echo $this->getWidget('base.widgetFeedReaderTypes', array('height' => '400px'));?>

<BR><BR>		

<?php echo $this->getWidget('base.widgetFeedTypes');?>


						