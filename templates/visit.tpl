<?php foreach($visits as $visit): ?>
		
				<div>
					<fieldset>
						
						<table cellpadding="0" cellspacing="0">
							<TR>
								
								<TD colspan="3">
								<span class="h_label"><?=$visit['month'];?>/<?=$visit['day'];?> @ at <?=$visit['hour'];?>:<?=$visit['minute'];?></span>
								 - 
								
									<? if ($visit['is_new_visitor'] == true): ?>
												New Visitor
												<? else: ?>
												Returning Visitor
												<span class="info_text">(<a href="<?=$this->make_report_link('session_report.php', array($this->config['ns'].$this->config['session_param'] => $visit['prior_session_id']));?>">Last visit was</a>
																					
												<?=round($visit['time_sinse_priorsession']/(3600*24));?> 
													<? if (round($visit['time_sinse_priorsession']/(3600*24)) == 1): ?>
													day ago.
													<? else: ?>
													days ago.
													<? endif; ?>
													)</span>
												<?endif;?>
												<?=$this->choose_browser_icon($visit['browser_type']);?>
							 
								</TD>
							</tr>
							<TR>
								<TD class="visit_box_stat">	
									<div class="visitor_info_box pages_box">
										<span class="large_number">
											<a href="<?=$this->make_report_link('session_report.php', array($this->config['ns'].$this->config['session_param'] => $visit['session_id']));?>"><?=$visit['num_pageviews'];?></span><br /><span class="info_text">Pages</span></a>
									</div>
									
								</td>
								
								<? if (!empty($visit['num_comments'])):?>
								<TD>	
									<div class="comments_info_box">
										<span class="large_number"><?=$visit['num_comments'];?></span><br /><span class="info_text"></span></a>
									</div>
								</TD>	 
								<?endif;?>
								
								<TD>
									<table cellpadding="0" cellspacing="0">
										<TR>
											<TD>
											<span class="h_label">V:</span> <a href="<?=$this->config['reporting_url'];?>/visitor_report.php&<?=$this->config['ns'].$this->config['visitor_param']?>=<?=$visit['visitor_id'] ?>"><span class="inline_h2"><? if (!empty($visit['user_name'])):?><?=$visit['user_name'];?><?elseif (!empty($visit['user_email'])):?><?=$visit['user_email'];?><? else: ?><?=$visit['visitor_id'];?><? endif; ?></span></a> 
											 <span class="info_text"><?=$visit['host'];?> - <?=$visit['city'];?>, <?=$visit['country'];?></span>
											</TD>
										</TR>
										<TR>
											<TD>
												<span class="h_label">In:</span> <a href="<?=$visit['first_page_uri'];?>"><?=$visit['first_page_title'];?></a> (<?=$visit['first_page_type'];?>) 
												<span class="info_text"><?=$visit['first_page_uri'];?></span>
											</TD>
										</TR>
										<TR>
											<TD>
												<table>
													<TR>
														<TD>
															<span class="h_label">From: </span>
														</TD>
														<TD>
															<? if (!empty($visit['referer'])):?>
											<a href="<?=$visit['referer'];?>"><? if (!empty($visit['referer_page_title'])):?><?=$visit['referer_page_title']?><? else:?><?=$this->truncate($visit['referer'], 70, '...');?><? endif;?></a> <span class="info_text"> 
											<?=$this->truncate($visit['referer'], 35, '...');?></span>
											
											<? else: ?>
											None.
											<? endif; ?>
														</TD>
													</TR>
													<TR>
														<TD>
														
														</TD>
														<TD>
															<span class="snippet_text"><?=$visit['referer_snippet'];?></span>
														</TD>
													</TR>
												</table>
											</TD>
										</TR>
									</table>
								</TD>
							</TR>
						</table>
					</fieldset>
				</div>
	
<?php endforeach; ?>