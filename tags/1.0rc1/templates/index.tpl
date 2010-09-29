<style>
#recent_visitors {width: 700px;}
#top_pages {width: 450px;}
#top_visitors {width: 250px;}
#summary_stats {width: px;}
#core_metrics {width: 600px;}
</style>



        <h2><?=$headline;?></h2>
		
		<fieldset id="trends" class="options">
			<legend>Trends</legend>
			
			<table>
				<!--<TR>
					<TH>Page Views & Visits</TH>
				</TR>-->
				<TR>
					<TD>
						<img src="<?=$this->config['action_url'];?>?owa_action=graph&name=pv_visits&type=bar_line&period=last_thirty_days&site_id=<?=$params['site_id'];?>" />				
					</TD>
				</TR>
			</table>
		</fieldset>
		
		<div id="summary_stats">
			<fieldset class="options">
				<legend>Summary for <?=$period_label;?></legend>
				<table>
					<TR>
						<Th>Quick Stats</Th>
						<th>New Vs. Repeat Users for <?=$period_label;?></th>
						<TH>Visitors By Source for <?=$period_label;?></TH>
					</TR>
					<TR>
						<TD valign="top">
							<?=$summary_stats_table;?>		
						</TD>
						<TD>
							<img src="<?=$this->config['action_url']?>?owa_action=graph&name=visitors_pie&type=pie&period=<?=$period;?>&site_id=<?=$params['site_id'];?>" />
						</TD>
						<TD>
							<img src="<?=$this->config['action_url']?>?owa_action=graph&name=source_pie&type=pie&period=<?=$period;?>&site_id=<?=$params['site_id'];?>" />
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