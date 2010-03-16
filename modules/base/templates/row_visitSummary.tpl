<TD>
	<div class="owa_visitSummaryInfobox">
	
		<span class="h_label"><?php echo $row['session_month'];?>/<?php echo $row['session_day'];?> @ at <?php echo $row['session_hour'];?>:<?php echo $row['session_minute'];?></span> |
		<span class="info_text"><?php echo $row['host_host'];?><?php if ($row['host_city']):?> - <?php echo $row['host_city'];?>, <?php echo $row['host_country'];?><?php endif;?></span> 
		<?php echo $this->choose_browser_icon($row['ua_browser_type']);?>
				
		<table cellpadding="0" cellspacing="0" width="100%" border="0" class="">
			<TR>
				<TD class="visit_icon" align="right" valign="bottom">
					<span class="h_label">
						<?php if ($row['session_is_new_visitor'] == true): ?>
						<img src="<?php echo $this->makeImageLink('base/i/newuser_icon_small.png');?>" alt="New Visitor" >
						<?php else:?>
						<img src="<?php echo $this->makeImageLink('base/i/user_icon_small.png');?>" alt="Repeat Visitor">
						<?php endif;?>
					</span>
				</TD>
				
				<TD valign="bottom">
					 <a href="<?php echo $this->makeLink(array('do' => 'base.reportVisitor', 'visitor_id' => $row['visitor_id'], 'site_id' => $site_id));?>">
					 	<span class="inline_h2"><?php if (!empty($row['visitor_user_name'])):?><?php echo $row['visitor_user_name'];?><?php elseif (!empty($row['visitor_user_email'])):?><?php echo $row['visitor_user_email'];?><?php else: ?><?php echo $row['visitor_id'];?><?php endif; ?></span>
					 </a>
					<?php if ($row['session_is_new_visitor'] == false): ?>
						<?php if (!empty($row['session_prior_session_id'])): ?>	
						- <span class="info_text">(<a href="<?php echo $this->makeLink(array('session_id' => $row['session_prior_session_id'], 'do' => 'base.reportVisit'), true);?>">Last visit was</a>	<?php echo round($row['session_time_sinse_priorsession']/(3600*24));?> 
							<?php if (round($row['session_time_sinse_priorsession']/(3600*24)) == 1): ?>
								day ago.
							<?php else: ?>
								days ago.
							<?php endif; ?>
							)</span>
						<?php endif;?>
					<?php endif;?>
				</TD>
				
				<TD class="visit_box_stat" rowspan="4">	
					<div class="visitor_info_box pages_box">
						<a href="<?php echo $this->makeLink(array('session_id' => $row['session_id'], 'do' => 'base.reportVisit'), true);?>"><span class="large_number"><?php echo $row['session_num_pageviews'];?></span></a>
						<br />
						<span class="info_text">Pages</span>
					</div>
					<BR>				
					<?php if (!empty($row['session_num_comments'])):?>
				
					<div class="comments_info_box">
						<span class="large_number"><?php echo $row['session_num_comments'];?></span><br /><span class="info_text"></span></a>
					</div>
					
					<?php endif;?>
				</TD>	 
				
				<TR>					
				<TD class="visit_icon" align="right" valign="top"><span class="h_label">
					<img src="<?php echo $this->makeImageLink('base/i/document_icon.gif');?>" alt="Entry Page"></span>
				</TD>
										
				<TD valign="top">
					<a href="<?php echo $row['document_url'];?>"><span class="inline_h4"><?php echo $row['document_page_title'];?></span></a><?php if($row['document_page_type']):?> (<?php echo $row['document_page_type'];?>)<?php endif;?><BR><span class="info_text"><?php echo $row['document_url'];?></span>
				</TD>							
			</tr>
			
			<?php if (!empty($row['referer_url'])):?>		
			<TR>
				<TD class="visit_icon" rowspan="2" align="right" valign="top">
				
					<span class="h_label"><img src="<?php echo $this->makeImageLink('base/i/referer_icon.gif');?>" alt="Refering URL"></span>
				</TD>

				<TD valign="top" colspan="2">
					<a href="<?php echo $row['referer_url'];?>"><?php if (!empty($row['referer_page_title'])):?><span class="inline_h4"><?php echo $this->truncate($row['referer_page_title'], 80, '...');?></span></a><BR><span class="info_text"><?php echo $this->truncate($row['referer_url'], 80, '...');?></span><?php else:?><?php echo $this->truncate($row['referer_url'], 50, '...');?><?php endif;?></a>
				</TD>
																
			</TR>
			<?php endif;?>
						
		<?php if (!empty($row['referer_snippet'])):?>			
			<TR>
				<TD colspan="1">
					<span class="snippet_text"><?php echo $row['referer_snippet'];?></span>
				</TD>
				
			</TR>
		<?php endif;?>

								
			</TR>
						
		</table>
	
</div>
</TD>