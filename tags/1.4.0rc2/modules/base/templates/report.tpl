<SCRIPT>
OWA.items['<?php echo $dom_id;?>'] = new OWA.report();
OWA.items['<?php echo $dom_id;?>'].dom_id = "<?php echo $dom_id;?>";
OWA.items['<?php echo $dom_id;?>'].page_num = "<?php $this->out( $this->getValue( 'page_num', 'pagination' ),false );?>1";
OWA.items['<?php echo $dom_id;?>'].max_page_num = "<?php $this->out( $this->getValue( 'max_page_num', 'pagination' ), false );?>";
OWA.items['<?php echo $dom_id;?>'].max_page_num = "<?php $this->out( $this->getValue( 'more_pages', 'pagination' ), false );?>";
OWA.items['<?php echo $dom_id;?>'].properties = <?php echo $this->makeJson($params);?>;
</SCRIPT>
<div id="<?php echo $dom_id;?>" class="owa_reportContainer">

	<table width="100%" cellpadding="0" cellspacing="0">
		
		<TR>
			<TD valign="top" class="owa_reportLeftNavColumn">
				<div class="reportSectionContainer">
					<div id="owa_reportNavPanel">
						<?php echo $this->makeNavigationMenu($top_level_report_nav);?>
					</div>
				</div>			
			</TD>
			<TD valign="top" width="*">
			
				<div class="reportSectionContainer" style="margin-bottom:20px;">
				<?php include('filter_site.tpl');?>
				</div>
				
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
<script>
OWA.items['<?php echo $dom_id;?>'].showSiteFilter();
</script>

