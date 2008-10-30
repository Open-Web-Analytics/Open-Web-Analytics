<SCRIPT>


OWA.items['<?=$dom_id;?>'] = new OWA.report();
OWA.items['<?=$dom_id;?>'].dom_id = "<?=$dom_id;?>";
OWA.items['<?=$dom_id;?>'].page_num = "<?=$pagination['page_num'];?>1";
OWA.items['<?=$dom_id;?>'].max_page_num = "<?=$pagination['max_page_num'];?>";
OWA.items['<?=$dom_id;?>'].max_page_num = "<?=$pagination['more_pages'];?>";
<? foreach ($params as $k => $v): ?>
OWA.items['<?=$dom_id;?>'].properties.<?=$k;?> = "<?=$v;?>";
<? endforeach;?>

</SCRIPT>

<div id="<?=$dom_id;?>" class="owa_reportContainer">
	

<table width="100%">
	<TR>
		<TD valign="top" class="owa_reportLeftNavColumn">
			<div id="owa_report-filters"><? include('filter_site.tpl');?></div>
			<div id="owa_reportNavPanel">
				<?=$this->makeTwoLevelNav($top_level_report_nav);?>
			</div>
			
		</TD>
		<TD valign="top" width="*">
		
						
			
			<table id="report_header" cellpadding="0" cellspacing="0">
				<TR>
					<TD valign="top" class="report_headline"><?=$headline;?></TD>
					<TD class="owa_reportPeriod"><? include('filter_period.tpl');?></TD>			
				</TR>
			</table>		
			
			<?=$subview;?>
		
		</TD>
	</TR>
</table>
		
	
	
</div>
