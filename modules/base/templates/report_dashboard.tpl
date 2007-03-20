<style>
#summary_stats {width:;}
#trend {width:; padding:10px;}
#core_metrics {width:px;float:;}
#visitor_types {width:350px; padding:10px; float:;}
#visitor_sources {width:350px; padding:10px; float:;}
#recent_visitors {width:px;}
#top_pages {width:; }
#top_referers {width:px; margin-left: px}
#top_visitors {width:; margin-left: px}
#top_browser_types{width:; margin-left: px}
</style>


<h2><?=$headline;?>: <?=$period_label;?><?=$date_label;?></h2>

<table>
	<TR>
		<TD valign="top" width="390">
			<fieldset id="summary_stats">
				<legend>Summary</legend>
				<? include ('report_dashboard_summary_stats.tpl');?>		
			</fieldset>
			
			<fieldset id="visitor_types">	
				<legend>Visitor Types</legend>
				<img src="<?=$this->graphLink(array('view' => 'base.graphVisitorTypes'), true); ?>">
			</fieldset>
			
			<fieldset id="visitor_sources">	
				<legend>Visitor Sources</legend>
				<img src="<?=$this->graphLink(array('view' => 'base.graphVisitorSources'), true); ?>">
			</fieldset>
			
			<div id="top_pages">
				<fieldset>
					<legend>Top Pages</legend>
					<? include ('report_top_pages.tpl');?>
				</fieldset>
			</div>
					
			<div id="top_referers">
				<fieldset>
					<legend>Top Referering Web Pages</legend>
					<? include ('report_top_referers.tpl');?>
				</fieldset>
			</div>
					
			<div id="top_visitors">
				<fieldset>
					<legend>Top Visitors</legend>
					<? include ('report_top_visitors.tpl');?>
				</fieldset>
			</div>
			
			<div id="top_browser_types">
				<fieldset>
					<legend>Browser Types</legend>
					<? include ('report_browser_types.tpl');?>
				</fieldset>
			</div>
			
		</TD>
		
		<TD valign="top">
			<fieldset id="trend">
				<legend>30 Day Trend</legend>
				<img src="<?=$this->graphLink(array('view' => 'base.graphDashboardTrend', 'period' => 'last_thirty_days', 'site_id' => $params['site_id'])); ?>">
			</fieldset>
			
			<fieldset id="core_metrics">
				<legend>Core Metrics</legend>
				<? include ('report_dashboard_core_metrics.tpl');?>
			</fieldset>
			
			<div id="recent_visitors">
				<fieldset>
					<legend>Recent Visitors</legend>
					<? include ('report_latest_visits.tpl');?>
				</fieldset>
			</div>
		</TD>
	</TR>
</table>
