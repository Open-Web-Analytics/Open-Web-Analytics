<SCRIPT>


OWA.items['<?php echo $dom_id;?>'] = new OWA.report();
OWA.items['<?php echo $dom_id;?>'].dom_id = "<?php echo $dom_id;?>";
OWA.items['<?php echo $dom_id;?>'].page_num = "<?php echo $pagination['page_num'];?>1";
OWA.items['<?php echo $dom_id;?>'].max_page_num = "<?php echo $pagination['max_page_num'];?>";
OWA.items['<?php echo $dom_id;?>'].max_page_num = "<?php echo $pagination['more_pages'];?>";
<?php //foreach ($params as $k => $v): ?>
//OWA.items['<?php echo $dom_id;?>'].properties.<?php echo $k;?> = "<?php echo $v;?>";
<?php //endforeach;?>
OWA.items['<?php echo $dom_id;?>'].properties = <?php echo $this->makeJson($params);?>;
</SCRIPT>

<div id="<?php echo $dom_id;?>" class="owa_reportContainer">

	<table width="100%" cellpadding="0" cellspacing="0">
		<TR>
			<TD colspan="2">
				<div class="reportSectionContainer">
				<?php include('filter_site.tpl');?>
				</div>
			</TD>
		</TR>
		<TR>
			<TD valign="top" class="owa_reportLeftNavColumn">
				<div class="reportSectionContainer">
					<div id="owa_reportNavPanel">
						<?php echo $this->makeNavigationMenu($top_level_report_nav);?>
					</div>
				</div>			
			</TD>
			<TD valign="top" width="*">
				<div class="reportSectionContainer">
					<div class="owa_reportPeriod" style="float:right;"><?php include('filter_period.tpl');?></div>	
					<div class="owa_reportTitle"><?php echo $title;?><span class="titleSuffix"><?php echo $this->get('titleSuffix');?></span></div>
					
					<div class="clear"></div>
					<?php echo $subview;?>
				
				</div>
			</TD>
		</TR>
	</table>	
	
</div>
