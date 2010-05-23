<?php if (!empty($summary)):?>

<table  id="" cellpadding="0" cellspacing="0" width="100%">
	<tr>	
		<td valign="top">
			<div class="owa_metricInfobox">
				<p class="owa_metricInfoboxLabel">Visits</p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo $this->formatNumber($summary->getAggregateMetric('visits'),0);?></p>
				<p><?php echo $this->displaySparkline('sessionsTrend', $summary->getSeries('visits'));?></p>
			</div>
			
			<div class="owa_metricInfobox">	 
				<p class="owa_metricInfoboxLabel">Pages/Visit</p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo round($summary->getAggregateMetric('pagesPerVisit'),1);?></p>		
				<p><?php echo $this->displaySparkline('pagePerVisitTrend', $summary->getSeries('pagesPerVisit'));?></p>			
			</div>
		
			<div class="owa_metricInfobox">
				<p class="owa_metricInfoboxLabel">New Visitors</p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo $summary->getAggregateMetric('newVisitors');?></p>
				<p><?php echo $this->displaySparkline('newVisitorsTrend', $summary->getSeries('newVisitors'));?></p>	
			</div>
		
			<div class="owa_metricInfobox">
				<p class="owa_metricInfoboxLabel">Repeat Visitors</p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo $summary->getAggregateMetric('repeatVisitors');?></p>
				<p><?php echo $this->displaySparkline('repeatVisitorsTrend', $summary->getSeries('repeatVisitors'));?></p>
			</div>
	
			<div class="owa_metricInfobox">
				<p class="owa_metricInfoboxLabel">Unique Visitors</p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo $summary->getAggregateMetric('uniqueVisitors');?></p>
				<p><?php echo $this->displaySparkline('uniqueVisitorsTrend', $summary->getSeries('uniqueVisitors'));?></p>
			</div>
			
			<?php echo $this->displayMetricInfobox(array(
													'metrics' => 'pageViews',
													'constraints' => sprintf('site_id=%s', $site_id),
													'period' => $this->get('period_obj')->get()
												  ));
			?>
			
		</td>
	</tr>
</table>
<?php else:?>
	There are no statistics for this time period.
<?php endif;?>

