<div class="owa_reportSectionContent">
	<table width="100%">
		<TR>
			<TD valign="top">
				<span class="inline_h2"><?php echo $detail['page_title'];?></span><BR>
				<span class="infotext"><?php echo $detail['url'];?> (<a href="<?php echo $detail['url'];?>">visit page</a>)</span>
			</TD>
			<TD valign="" style="text-align:right;">
				<span class="infotext"><B>Page Type:</B></span><BR>
				<span class="inline_h3"><?php echo $detail['page_type'];?></span>	
			</TD>
		</TR>
	</table>
	
	
<BR>
<?php echo $this->displayChart('trend', $chart1_data, '100%', '135px'); ?>
</div>
<BR>
<div class="owa_reportSectionHeader">There were <?php echo $summary_stats['page_views'];?> page views for this document.
</div> 
<div class="owa_reportSectionContent">
	<table>
		<TR>
			<TD width="33%" valign="top">
				<? include('report_document_summary_stats.tpl');?>
			</TD>
			
			<TD width="" valign="top">
			<div class="section_header inline_h2">More reports for this document:</div>
			<P>
				<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.reportClicks'), true);?>">Click Analysis</a></span> - See which part of the document are being clicked on.
			</P>
			<P>
				<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.overlayLauncher', 'document_id' =>$detail['id'], 'overlay_params' => urlencode($this->makeParamString(array('action' => 'loadHeatmap'), true, 'cookie'))));?>" target="_blank">Overlay</a></span>
			</P>
			
			<P>
				<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.overlayLauncher', 'url' => urlencode($detail['url']), 'overlay_params' => urlencode($this->makeParamString(array('action' => 'loadPlayer'), true, 'cookie'))));?>" target="_blank">Player</a></span>
			</P>
					
		</TD>
		</TR>
	</table>
	
	<BR><BR>
	<?php //include('report_document_core_metrics.tpl');?>
</div>

<div class="owa_reportSectionHeader">Refering Web Pages</div>
<div class="owa_reportSectionContent">
	<?php include('report_top_referers.tpl');?>
</div>

