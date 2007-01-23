<? if(!empty($visits)):?>
	<?php foreach($visits as $visit): ?>
		
	<div>
		<fieldset>
			<legend>
				<span class="h_label"><?=$visit['session_month'];?>/<?=$visit['session_day'];?> @ at <?=$visit['session_hour'];?>:<?=$visit['session_minute'];?></span> |
					<span class="info_text"><?=$visit['host_host'];?> - <?=$visit['host_city'];?>, <?=$visit['host_country'];?></span> 
					<?=$this->choose_browser_icon($visit['ua_browser_type']);?>
			</legend>
						
			<table cellpadding="0" cellspacing="0" width="100%" border="0">
				<TR>
					<TD class="visit_icon" align="right" valign="bottom">
						<span class="h_label"><img src="<?=$this->config['images_url'].'/user_icon_small.gif';?>" alt="Visitor"></span>
					</TD>
					
					<TD valign="top">
						 <a href="<?=$this->makeLink(array('do' => 'base.reportVisitor', 'visitor_id' => $visit['visitor_id']), true);?>">
						 	<span class="inline_h2"><? if (!empty($visit['visitor_user_name'])):?><?=$visit['visitor_user_name'];?><?elseif (!empty($visit['visitor_user_email'])):?><?=$visit['visitor_user_email'];?><? else: ?><?=$visit['visitor_id'];?><? endif; ?></span>
						 </a> - 
						<? if ($visit['session_is_new_visitor'] == true): ?>
							New Visitor
						<? else: ?>
							Returning Visitor <span class="info_text">(<a href="<?=$this->makeLink(array('session_id' => $visit['session_prior_session_id'], 'do' => 'base.reportVisit'), true);?>">Last visit was</a>	<?=round($visit['session_time_sinse_priorsession']/(3600*24));?> 
							<? if (round($visit['session_time_sinse_priorsession']/(3600*24)) == 1): ?>
								day ago.
							<? else: ?>
								days ago.
							<? endif; ?>
								)</span>
						<?endif;?>
					</TD>
					
					<TD class="visit_box_stat" rowspan="4">	
						<div class="visitor_info_box pages_box">
							<a href="<?=$this->makeLink(array('session_id' => $visit['session_id'], 'do' => 'base.reportVisit'), true);?>"><span class="large_number"><?=$visit['session_num_pageviews'];?></span></a>
							<br />
							<span class="info_text">Pages</span>
						</div>
						<BR>				
						<? if (!empty($visit['session_num_comments'])):?>
					
						<div class="comments_info_box">
							<span class="large_number"><?=$visit['session_num_comments'];?></span><br /><span class="info_text"></span></a>
						</div>
						
						<?endif;?>
					</TD>	 
			
									
				</TR>
								
				<TR>					
					<TD class="visit_icon" align="right" valign="top"><span class="h_label">
						<img src="<?=$this->config['images_url'].'/document_icon.gif';?>" alt="Entry Page"></span>
					</TD>
											
					<TD valign="top">
						<a href="<?=$visit['document_url'];?>"><span class="inline_h4"><?=$visit['document_page_title'];?></span></a> (<?=$visit['document_page_type'];?>) <span class="info_text"><?=$visit['document_url'];?></span>
					</TD>							
				</tr>
							
				<TR>
					<TD class="visit_icon" rowspan="2" align="right" valign="top">
					<? if (!empty($visit['referer_url'])):?>
						<span class="h_label"><img src="<?=$this->config['images_url'].'/referer_icon.gif';?>" alt="Refering URL"></span>
					</TD>

					<TD valign="top" colspan="2">
						<a href="<?=$visit['referer_url'];?>"><? if (!empty($visit['referer_page_title'])):?><span class="inline_h4"><?=$this->truncate($visit['referer_page_title'], 80, '...');?></span></a> <span class="info_text"><?=$this->truncate($visit['referer_url'], 35, '...');?></span><? else:?><?=$this->truncate($visit['referer_url'], 50, '...');?><? endif;?></a>
					</TD>
																	
				</TR>
							
			<? if (!empty($visit['referer_snippet'])):?>			
				<TR>
					<TD colspan="1">
						<span class="snippet_text"><?=$visit['referer_snippet'];?></span>
					</TD>
					
				</TR>
			<?endif;?>
									
		<?endif;?>
						
			</table>
			
		</fieldset>
	</div>
	
<?php endforeach; ?>

<?else:?>
	There were no visits during this time period.
<? endif;?>