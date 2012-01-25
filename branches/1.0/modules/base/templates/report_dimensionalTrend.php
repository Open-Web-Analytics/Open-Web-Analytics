<div class="owa_reportSectionContent">
	
	<div id="trend-chart"></div>
	<div id="trend-title" class="owa_reportHeadline"></div>
	<div id="report-tabs">
		
		<?php foreach ($tabs as $k => $tab): ?>
		<div id="tab_<?php $this->out($k); ?>">
			
				<div id="<?php $this->out($k); ?>_trend-metrics" style="height:auto;width:auto;<?php if( $this->get( 'pie' ) ) {echo 'float:right';}?>"></div>
				<?php if ( $this->get('pie' ) ): ?>	
				<div id="pie" style="min-width:300px;"></div>
				<?php endif;?>
				<div class="spacer" style="clear:both; height:20px;"></div>
				<?php if (!$this->get('hideGrid')):?>
				<div id="<?php $this->out($k); ?>_dimension-grid"></div>
				<?php endif;?>
			
		</div>
		<?php endforeach; ?>
	</div>
</div>

<script type="text/javascript">
		
	// add tabs	
	
	<?php foreach ($tabs as $k => $tab): ?>
	
	// adding tab for <?php $this->out($k, false);?>
	
	
	var tab = new OWA.report.tab('tab_<?php $this->out($k, false);?>');
	tab.setLabel('<?php $this->out($tab['tab_label']);?>');	
	// create trend and aggregate data resultSetExplorer objects
	var trendurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																'metrics' => $tab['metrics'], 
																'dimensions' => 'date', 
																'sort' => 'date',
																'format' => 'json',
																'constraints' => $constraints
																),true);?>';
																  
	var trend = new OWA.resultSetExplorer('trend-chart');
	trend.setDataLoadUrl(trendurl);
	trend.options.sparkline.metric = 'visits';
	<?php if ($trendTitle):?>
	trend.asyncQueue.push(['renderTemplate', '<?php echo $trendTitle;?>', {d: trend}, 'replace', 'trend-title']);
	<?php endif;?>
	trend.asyncQueue.push(['makeAreaChart', [{x: 'date', y: '<?php if ( isset($tab['trendchartmetric'] ) ): echo $tab['trendchartmetric']; else: echo $trendChartMetric; endif; ?>'}], 'trend-chart']);
	trend.options.metricBoxes.width = '150px';
	trend.asyncQueue.push(['makeMetricBoxes' , '<?php $this->out($k, false);?>_trend-metrics']);
	// add rse to tab
	tab.addRse('trend', trend);
	// dimensonal data object
	var dimurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																'metrics' => $tab['metrics'], 
																'dimensions' => $dimensions, 
																'sort' => $tab['sort'],
																'resultsPerPage' => $resultsPerPage,
																'format' => 'json',
																'constraints' => $constraints
																),true);?>';
																  
	var dim = new OWA.resultSetExplorer('<?php $this->out($k, false);?>_dimension-grid');
	dim.setDataLoadUrl(dimurl);
	<?php if (!empty($dimensionLink)):?>
	var link = '<?php echo $this->makeLink($dimensionLink['template'], true);?>';
	var values = <?php if (is_array($dimensionLink['valueColumns'])) { 
					$values = "[";
					$i = 0;
					$count = count($dimensionLink['valueColumns']);
					foreach ($dimensionLink['valueColumns'] as $v) {
						$values .= "'$v'";
						if ($i < $count) {
							$values .= ', ';
						}
						$i++;
					}
					$values .= "]";
					echo $values; 
				} else {
					echo "['".$dimensionLink['valueColumns']."']";
				}
				?>;
	dim.addLinkToColumn('<?php echo $dimensionLink['linkColumn'];?>', link, values);
	<?php endif; ?>
	
	<?php if (isset($gridFormatters) && ! empty($gridFormatters) ):?>
	<?php foreach ($gridFormatters as $col => $formatter): ?>
	dim.options.grid.columnFormatters['<?php $this->out($col); ?>'] = <?php $this->out($formatter, false);?>;
	<?php endforeach;?>
	<?php endif;?>
	
	<?php if (!empty($excludeColumns)):?>
	dim.options.grid.excludeColumns = [<?php echo $excludeColumns;?>];
	<?php endif; ?>
	dim.asyncQueue.push(['refreshGrid']);
	// add dim object to tab
	tab.addRse('dim', dim);
	// add tab
	OWA.items['<?php echo $dom_id;?>'].addTab( tab );
	<?php endforeach;?>
	// create report tabs
	OWA.items['<?php echo $dom_id;?>'].createTabs();
	
</script>

<?php require_once('js_report_templates.php');?>