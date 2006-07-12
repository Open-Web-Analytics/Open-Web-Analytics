<? if ($visitors_age):?>
<table width="100%">
	<TR>
		<TH>Month of First Visit</TH>
		<TH>Visitors</TH>
	</TR>
	<? foreach ($visitors_age as $row):?>
	<TR>
		<TD><?=$this->get_month_label($row['first_session_month']);?> <?=$row['first_session_day'];?> <?=$row['first_session_year'];?></TD>
		<TD>
			<a href="<?=$this->make_report_link('visitors_report.php', array('owa_page' => 'visitor_list', 'year2' => $row['first_session_year'], 'month2' => $row['first_session_month'], 'period' => $params['period']));?>">
			<?=$row['count'];?></a>
		</TD>
	</TR>
	<? endforeach;?>
</table>
<?else:?>
<div>There are no visitors for this time period</div>
<?endif;?>