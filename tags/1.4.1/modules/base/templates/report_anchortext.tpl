<div class="owa_reportSectionContent">
	<div id="trend-title" class="owa_reportSectionHeader"></div>
	<div id="trend-chart"></div><BR>
	<div id="trend-metrics" style="height:auto;width:auto;"></div>
	<div style="clear:both;"></div>
	<script>
		
		var trendurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																	'metrics' => $metrics, 
																	'dimensions' => 'date', 
																	'sort' => 'date',
																	'format' => 'json',
																	),true);?>';
																	  
		var trend = new OWA.resultSetExplorer('trend-chart');
		trend.options.sparkline.metric = 'visits';
		<?php if ($trendTitle):?>
		trend.asyncQueue.push(['renderTemplate', '<?php echo $trendTitle;?>', {d: trend}, 'replace', 'trend-title']);
		<?php endif;?>
		trend.asyncQueue.push(['makeAreaChart', [{x: 'date', y: '<?php echo $trendChartMetric; ?>'}], 'trend-chart']);
		trend.asyncQueue.push(['makeMetricBoxes' , 'trend-metrics']);
		trend.load(trendurl);
		
	</script>

</div>

<div class="owa_reportSectionContent">
	<div class="owa_reportSectionHeader">Top Inbound Link Text</div>
	<div id="dimension-grid"></div>
	
	<script>
		var dimurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																	'metrics' => $metrics, 
																	'dimensions' => $dimensions, 
																	'sort' => $sort,
																	'resultsPerPage' => $resultsPerPage,
																	'format' => 'json',
																	),true);?>';
																	  
		var dim = new OWA.resultSetExplorer('dimension-grid');
		
		<?php if (!empty($dimensionLink)):?>
		var link = '<?php echo $this->makeLink($dimensionLink['template'], true);?>';
		dim.addLinkToColumn('<?php echo $dimensionLink['linkColumn'];?>', link, ['<?php echo $dimensionLink['valueColumns'];?>']);
		<?php endif; ?>
		dim.asyncQueue.push(['refreshGrid']);
		dim.load(dimurl);
	</script>
	
</div>

<script type="text/x-jqote-template" id="metricInfobox">
 <![CDATA[
 
	<div class="owa_metricInfobox">
	<p class="owa_metricInfoboxLabel"><%= this.label %></p>
	<p class="owa_metricInfoboxLargeNumber"><%= this.value %></p>
	<p id='<%= this.dom_id %>-sparkline'></p>
	</div>

]]>
</script>
