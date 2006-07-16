<style>

#recent_visitors {width: 700px;}
#top_pages {width: 450px;}
#top_visitors {width: 250px;}
#summary_stats {width: px;}
#core_metrics {width: 600px;}
th {padding:6px 6px 6px 6px;}
td {padding: 2px 6px 2px 6px;}
</style>


        <h2><?=$headline;?><?=$date_label;?></h2>
		
		
		<div id="summary_stats">
			<fieldset class="options">
				<legend>Quick Stats</legend>
				<table>
					<TR>
						<Th></Th>
						<th>New Vs. Repeat Users</th>
						<TH>Visitors By Source</TH>
					</TR>
					<TR>
						<TD valign="top">
							<?=$summary_stats_table;?>		
						</TD>
						<TD>
							<img src="<?=$this->config['action_url']?>?owa_action=graph&name=visitors_pie&type=pie&year=<?=$params['year'];?>&month=<?=$params['month'];?>&day=<?=$params['day'];?>&site_id=<?=$params['site_id'];?>" />
						</TD>
						<TD>
							<img src="<?=$this->config['action_url']?>?owa_action=graph&name=source_pie&type=pie&year=<?=$params['year'];?>&month=<?=$params['month'];?>&day=<?=$params['day'];?>&site_id=<?=$params['site_id'];?>" />
						</TD>
					</TR>
				
				</table>	
			</fieldset>
		</div>	
		
		
		<div id="core_metrics">
			<fieldset  class="options">
				<legend>Core Metrics</legend>
				<?=$core_metrics_table;?>
			</fieldset>
		</div>
		
		<div id="recent_visitors">
			<fieldset class="options">
				<legend>Recent Visitors</legend>
				<?=$visit_data;?>
			</fieldset>
		</div>
		
		<div id="top_pages">
			<fieldset class="options">
				<legend>Top Pages</legend>
				<?=$top_pages_table;?>
			</fieldset>
		</div>
		
		<div id="top_referers">
			<fieldset class="options">
				<legend>Top Referering Web Pages</legend>
				<?=$top_referers_table;?>	
			</fieldset>
		</div>
		
		<div id="top_visitors">
			<fieldset class="options">
				<legend>Top Visitors</legend>
				<?=$top_visitors_table;?>
			</fieldset>
		</div>
		