<?php if ($visitors_age):?>
<table class="tablesorter">
	<thead>
		<TR>
			<th>Date of First Visit</th>
			<th>Visitors</th>
		</TR>
	</thead>
	<tbody>
	
	<?php foreach ($visitors_age as $row):?>
		<TR>
			<TD><?=$this->daysAgo(mktime(23, 59, 59,$row['first_session_month'], $row['first_session_day'], $row['first_session_year']) );?> (<?=$this->get_month_label($row['first_session_month']);?> <?=$row['first_session_day'];?> <?=$row['first_session_year'];?>)</TD>
			<TD>
				<a href="<?=$this->makeLink(array('do' => 'base.reportVisitorsRoster','year2' => $row['first_session_year'], 'month2' => $row['first_session_month'], 'day2' => $row['first_session_day'], 'period' => $params['period']));?>">
				<?=$row['count'];?></a>
			</TD>
		</TR>
	<?php endforeach;?>
	</tbody>
</table>
<?php else:?>
<div class="no_data_msg">There are no visitors for this time period</div>
<?php endif;?>