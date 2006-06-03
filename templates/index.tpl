<style>

th {text-align:left;}
td {padding:2px;}
.inline_h2 {font-size:18px;}
.visitor_info_box {
	  width:40px;
	  height:40px;
	  text-align:center;
  }
  
  .comments_info_box {
		padding:4px 4px 4px 4px;
	border:solid 0px #999999;
	margin:0px 2px 2px 2px;
	width:40px;
	height:40px;
	background-image: url('<?=$this->config['images_url'];?>/comment_background.jpg');
	background-repeat: no-repeat;
	text-align:center;
  }
  
  .date_box {
  
  	padding:4px;
	border:solid 1px #999999;
	margin:2px;
  }
  
   .pages_box {
  
  	padding:4px 4px 4px 4px;
	border:solid 2px #999999;
	margin:0px 2px 2px 2px;
	background-color:;
	color:;
  }
  
  .large_number {
  	font-size:24px;
  
  }
  
  .info_text {
  
  color:#999999;
  font-size:12px;
 /* font-family:Arial, Helvetica, sans-serif; */
  
  }
  
 .h_label {
  
  color:;
  font-size:14px;
  font-weight:bold;
 /* font-family:Arial, Helvetica, sans-serif; */
  
  }

.centered_buttons {margin-left:auto;margin-right:auto;}
#recent_visitors {width: 700px;}
#top_pages {width: 450px;}
#top_visitors {width: 250px;}
#summary_stats {width: px;}
#core_metrics {width: 600px;}
th {padding:6px 6px 6px 6px;}
td {padding: 2px 6px 2px 6px;}
.snippet_text {color:#999999;font-size:12px;}
.snippet_text a {color:#999999;}
.visit_box_stat {width:42px;}
</style>
		

        <h2><?=$headline;?></h2>
        
        <fieldset id="news" class="options">
		<legend class="options">OWA News & Updates</legend>
		<table>
		<? foreach ($news['items'] as $item => $value): ?>
		<TR>
			<TD>
				<B><?=$value['pubDate'];?>:</B>
			</TD>
			<TD>
				<a href="<?=$value['link'];?>"><span class="h_label"><?=$value['title'];?></span></a>
			</TD>
		</TR>
		<TR>
			<TD></TD>
			<TD>
			<?=$value['description'];?>
			</TD>
		</TR>
		<? endforeach;?>
		</table>
		
		</fieldset>
		
		<fieldset class="options">
			<legend>Time Periods</legend>
			<?=$periods_menu;?>	
		</fieldset>
		
		
		<fieldset id="trends" class="options">
			<legend>Trends</legend>
			
			<table>
				<!--<TR>
					<TH>Page Views & Visits</TH>
				</TR>-->
				<TR>
					<TD>
						<img src="<?=$this->config['action_url'];?>?owa_action=graph&graph=pv_visits&type=bar_line&period=last_thirty_days" />				
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
							<img src="<?=$this->config['action_url']?>?owa_action=graph&graph=visitors_pie&type=pie&period=<?=$period;?>" />
						</TD>
						<TD>
							<img src="<?=$this->config['action_url']?>?owa_action=graph&graph=source_pie&type=pie&period=<?=$period;?>" />
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
		