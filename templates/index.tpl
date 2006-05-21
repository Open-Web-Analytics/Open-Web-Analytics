<style>

#recent_visitors {width: 600px;}
#top_pages {width: 450px;}
</style>


        <h2><?=$headline;?></h2>
		
		<fieldset class="options">
		<legend>Time Periods</legend>
		
		<form action="<?php $_SERVER['PHP_SELF'] ?>" method="POST">
		<SELECT name="period">
			<OPTION VALUE="today" <?php if ($period == 'today'): echo 'selected'; endif; ?>>Today</OPTION>
			<OPTION VALUE="yesterday" <?php if ($period == 'yesterday'): echo 'selected'; endif; ?>>Yesterday</OPTION>
			<OPTION VALUE="this_week" <?php if ($period == 'this_week'): echo 'selected'; endif; ?>>This Week</OPTION>
			<OPTION VALUE="last_seven_days" <?php if ($period == 'last_seven_days'): echo 'selected'; endif; ?>>Last Seven Days</OPTION>
			<OPTION VALUE="this_month" <?php if ($period == 'this_month'): echo 'selected'; endif;?>>This Month</OPTION>
			<OPTION VALUE="this_year" <?php if ($period == 'this_year'): echo 'selected'; endif;?>>This Year</OPTION>
		</SELECT>
		<INPUT TYPE=SUBMIT VALUE="Go">
		</FORM>
		
		</fieldset>
		
		
		<fieldset id="graphs" class="options">
		<legend>Graphs</legend>
		
		<table>
			<TR>
				<th>Page Views</Th>
				<th>New Vs. Repeat Users for <?=$period_label;?></th>
				<TH>Visitors By Source for <?=$period_label;?></TH>
			</TR>
			<TR>
				<TD><img src="<?=$this->config['action_url'];?>?owa_action=graph&graph=page_views&type=bar&period=<? 
		if ($period == 'today' || 'yesterday'): 
		
			echo 'last_seven_days'; 
		   
		else: 
		    
			echo $period; 
				
		endif; 
		?>" /></TD>
				<TD>	<img src="<?=$this->config['action_url']?>?owa_action=graph&graph=visitors_pie&type=pie&period=<?=$period;?>" /></TD>
				<TD><img src="<?=$this->config['action_url']?>?owa_action=graph&graph=source_pie&type=pie&period=<?=$period;?>" /></TD>
			</TR>
		
		</table>
		
		
		
		
			
								
		<BR />
		
		</fieldset>
			
		
		<fieldset id="graphs" class="options">
		<legend>Quick Stats for <?=$period_label;?></legend>
		
		<div>Unique Visitors: <?=$dash_counts['unique_visitors'];?></div>
		<div>New Visitors: <?=$dash_counts['new_visitor'];?></div>
		<div>Sessions: <?=$dash_counts['sessions'];?></div>
		<div>Page Views: <?=$dash_counts['page_views'];?></div>
		<div>Visitors from Feeds: <?=$from_feed['source_count'];?></div>
		
		</fieldset>
		
		<fieldset id="graphs" class="options">
		<legend>Metrics</legend>
		
		<table width="100%">
			<tr>
				
				
				<? if ($period == 'this_year'): ?>
				
				<th scope="col">Month</th>
				
				<? else: ?>
				
				<th scope="col">Month</th>
				<th scope="col">Day</th>
				<th scope="col">Year</th>
				
				<? endif; ?>
				
				<th scope="col">Unique Visitors</th>
				<th scope="col">Sessions</th>
				<th scope="col">Page Views</th>
			</tr>
			
			<?php foreach($rows as $row): ?>
			
			<TR>
			
				<? if ($period == 'this_year'): ?>
				
				<TD><?=$this->get_month_label($row['month']);?></TD>
				
				<? else: ?>
				
				<TD><?=$this->get_month_label($row['month']);?></TD>
				<TD><?=$row['day'];?></TD>
				<TD><?=$row['year'];?></TD>
				
				<? endif; ?>
				
				<TD><?=$row['unique_visitors'];?></TD>
				<TD><?=$row['sessions'];?></TD>
				<TD><?=$row['page_views'];?></TD>
			</TR>
			
   <?php endforeach; ?>
		</table>
		
		</fieldset>
		
		<fieldset id="recent_visitors" class="options">
			<legend>Recent Visitors</legend>
			<?=$visit_data;?>
		</fieldset>
		
		<fieldset id="top_pages" class="options">
			<legend>Top Pages</legend>
		
			<?=$top_pages_table;?>
		</fieldset>
		
		
		<fieldset class="options">
			<legend>Top Referers</legend>
			<? if (!empty($top_referers)):?>
			<table width="100%">
				<tr>
					<th scope="col">Source</th>
					<th scope="col">Visits</th>
					<th scope="col">Query Terms</th>
					<th scope="col">Is Search Engine</th>
				</tr>
				
				<?php foreach($top_referers as $referer): ?>
				
				<TR>
					<TD><a href="<?=$referer['url'];?>"><? if (!empty($referer['page_title'])):?><?=$referer['page_title'];?><?else:?><?=$this->truncate($referer['url'], 100, '...');?><? endif;?></a></TD>
					<TD><?=$referer['count']?></TD>
					<TD><?=urldecode($referer['query_terms'])?></TD>
					<TD><? if ($referer['is_searchengine'] == true):?>yes<?else:?>no<?endif;?></TD>
				</TR>
				
			   <?php endforeach; ?>
				</table>
			<?else:?>
				There are no referering sources for this time period.
			<?endif;?>
				
		</fieldset>
		
		<fieldset class="options">
			<legend>Top Visitors</legend>
		
			<table width="">
				<tr>
					<th scope="col">Visitor</th>
					<th scope="col">Visits</th>
				</tr>
			
				<?php foreach($top_visitors as $vis): ?>
				
				<TR>
					<TD><a href="<?=$this->config['reporting_url'];?>/visitor_report.php&<?=$this->config['ns'].$this->config['visitor_param']?>=<?=$vis['vis_id'] ?>"><span class=""><? if (!empty($vis['user_name'])):?><?=$vis['user_name'];?><?elseif (!empty($vis['user_email'])):?><?=$vis['user_email'];?><? else: ?><?=$vis['vis_id'];?><? endif; ?></span></a></TD>
					<TD><?=$vis['count']?></TD>
				</TR>
				
			    <?php endforeach; ?>
			</table>	
		</fieldset>
		
		