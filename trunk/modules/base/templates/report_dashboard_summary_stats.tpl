<?php if (!empty($summary_stats)):?>

<table  id="" cellpadding="0" cellspacing="0" width="100%">
	<tr>	
		<td valign="top">
			<div class="owa_metricInfobox">
				<p class="owa_metricInfoboxLabel">Visits</p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo $this->formatNumber($summary_stats['sessions'],0);?></p>
				<p><?php echo $this->displaySparkline('sessionsTrend', $site_trend['sessions']);?></p>
			</div>
			
			<div class="owa_metricInfobox">	 
				<p class="owa_metricInfoboxLabel">Pages/Visit</p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo round($summary_stats['pages_per_visit'],1);?></p>		
				<p><?php echo $this->displaySparkline('pagePerVisitTrend', $site_trend['pages_per_visit']);?></p>			
			</div>
		
			<div class="owa_metricInfobox">
				<p class="owa_metricInfoboxLabel">New Visitors</p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo $summary_stats['new_visitor'];?></p>
				<p><?php echo $this->displaySparkline('newVisitorsTrend', $site_trend['new_visitor']);?></p>	
			</div>
		
			<div class="owa_metricInfobox">
				<p class="owa_metricInfoboxLabel">Repeat Visitors</p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo $summary_stats['repeat_visitor'];?></p>
				<p><?php echo $this->displaySparkline('repeatVisitorsTrend', $site_trend['repeat_visitor']);?></p>
			</div>
	
			<div class="owa_metricInfobox">
				<p class="owa_metricInfoboxLabel">Unique Visitors</p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo $summary_stats['unique_visitors'];?></p>
				<p><?php echo $this->displaySparkline('uniqueVisitorsTrend', $site_trend['unique_visitors']);?></p>
			</div>
			
			<?php //echo $this->displayMetricInfobox('base.pageViewsCount', $this->get('period_obj'), array('constraints' => array('site_id' => $site_id)));?>
		</td>
	</tr>
</table>
<?php else:?>
	There are no statistics for this time period.
<?php endif;?>

