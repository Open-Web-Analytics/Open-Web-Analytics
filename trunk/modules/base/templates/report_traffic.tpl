<? include('report_header.tpl');?>

<P><span class="inline_h2">There were <?=$sessions['count'];?> visits from all sources.</span></p> 

<table width=100%">
	<TR>
		<TD width="50%" valign="top"><? include('report_traffic_summary_stats.tpl');?></TD>
		<TD valign="top">
			<img src="<?=$this->graphLink(array('view' => 'base.graphVisitorSources'), true); ?>">
		
			<div class="section_header inline_h2">Traffic Reports</div>
			<P><span class="inline_h3">Search Engines</span> - See which search engines your visitors arecomming from.</P>
			<P><span class="inline_h3">Keywords</span> - See what keywords your visitor are using to find your web site.</P>
			<P><span class="inline_h3">Referring Web Sites</span> - See which web sites are linking to your web site.</P>
			<P><span class="inline_h3">Inbound Link Text</span> - See what words Referring Web Sites use to describe your web site.</P>
			
		
		</TD>
	</TR>
</table>


