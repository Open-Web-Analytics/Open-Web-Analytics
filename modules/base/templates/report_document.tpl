<div class="owa_reportSectionContent">

<?php require('item_document.php');?>
	
	
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
				<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.overlayLauncher', 'document_id' =>$document['id'], 'overlay_params' => urlencode($this->makeParamString(array('action' => 'loadHeatmap'), true, 'cookie'))));?>" target="_blank">Heatmap Overlay</a></span> (Firefox 3.5+ required)
			</P>
			
			<P>
				<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.reportDomstreams', 'document_id' => $document['id']), true);?>">Domstreams</a></span> - mouse movement recordings.
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

