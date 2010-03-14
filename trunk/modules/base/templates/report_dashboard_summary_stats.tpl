<?php if (!empty($summary_stats)):?>

<table  id="" cellpadding="0" cellspacing="0" width="100%">
	<tr>	
		<td valign="top">
			<div class="owa_metricInfobox">
				<span class="owa_metricInfoboxLabel">Visits</span><BR>
				<span class="owa_metricInfoboxLargeNumber"><?php echo $this->formatNumber($summary_stats['sessions'],0);?></span><BR>
				<?php echo $this->displaySparkline('sessionsTrend', $site_trend['sessions']);?>
			</div>
			
			<div class="owa_metricInfobox">	 
				<span class="owa_metricInfoboxLabel">Pages/Visit</span><BR>
				<span class="owa_metricInfoboxLargeNumber"><?php echo round($summary_stats['pages_per_visit'],1);?></span><BR>			
				<?php echo $this->displaySparkline('pagePerVisitTrend', $site_trend['pages_per_visit']);?>			
			</div>
		
			<div class="owa_metricInfobox">
				<span class="owa_metricInfoboxLabel">New Visitors</span><BR>
				<span class="owa_metricInfoboxLargeNumber"><?php echo $summary_stats['new_visitor'];?></span><BR>
				<?php echo $this->displaySparkline('newVisitorsTrend', $site_trend['new_visitor']);?>	
 
			</div>
		
			<div class="owa_metricInfobox">
				<span class="owa_metricInfoboxLabel">Repeat Visitors</span><BR>
				<span class="owa_metricInfoboxLargeNumber"><?php echo $summary_stats['repeat_visitor'];?></span><BR>
				<?php echo $this->displaySparkline('repeatVisitorsTrend', $site_trend['repeat_visitor']);?>	
			</div>
	
			<div class="owa_metricInfobox">
				<span class="owa_metricInfoboxLabel">Unique Visitors</span><BR>
				<span class="owa_metricInfoboxLargeNumber"><?php echo $summary_stats['unique_visitors'];?></span><BR>
				<?php echo $this->displaySparkline('uniqueVisitorsTrend', $site_trend['unique_visitors']);?>	
			</div>
		</td>
	</tr>
</table>
<?php else:?>
	There are no statistics for this time period.
<?php endif;?>

