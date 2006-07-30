<h2><?=$headline;?></h2>

<fieldset class="options">

	<legend>Entry & Exit Pages</legend>
	
	<table width="100%">
		<TR>
			<TH>Top Entry pages</TH>
			<TH>Top Exit pages</TH>
		</TR>
		<TR>
			<TD valign="top"><?=$entry_pages;?></TD>
			<TD valign="top"><?=$exit_pages;?></TD>
		</TR>
	</table>	
	
</fieldset>

<fieldset class="options">

	<legend>Most Popular Web Pages</legend>

	<table>
		<TR>
			<TH>Top Pages</TH>
			<TH>Requests by Page Type</TH>
		</TR>
		<TR>
			<TD valign="top">
				<? include('top_pages.tpl');?>
			</TD>
			<TD valign="top">
			<img src="<?=$this->makeGraphLink('page_types');?>">
			</TD>
		</TR>
		<TR>
			<TD></TD>
		
		</TR>
	</Table>

</fieldset>
