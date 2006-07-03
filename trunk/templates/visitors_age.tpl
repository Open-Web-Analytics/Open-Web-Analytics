<? if ($visitors_age):?>
<table width="100%">
	<TR>
		<TH>Month of First Visit</TH>
		<TH>Visitors</TH>
	</TR>
	<? foreach ($visitors_age as $row):?>
	<TR>
		<TD><?=$this->get_month_label($row['first_session_month']);?></TD>
		<TD><?=$row['count'];?></TD>
	</TR>
	<? endforeach;?>
</table>
<?else:?>
<div>There are no visitors for this time period</div>
<?endif;?>