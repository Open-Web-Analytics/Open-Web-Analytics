<div class="owa_reportSectionContent">
	
	<div id="trend-chart"></div>

	
	<div id="trend-title" class="owa_reportHeadline"></div>	
	<div id="trend-metrics" style="height:auto;<?php if($pie) {echo 'float:right';}?>"></div>
	<?php if($pie): ?>	
	<div id="pie" style="min-width:300px;"></div>
	<script>
	
		var hpurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
														'metrics' => 'pageViews,visits,bounceRate', 
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

<?php require_once('js_report_templates.php');?>
