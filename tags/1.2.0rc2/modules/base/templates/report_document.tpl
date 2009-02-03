<div class="owa_reportSectionContent">
	<table width="100%">
		<TR>
			<TD valign="top">
				<span class="inline_h2"><?=$detail['page_title'];?></span><BR>
				<span class="infotext"><?=$detail['url'];?> (<a href="<?=$detail['url'];?>">visit page</a>)</span>
			</TD>
			<TD valign="" style="text-align:right;">
				<span class="infotext"><B>Page Type:</B></span><BR>
				<span class="inline_h3"><?=$detail['page_type'];?></span>	
			</TD>
		</TR>
	</table>
	
	
<BR>
<?=$this->displayChart('trend', $chart1_data, '100%', '135px'); ?>
</div>
<BR>
<div class="owa_reportSectionHeader">There were <?=$summary_stats['page_views'];?> page views for this document.
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
				<span class="inline_h3"><a href="<?=$this->makeLink(array('do' => 'base.reportClicks'), true);?>">Click Analysis</a></span> - See which part of the document are being clicked on.
			</P>
					
		</TD>
		</TR>
	</table>
	
	<BR><BR>
	<? //include('report_document_core_metrics.tpl');?>
</div>

<div class="owa_reportSectionHeader">Refering Web Pages</div>
<div class="owa_reportSectionContent">
	<? include('report_top_referers.tpl');?>
</div>

