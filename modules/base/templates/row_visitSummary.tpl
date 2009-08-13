<TD>
	<div class="owa_visitSummaryInfobox">
	
		<span class="h_label"><?=$row['session_month'];?>/<?=$row['session_day'];?> @ at <?=$row['session_hour'];?>:<?=$row['session_minute'];?></span> |
		<span class="info_text"><?=$row['host_host'];?><? if ($row['host_city']):?> - <?=$row['host_city'];?>, <?=$row['host_country'];?><? endif;?></span> 
		<?=$this->choose_browser_icon($row['ua_browser_type']);?>
				
		<table cellpadding="0" cellspacing="0" width="100%" border="0" class="">
			<TR>
				<TD class="visit_icon" align="right" valign="bottom">
					<span class="h_label">
						<? if ($row['session_is_new_visitor'] == true): ?>
						<img src="<?=$this->makeImageLink('base/i/newuser_icon_small.png');?>" alt="New Visitor" >
						<? else:?>
						<img src="<?=$this->makeImageLink('base/i/user_icon_small.png');?>" alt="Repeat Visitor">
						<? endif;?>
					</span>
				</TD>
				
				<TD valign="bottom">
					 <a href="<?=$this->makeLink(array('do' => 'base.reportVisitor', 'visitor_id' => $row['visitor_id'], 'site_id' => $site_id));?>">
					 	<span class="inline_h2"><? if (!empty($row['visitor_user_name'])):?><?=$row['visitor_user_name'];?><? elseif (!empty($row['visitor_user_email'])):?><?=$row['visitor_user_email'];?><? else: ?><?=$row['visitor_id'];?><? endif; ?></span>
					 </a>
					<? if ($row['session_is_new_visitor'] == false): ?>
						<? if (!empty($row['session_prior_session_id'])): ?>	
						- <span class="info_text">(<a href="<?=$this->makeLink(array('session_id' => $row['session_prior_session_id'], 'do' => 'base.reportVisit'), true);?>">Last visit was</a>	<?=round($row['session_time_sinse_priorsession']/(3600*24));?> 
							<? if (round($row['session_time_sinse_priorsession']/(3600*24)) == 1): ?>
								day ago.
							<? else: ?>
								days ago.
							<? endif; ?>
							)</span>
						<? endif;?>
					<? endif;?>
				</TD>
				
				<TD class="visit_box_stat" rowspan="4">	
					<div class="visitor_info_box pages_box">
						<a href="<?=$this->makeLink(array('session_id' => $row['session_id'], 'do' => 'base.reportVisit'), true);?>"><span class="large_number"><?=$row['session_num_pageviews'];?></span></a>
						<br />
						<span class="info_text">Pages</span>
					</div>
					<BR>				
					<? if (!empty($row['session_num_comments'])):?>
				
					<div class="comments_info_box">
						<span class="large_number"><?=$row['session_num_comments'];?></span><br /><span class="info_text"></span></a>
					</div>
					
					<? endif;?>
				</TD>	 
				
				<TR>					
				<TD class="visit_icon" align="right" valign="top"><span class="h_label">
					<img src="<?=$this->makeImageLink('base/i/document_icon.gif');?>" alt="Entry Page"></span>
				</TD>
										
				<TD valign="top">
					<a href="<?=$row['document_url'];?>"><span class="inline_h4"><?=$row['document_page_title'];?></span></a><? if($row['document_page_type']):?> (<?=$row['document_page_type'];?>)<? endif;?><BR><span class="info_text"><?=$row['document_url'];?></span>
				</TD>							
			</tr>
			
			<? if (!empty($row['referer_url'])):?>		
			<TR>
				<TD class="visit_icon" rowspan="2" align="right" valign="top">
				
					<span class="h_label"><img src="<?=$this->makeImageLink('base/i/referer_icon.gif');?>" alt="Refering URL"></span>
				</TD>

				<TD valign="top" colspan="2">
					<a href="<?=$row['referer_url'];?>"><? if (!empty($row['referer_page_title'])):?><span class="inline_h4"><?=$this->truncate($row['referer_page_title'], 80, '...');?></span></a><BR><span class="info_text"><?=$this->truncate($row['referer_url'], 80, '...');?></span><? else:?><?=$this->truncate($row['referer_url'], 50, '...');?><? endif;?></a>
				</TD>
																
			</TR>
			<? endif;?>
						
		<? if (!empty($row['referer_snippet'])):?>			
			<TR>
				<TD colspan="1">
					<span class="snippet_text"><?=$row['referer_snippet'];?></span>
				</TD>
				
			</TR>
		<? endif;?>

								
			</TR>
						
		</table>
	
</div>
</TD>