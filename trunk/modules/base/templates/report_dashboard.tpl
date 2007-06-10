<style>
#summary_stats {width:px;}
#trend {width:; padding:10px;}
#core_metrics {width:px;float:;}
#visitor_types {width:350px; padding:10px; float:;}
#visitor_sources {width:350px; padding:10px; float:;}
#recent_visitors {width:px;}
#top_pages {width:; }
#top_referers {width:px; margin-left: px}
#top_visitors {width:; margin-left: px}
#top_browser_types{width:; margin-left: px}
#recent_visitors{width:; margin-left: px}
</style>

<? include('report_header.tpl');?>

<table width="100%">
	<TR>
		<TD valign="top" id="trend_graph">
		
			<img src="<?=$this->graphLink(array('view' => 'base.graphDashboardTrend', 'period' => 'last_thirty_days', 'site_id' => $params['site_id'])); ?>">
		
		</TD>	
	</TR>
	<TR>
		<TD valign="top">
			<? include ('report_dashboard_summary_stats.tpl');?>		
		</TD>
	</TR>
</table>

<table>
	<TR>
		<TD valign="top">	

			<fieldset id="top_pages">
				<legend>Top Pages</legend>
				<? include ('report_top_pages.tpl');?>
			</fieldset>
			
			<fieldset id="visitor_sources">	
				<legend>Visitor Sources</legend>
				<img src="<?=$this->graphLink(array('view' => 'base.graphVisitorSources'), true); ?>">
			</fieldset>
			
			<fieldset id="visitor_types">	
				<legend>Visitor Types</legend>
				<img src="<?=$this->graphLink(array('view' => 'base.graphVisitorTypes'), true); ?>">
			</fieldset>

			
	
		</TD>
		
		<TD valign="top">
		
			<fieldset id="core_metrics">
				<legend>Core Metrics</legend>
				<? include ('report_dashboard_core_metrics.tpl');?>
			</fieldset>
			
			<fieldset id="top_referers">
				<legend>Top Referering Web Pages</legend>
				<? include ('report_top_referers.tpl');?>
			</fieldset>
				
			<fieldset id="recent_visitors">
				<legend>Recent Visitors</legend>
				<? include ('report_latest_visits.tpl');?>
			</fieldset>
			
		</TD>
	</TR>
</table>


