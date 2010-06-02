
<div class="owa_reportSectionContent">
	<div class="owa_reportSectionHeader">Trends</div>	
	<div style="min-width:500px;" id="actionsTrend"></div>
	<script>
	
		var trendurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
														  'metrics' => 'actions', 
														  'dimensions' => 'date', 
														  'sort' => 'date', 
														  'format' => 'json'), true);?>';
														  
		var trend = new OWA.resultSetExplorer('actionsTrend');
		trend.asyncQueue.push(['makeAreaChart', [{x: 'date', y:'actions'}]]);
		trend.load(trendurl);
	</script>
	
	<table cellpadding="0" cellspacing="0" width="100%">
	<tr>
		<td valign="top">
		<?php foreach($aggregates->aggregates as $row):?>
			<div class="owa_metricInfobox">
				<p class="owa_metricInfoboxLabel"><?php echo $row['label'];?></p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo $row['value'];?></p>	
			</div>
		<?php endforeach;?>
		</td>
	</tr>
</table>

</div>


<table width="100%">
	<TR>
		<TD valign="top" style="width:50%;">
			<div class="owa_reportSectionContent">
				<div class="section_header">Actions by Name</div>
				<div style="min-width:250px;" id="actionsByNameExplorer"></div>
				<script>
				
				var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																  'metrics' => 'actions', 
																  'dimensions' => 'actionName', 
																  'sort' => 'actions-', 
																  'resultsPerPage' => 5,
																  'format' => 'json'), true);?>';
																  
				rsh = new OWA.resultSetExplorer('actionsByNameExplorer');
				var link = '<?php echo $this->makeLink(array('do' => 'base.reportActionDetail', 'actionName' => '%s'), true);?>';
				rsh.addLinkToColumn('actionName', link, ['actionName']);
				rsh.asyncQueue.push(['refreshGrid']);
				rsh.load(aurl, 'grid');
				</script>
			</div>
		</TD>
		
		<TD valign="top" style="width:50%;">
			<div class="owa_reportSectionContent">
				<div class="section_header">Actions By Group</div>
				<div style="min-width:300px;" id="actionsByGroupExplorer"></div>
				<script>
				var url = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
															  'metrics' => 'actions', 
															  'dimensions' => 'actionGroup', 
															  'sort' => 'actions-', 
															  'resultsPerPage' => 5,
															  'format' => 'json'), true);?>';
															  
				rshre = new OWA.resultSetExplorer('actionsByGroupExplorer');
				rshre.asyncQueue.push(['refreshGrid']);
				rshre.load(url);
				</script>
			</div>
		</TD>
	</TR>
</table>

