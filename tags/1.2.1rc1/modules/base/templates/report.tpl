<SCRIPT>


OWA.items['<?php echo $dom_id;?>'] = new OWA.report();
OWA.items['<?php echo $dom_id;?>'].dom_id = "<?php echo $dom_id;?>";
OWA.items['<?php echo $dom_id;?>'].page_num = "<?php echo $pagination['page_num'];?>1";
OWA.items['<?php echo $dom_id;?>'].max_page_num = "<?php echo $pagination['max_page_num'];?>";
OWA.items['<?php echo $dom_id;?>'].max_page_num = "<?php echo $pagination['more_pages'];?>";
<? //foreach ($params as $k => $v): ?>
//OWA.items['<?php echo $dom_id;?>'].properties.<?php echo $k;?> = "<?php echo $v;?>";
<? //endforeach;?>
OWA.items['<?php echo $dom_id;?>'].properties = <?php echo $this->makeJson($params);?>;
</SCRIPT>

<div id="<?php echo $dom_id;?>" class="owa_reportContainer">
	

<table width="100%">
	<TR>
		<TD valign="top" class="owa_reportLeftNavColumn">
			<div id="owa_report-filters"><?php include('filter_site.tpl');?></div>
			<div id="owa_reportNavPanel">
				<?php //$this->makeTwoLevelNav($top_level_report_nav);?>
				<?php echo $this->makeNavigationMenu($top_level_report_nav);?>
			</div>
			
		</TD>
		<TD valign="top" width="*">
		
						
			
			<table id="report_header" cellpadding="0" cellspacing="0">
				<TR>
					<TD valign="top" class="report_headline"><?php echo $title;?></TD>
					<TD class="owa_reportPeriod"><?php include('filter_period.tpl');?></TD>			
				</TR>
			</table>		
			
			<?php echo $subview;?>
		
		</TD>
	</TR>
</table>
		
	
	
</div>
