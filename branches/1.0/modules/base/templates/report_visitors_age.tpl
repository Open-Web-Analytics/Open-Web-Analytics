<? if ($visitors_age):?>
<table class="data_table">
	<TR>
		<TD class="col_item_label">Date of First Visit</TD>
		<TD class="col_label">Visitors</TD>
	</TR>
	<? foreach ($visitors_age as $row):?>
	<TR>
		<TD class="item_cell"><?=$this->daysAgo(mktime(23, 59, 59,$row['first_session_month'], $row['first_session_day'], $row['first_session_year']) );?> (<?=$this->get_month_label($row['first_session_month']);?> <?=$row['first_session_day'];?> <?=$row['first_session_year'];?>)</TD>
		<TD class="data_cell">
			<a href="<?=$this->makeLink(array('do' => 'base.reportVisitorsRoster','year2' => $row['first_session_year'], 'month2' => $row['first_session_month'], 'day2' => $row['first_session_day'], 'period' => $params['period']));?>">
			<?=$row['count'];?></a>
		</TD>
	</TR>
	<? endforeach;?>
</table>
<?else:?>
<div class="no_data_msg">There are no visitors for this time period</div>
<?endif;?>