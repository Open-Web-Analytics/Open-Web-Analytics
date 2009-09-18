<?php if (!empty($summary_stats)):?>

<div>
			<div class="owa_infobox">
				<span class="large_number"><?php echo $summary_stats['sessions'];?></span>
				<span class="inline_h3">Visits</span>
			</div>
			<BR>
			<div class="owa_infobox">
				<span class="large_number"><?php echo $summary_stats['unique_visitors'];?></span>
				<span class="inline_h3">Unique Visitors</span>
			</div>
			<BR>
			<div class="owa_infobox">
				<span class="large_number"><?php echo $summary_stats['new_visitor'];?></span>
				<span class="inline_h3">New Visitors</span>
			</div>
			<BR>
			<div class="owa_infobox">
				<span class="large_number"><?php echo $summary_stats['repeat_visitor'];?></span>
				<span class="inline_h3">Repeat Visitors</span>
			</div>
</div>

	
<?php else:?>
	There are no statistics for this time period.
<?php endif;?>

