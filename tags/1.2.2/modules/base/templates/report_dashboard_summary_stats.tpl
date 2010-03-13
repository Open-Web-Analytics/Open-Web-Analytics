<?php if (!empty($summary_stats)):?>

<table  id="" cellpadding="0" cellspacing="0" width="100%">
	<tr>	
		<td valign="top">
			<div class="owa_metricInfobox">
				<span class="large_number"><?php echo $summary_stats['sessions'];?></span> 
				<span class="inline_h3">Visits</span><BR>
				<?php echo $this->displaySparkline('sessionsTrend', $site_trend['sessions']);?>
			</div>
			
			<div class="owa_metricInfobox">	
				<span class="large_number"><?php echo round($summary_stats['pages_per_visit'],1);?></span> 
				<span class="inline_h3">Pages/Visit</span><BR>				
				<?php echo $this->displaySparkline('pagePerVisitTrend', $site_trend['pages_per_visit']);?>			
			</div>
		
			<div class="owa_metricInfobox">
				<span class="large_number"><?php echo $summary_stats['new_visitor'];?></span> 
				<span class="inline_h3">New Visitors</span><BR>
				<?php echo $this->displaySparkline('newVisitorsTrend', $site_trend['new_visitor']);?>	
 
			</div>
		
			<div class="owa_metricInfobox">
				<span class="large_number"><?php echo $summary_stats['repeat_visitor'];?></span>
				<span class="inline_h3">Repeat Visitors</span><BR>
				<?php echo $this->displaySparkline('repeatVisitorsTrend', $site_trend['repeat_visitor']);?>	
			</div>
	
			<div class="owa_metricInfobox">
				<span class="inline_h3"><span class="large_number"><?php echo $summary_stats['unique_visitors'];?></span> 
				Unique Visitors</span><BR>
				<?php echo $this->displaySparkline('uniqueVisitorsTrend', $site_trend['unique_visitors']);?>	
			</div>
		</td>
	</tr>
</table>
<?php else:?>
	There are no statistics for this time period.
<?php endif;?>

