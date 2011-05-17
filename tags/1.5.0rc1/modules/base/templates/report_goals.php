<div class="owa_reportSectionContent">
	
	<div id="trend-chart"></div>

	
	<div id="trend-title" class="owa_reportHeadline"></div>	
	<div id="trend-metrics" style="height:auto;width:auto;<?php if(isset($pie)) {echo 'float:right';}?>"></div>
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
		trend.options.sparkline.metric = 'goalCompletionsAll';
		<?php if ($trendTitle):?>
		trend.asyncQueue.push(['renderTemplate', '<?php echo $trendTitle;?>', {d: trend}, 'replace', 'trend-title']);
		<?php endif;?>
		trend.asyncQueue.push(['makeAreaChart', [{x: 'date', y: '<?php echo $trendChartMetric; ?>'}], 'trend-chart']);
		trend.options.metricBoxes.width = '150px';
		trend.asyncQueue.push(['makeMetricBoxes' , 'trend-metrics']);
		trend.load(trendurl);
		
	</script>

</div>

<table width="100%">
	<TR>
		<TD valign="top" style="width:50%;">
			<div class="owa_reportSectionContent">
				<div class="section_header">Goal Performance</div>
				<div style="min-width:250px;" id="goalMetrics"></div>
				<?php if ($goal_metrics): ?>
				<script>
				
				var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																  'metrics' => $goal_metrics, 
																  'format' => 'json'), true);?>';
																  
				rsh = new OWA.resultSetExplorer('goalMetrics');
				rsh.asyncQueue.push(['makeMetricBoxes' , 'goalMetrics']);
				rsh.load(aurl, 'grid');
				</script>
				<?php endif;?>
			</div>
	
		</TD>
		
		<td valign="top">
			<div class="owa_reportSectionContent">
				<div class="section_header">Related Reports</div>
				<div class="relatedReports">
				<UL>
					<li>
						
						<a href="<?php echo $this->makeLink(array('do' => 'base.reportGoalFunnel'), true);?>">Conversion Funnels</a> - Goal funnel Visualization.
						
					</li>
				</UL>
				</div>
			</div>
		</td>
	</TR>
</table>

<?php require_once('js_report_templates.php');?>

