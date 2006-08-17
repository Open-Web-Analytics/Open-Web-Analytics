<? if(!empty($visits)):?>
<?php foreach($visits as $visit): ?>
		
				<div>
					<fieldset>
					
					<legend>
					
					<span class="h_label"><?=$visit['month'];?>/<?=$visit['day'];?> @ at <?=$visit['hour'];?>:<?=$visit['minute'];?></span> |
					<span class="info_text"><?=$visit['host'];?> - <?=$visit['city'];?>, <?=$visit['country'];?></span> 
					<?=$this->choose_browser_icon($visit['browser_type']);?>
								
					</legend>
						
						<table cellpadding="0" cellspacing="0" width="100%" border="0">
							<TR>
								
											<TD valign="top"><span class="h_label">Vi:</span></TD>
											
											<TD valign="top">
											 <a href="<?=$this->make_report_link('visitor_report.php', array('visitor_id' => $visit['visitor_id'], 'site_id' => $params['site_id']));?>"><span class="inline_h3"><? if (!empty($visit['user_name'])):?><?=$visit['user_name'];?><?elseif (!empty($visit['user_email'])):?><?=$visit['user_email'];?><? else: ?><?=$visit['visitor_id'];?><? endif; ?></span></a> - 
												<? if ($visit['is_new_visitor'] == true): ?>
												New Visitor
												<? else: ?>
												Returning Visitor
												<span class="info_text">(<a href="<?=$this->make_report_link('session_report.php', array('session_id' => $visit['prior_session_id'], 'site_id' => $params['site_id']));?>">Last visit was</a>									
												<?=round($visit['time_sinse_priorsession']/(3600*24));?> 
													<? if (round($visit['time_sinse_priorsession']/(3600*24)) == 1): ?>
													day ago.
													<? else: ?>
													days ago.
													<? endif; ?>
													)</span>
												<?endif;?>
											</TD>
											
											<TD class="visit_box_stat" rowspan="2">	
												<div class="visitor_info_box pages_box">
														<a href="<?=$this->make_report_link('session_report.php', array('session_id' => $visit['session_id'], 'site_id' => $params['site_id']));?>"><span class="large_number"><?=$visit['num_pageviews'];?></span></a><br /><span class="info_text">Pages</span>
												</div>
											</TD>
											
											<? if (!empty($visit['num_comments'])):?>
											<TD rowspan="2" >	
												<div class="comments_info_box">
													<span class="large_number"><?=$visit['num_comments'];?></span><br /><span class="info_text"></span></a>
												</div>
											</TD>	 
											<?endif;?>
									
										</TR>
								
										<TR>
											
											<TD valign="top"><span class="h_label">In:</span></TD>
											
											<TD valign="top">
												<a href="<?=$visit['first_page_uri'];?>"><span class="inline_h4"><?=$visit['first_page_title'];?></span></a> (<?=$visit['first_page_type'];?>) 
												<span class="info_text"><?=$visit['first_page_uri'];?></span>
											</TD>
											
										
							</tr>
							<TR>
								<TD rowspan="2" valign="top">
									<? if (!empty($visit['referer'])):?>
									
												<span class="h_label">Fr:</span>
								</TD>

								<TD valign="top" colspan="2">
												<a href="<?=$visit['referer'];?>"><? if (!empty($visit['referer_page_title'])):?><span class="inline_h4"><?=$this->truncate($visit['referer_page_title'], 80, '...');?></span></a> <span class="info_text"><?=$this->truncate($visit['referer'], 35, '...');?></span><? else:?><?=$this->truncate($visit['referer'], 50, '...');?><? endif;?></a>
								</TD>
																	
							</TR>
							
							<? if (!empty($visit['referer_snippet'])):?>			
							<TR>
								<TD colspan="3">
													<span class="snippet_text"><?=$visit['referer_snippet'];?></span>
								</TD>
							</TR>
							<?endif;?>
									
						<?endif;?>
						
						</table>
					</TD>
				</TR>
			</table>
		</fieldset>
	</div>
	
<?php endforeach; ?>
<?else:?>
There were no visits during this time period.
<? endif;?>