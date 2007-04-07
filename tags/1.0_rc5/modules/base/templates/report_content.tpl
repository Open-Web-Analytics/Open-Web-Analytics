<h2><?=$headline;?>: <?=$period_label;?><?=$date_label;?></h2>

	<table width="100%">
		<TR>
			<TD valign="top">
				<fieldset>
					<legend>Most Popular Web Pages</legend>
					<? include('report_top_pages.tpl');?>
				</fieldset>
			</TD>
			<TD valign="top">
				<fieldset class="graph">
					<legend>Pages Types</legend>
					<img src="<?=$this->graphLink(array('view' => 'base.graphPageTypes'), true); ?>">
			</fieldset>
			</TD>
		</TR>
	</Table>

	<table width="100%">
		<TR>
			<TD valign="top">
				<fieldset>
					<legend>Top Entry pages</legend>
					<? include('report_top_entry_pages.tpl');?>
				</fieldset>
			</TD>
			<TD valign="top">
				<fieldset>
					<legend>Top Exit pages</legend>
					<? include('report_top_exit_pages.tpl');?>
				</fieldset>
			</TD>
		</TR>
	</table>	

</fieldset>
