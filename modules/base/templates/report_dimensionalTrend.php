<div class="owa_reportSectionContent">
	
	<div id="trend-chart"></div>

	
	<div id="trend-title" class="owa_reportHeadline"></div>	
	<div id="trend-metrics" style="height:auto;<?php if($pie) {echo 'float:right';}?>"></div>
	<?php if($pie): ?>	
	<div id="pie" style="min-width:300px;"></div>
	<script>
	var hpurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
														'metrics' => 'pageViews,visits,bounces', 
														'dimensions' => 'hostName', 
														'sort' => 'visits-',
														'format' => 'json',
														'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))),true);?>';
														  
	hp = new OWA.resultSetExplorer('pie');
	hp.options.pieChart.dimension = '<?php echo $dimensions;?>';
	hp.options.pieChart.metric = 'visits';
	hp.setView('pie');
	hp.load(hpurl);
				
	</script>
	<?php endif; ?>	
		

	
	<div style="clear:both;"></div>
	<script>
		
		var trendurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																	'metrics' => $metrics, 
																	'dimensions' => 'date', 
																	'sort' => 'date',
																	'format' => 'json',
																	'constraints' => $constraints
																	),true);?>';
																	  
		var trend = new OWA.resultSetExplorer('trend-chart');
		trend.options.sparkline.metric = 'visits';
		<?php if ($trendTitle):?>
		trend.asyncQueue.push(['renderTemplate', '<?php echo $trendTitle;?>', {d: trend}, 'replace', 'trend-title']);
		<?php endif;?>
		trend.asyncQueue.push(['makeAreaChart', [{x: 'date', y: '<?php echo $trendChartMetric; ?>'}], 'trend-chart']);
		trend.options.metricBoxes.width = '150px';
		trend.asyncQueue.push(['makeMetricBoxes' , 'trend-metrics']);
		trend.load(trendurl);
		
	</script>

</div>

<?php if (!$this->get('hideGrid')):?>
<div class="owa_reportSectionContent">
	
	
	
	<div id="dimension-grid"></div>
	
	<script>
		var dimurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																	'metrics' => $metrics, 
																	'dimensions' => $dimensions, 
																	'sort' => $sort,
																	'resultsPerPage' => $resultsPerPage,
																	'format' => 'json',
																	'constraints' => $constraints
																	),true);?>';
																	  
		var dim = new OWA.resultSetExplorer('dimension-grid');
		
		<?php if (!empty($dimensionLink)):?>
		var link = '<?php echo $this->makeLink($dimensionLink['template'], true);?>';
		dim.addLinkToColumn('<?php echo $dimensionLink['linkColumn'];?>', link, ['<?php echo $dimensionLink['valueColumns'];?>']);
		<?php endif; ?>
		<?php if (!empty($excludeColumns)):?>
		dim.options.grid.excludeColumns = [<?php echo $excludeColumns;?>];
		<?php endif; ?>
		dim.asyncQueue.push(['refreshGrid']);
		dim.load(dimurl);
	</script>

</div>
<?php endif;?>
<script type="text/x-jqote-template" id="metricInfobox">
 <![CDATA[
 
	<div class="owa_metricInfobox" style="width:<%= this.width %>;">
	<p class="owa_metricInfoboxLabel"><%= this.label %></p>
	<p class="owa_metricInfoboxLargeNumber"><%= this.formatted_value %></p>
	<p id='<%= this.dom_id %>-sparkline'></p>
	</div>

]]>
</script>
