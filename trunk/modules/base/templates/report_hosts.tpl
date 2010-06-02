<div class="owa_reportSectionHeader">There were <?php echo $summary_stats['unique_visitors'];?> unique visitors of this web site.</div>


	


<div class="owa_reportSectionContent">
<div class="owa_reportSectionHeader">Top Hosts</div>

	<table>
		<TR>
			<TD valign="top">
				<div id="hosts-pie" style="min-width:250px;"></div>
				<script>
				//OWA.setSetting('debug', true);
				var hpurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																'metrics' => 'pageViews,visits,bounces', 
																'dimensions' => 'hostName', 
																'sort' => 'visits-',
																'format' => 'json',
																'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))),true);?>';
																  
				hp = new OWA.resultSetExplorer('hosts-pie');
				hp.options.pieChart.dimension = 'hostName';
				hp.options.pieChart.metric = 'visits';
				hp.setView('pie');
				hp.load(hpurl);
				
				</script>
			</TD>
			
			<TD valign="top">
				<div id="top-hosts" style="min-width:550px;"></div>
				<script>
				//OWA.setSetting('debug', true);
				var thurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																'metrics' => 'pageViews,visits,bounces', 
																'dimensions' => 'hostName', 
																'sort' => 'visits-',
																'format' => 'json',
																'resultsPerPage' => 30,
																'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))),true);?>';
																  
				th = new OWA.resultSetExplorer('top-hosts');
				th.options.grid.showRowNumbers = false;
				th.setView('grid');
				th.load(thurl);
				</script>
			</TD>
		</TR>
	</table>

	


	

</div>
