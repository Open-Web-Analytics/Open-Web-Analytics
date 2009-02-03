<div class="owa_reportSectionHeader">There were <?=$sessions['count'];?> visits from all sources.</div> 
<div class="owa_reportSectionContent">
<table width=100%">
	<TR>
		<TD width="33%" valign="top">
			<? include('report_traffic_summary_stats.tpl');?>
		</TD>
		
		<TD width="33%" valign="top">
			<?=$this->getWidget('base.widgetVisitorSources', array('height' => '200px', 'width' => '', 'period' => $params['period']));?>
		</TD>
		
		<TD width="33%" valign="top">
			<div class="section_header inline_h2">Traffic Reports</div>
			<P>
				<span class="inline_h3"><a href="<?=$this->makeLink(array('do' => 'base.reportSearchEngines'));?>">Search Engines</a></span> - See which search engines your visitors are comming from.
			</P>
			<P>
				<span class="inline_h3"><a href="<?=$this->makeLink(array('do' => 'base.reportKeywords'));?>">Keywords</a></span> - See what keywords your visitor are using to find your web site.
			</P>
			<P>
				<span class="inline_h3"><a href="<?=$this->makeLink(array('do' => 'base.reportReferringSites'));?>">Referring Web Sites</a></span> - See which web sites are linking to your web site.
			</P>
			<P>
				<span class="inline_h3"><a href="<?=$this->makeLink(array('do' => 'base.reportAnchortext'));?>">Inbound Link Text</a></span> - See what words Referring Web Sites use to describe your web site.
			</P>
		
		</TD>
	</TR>
</table>

</div>