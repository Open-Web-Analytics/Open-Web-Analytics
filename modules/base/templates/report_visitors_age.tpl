<? if ($visitors_age):?>
<table width="100%">
	<TR>
		<TH>Date of First Visit</TH>
		<TH>Visitors</TH>
	</TR>
	<? foreach ($visitors_age as $row):?>
	<TR>
		<TD><?=$this->daysAgo(mktime(23, 59, 59,$row['first_session_month'], $row['first_session_day'], $row['first_session_year']) );?> (<?=$this->get_month_label($row['first_session_month']);?> <?=$row['first_session_day'];?> <?=$row['first_session_year'];?>)</TD>
		<TD>
			<a href="<?=$this->makeLink(array('do' => 'base.reportVisitorsRoster','year2' => $row['first_session_year'], 'month2' => $row['first_session_month'], 'day2' => $row['first_session_day'], 'period' => $params['period']));?>">
			<?=$row['count'];?></a>
		</TD>
	</TR>
	<? endforeach;?>
</table>
<?else:?>
<div>There are no visitors for this time period</div>
<?endif;?>