<div class="owa_reportSectionContent" style="width:auto;">
<div class="owa_reportSectionHeader">Site Metrics</div>

<div id="site-trend" style="height:125px;"></div>
	<script>
	//OWA.setSetting('debug', true);
	var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
													'metrics' => 'visits', 
													'dimensions' => 'date', 
													'sort' => 'date',
													'format' => 'json',
													'period' => 'last_thirty_days',
													'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))));?>';
													  
	rsh = new OWA.resultSetExplorer('site-trend');
	rsh.options.areaChart.series.push({x:'date',y:'visits'});
	rsh.setView('areaChart');
	rsh.load(aurl);
	
	</script>
	
	<?php include ('report_dashboard_summary_stats.tpl');?>
</div>

<table style="padding:0px;">
	<TR>
		<TD style="width:20%" valign="top">
			
			
			<div class="owa_reportSectionContent">
				<div class="owa_reportSectionHeader">Visitor Types</div>
				
				
				<div id="visitor-types" style="width:200px;text-align:center;"></div>		
			
				
					
	
				
				
				<script>
					//OWA.setSetting('debug', true);
					var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																	'metrics' => 'repeatVisitors,newVisitors', 
																	'dimensions' => '', 
																	'sort' => 'visits',
																	'format' => 'json',
																	'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))),true);?>';
																	  
					var vt = new OWA.resultSetExplorer('visitor-types');
					vt.options.pieChart.metrics = ['repeatVisitors', 'newVisitors'];
					vt.setView('pie');
					vt.load(aurl);
				
				</script>
			</div>
			
			<div class="owa_reportSectionContent">
				<div class="owa_reportSectionHeader">Traffic Sources</div>
				<div id="visitor-mediums" style="width:200px;text-align:center;"></div>	
				<script>
					var vmurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																	'metrics' => 'visits', 
																	'dimensions' => 'medium', 
																	'sort' => 'visits-',
																	'format' => 'json',
																	'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))),true);?>';
																	  
					var vm = new OWA.resultSetExplorer('visitor-mediums');
					vm.options.pieChart.metric = 'visits';
					vm.options.pieChart.dimension = 'medium';
					vm.setView('pie');
					vm.load(vmurl);
				</script>
			</div>
			
		</TD>
		
		<TD style="width:60%" valign="top">
			
			<div class="owa_reportSectionContent">
				<div class="owa_reportSectionHeader">Top Content</div>
				
				<div id="top-pages" style="min-width:350px"></div>
				<script>
				//OWA.setSetting('debug', true);
				var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																'metrics' => 'pageViews', 
																'dimensions' => 'pageTitle', 
																'sort' => 'pageViews-',
																'format' => 'json',
																'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))),true);?>';
																  
				rsh = new OWA.resultSetExplorer('top-pages');
				//rsh.options.areaChart.series.push({x:'date',y:'visits'});
				rsh.setView('grid');
				rsh.load(aurl);
				
				</script>
			</div>
			
			<div class="owa_reportSectionContent">
				<div class="owa_reportSectionHeader">Top Referers</div>
				
				<div id="top-referers" style="min-width:350px"></div>
				<script>
				//OWA.setSetting('debug', true);
				var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																'metrics' => 'visits', 
																'dimensions' => 'referralPageUrl', 
																'sort' => 'visits-',
																'format' => 'json',
																'resultsPerPage' => 10,
																'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))),true);?>';
																  
				rsh = new OWA.resultSetExplorer('top-referers');
				//rsh.options.areaChart.series.push({x:'date',y:'visits'});
				rsh.setView('grid');
				rsh.load(aurl);
				
				</script>
			</div>
			
			<div class="owa_reportSectionContent">
				<div class="section_header">Latest Visits</div>
				<?php echo $this->getWidget('base.widgetLatestVisits', array('height' => '', 'width' => '', 'period' => $params['period']), false);?>
			</div>
			
		</TD>
		<TD style="width:60%" valign="top">
		
			<?php if ($actions->getDataRows()):?>
			<div class="owa_reportSectionContent" style="min-width:200px; height:;">
				<div class="section_header">Actions</div>
				
				<div id="actions-trend" style="width:200px;height:100px;"></div>
				<script>
				
				var aturl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																'metrics' => 'actions', 
																'dimensions' => 'date', 
																'sort' => 'date',
																'format' => 'json',
																'period' => 'last_seven_days',
																'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))));?>';
																  
				at = new OWA.resultSetExplorer('actions-trend');
				at.options.areaChart.series.push({x:'date',y:'actions'});
				at.setView('areaChart');
				at.load(aturl);
				
				
				</script>
				
				<table cellpadding="0" cellspacing="0" width="100%">
					<tr>
						<td valign="top">
						<?php foreach($actions->getDataRows() as $k => $row):?>
							<div class="owa_metricInfobox">
								<p class="owa_metricInfoboxLabel"><?php echo $row['actionName']['value'];?></p>
								<p class="owa_metricInfoboxLargeNumber"><?php echo $row['actions']['value'];?></p>	
							</div>
						<?php endforeach;?>
						</td>
					</tr>
				</table>
				
				
				<div class="owa_genericHorizontalList owa_moreLinks">
					<UL>
						<LI>
							<a href="">View Full Report</a>
						</LI>
					</UL>
				</div>
				<div class="clear"></div>
			</div>
			<?php endif;?>
		
			<div class="owa_reportSectionContent">
				<div class="section_header">OWA News</div>
				<?php echo $this->getWidget('base.widgetOwaNews');?>
			</div>
		</TD>
	</TR>
</table>