<?php if (!empty($summary_stats)):?>

<table  id="" cellpadding="0" cellspacing="0" width="100%">
	<TR>	
		<TD valign="top">
			<DIV>
				<?=$this->getSparkline('base.dashCoreByDay', 'sessions', 'last_thirty_days', 25, 100);?>
				<span class="large_number"><?=$summary_stats['sessions'];?></span> 
				<span class="inline_h2">Visits</span>
			</DIV>
			
			<DIV>
				<?=$this->getSparkline('base.dashCoreByDay', 'pages_per_visit', 'last_thirty_days', 25, 100);?>			
				<span class="large_number"><?=round($summary_stats['pages_per_visit'],1);?></span> 
				<span class="inline_h2">Pages/Visit</span>				
			</DIV>
		
			<DIV>
				<?=$this->getSparkline('base.dashCoreByDay', 'new_visitor', 'last_thirty_days', 25, 100);?>
				<span class="large_number"><?=$summary_stats['new_visitor'];?></span> 
				<span class="inline_h2">New Visitors</span> 
			</DIV>
		</TD>
		<td valign="top">
			<DIV>
				<?=$this->getSparkline('base.dashCoreByDay', 'repeat_visitor', 'last_thirty_days', 25, 100);?>
				<span class="large_number"><?=$summary_stats['repeat_visitor'];?></span>
				<span class="inline_h2">Repeat Visitors</span> 
			</DIV>
	
			<DIV>
				<?=$this->getSparkline('base.dashCoreByDay', 'unique_visitors', 'last_thirty_days', 25, 100);?>
				<span class="inline_h2"><span class="large_number"><?=$summary_stats['unique_visitors'];?></span> 
				Unique Visitors</span>
			</DIV>
		</td>
	</TR>
</table>
<?php else:?>
	There are no statistics for this time period.
<?php endif;?>

