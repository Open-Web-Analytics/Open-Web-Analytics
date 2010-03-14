<TD>
	<div class="owa_visitInfobox">
	
		<p class="owa_visitInfoboxTitle"><?php echo date(DATE_RFC822,$row['session_timestamp']);?> &raquo; <?php echo $row['host_host'];?></p>
		
		<table class="owa_visitInfoboxItemContainer" cellspacing="0" width="100%">
			<TR>
				<TD>
					<table class="owa_userInfobox">
						<TD class="owa_avatar">
							<img src="<?php echo $this->getAvatarImage($row['visitor_user_email']);?>">
						</TD>
						<TD class="owa_userLabel">
							<a href="<?php echo $this->makeLink(array('do' => 'base.reportVisitor', 'visitor_id' => $row['visitor_id'], 'site_id' => $site_id));?>">
								<span class="owa_userNameLabel">
									<?php 
										if (!empty($row['visitor_user_name'])):
											echo $row['visitor_user_name'];
										elseif (!empty($row['visitor_user_email'])):
											echo $row['visitor_user_email'];
										else:
											echo $row['visitor_id'];
										endif; 
									?> 
								</span>
							</a> 
							<?php if ($row['session_is_new_visitor'] == true): ?>
							<img src="<?php echo $this->makeImageLink('base/i/icon_new.png');?>" alt="New Visitor" >
							<?php endif;?>
							<BR>
							<?php if ($row['host_city']):?> 
							<span class="owa_userGeoLabel">
								<?php echo $row['host_city'];?><BR> 
								<?php echo $row['host_country'];?>
							</span>
							<?php endif;?>
						</TD>
					</table>
				</td>
				<TD class="owa_visitInfoboxItem">
					<?php echo $this->choose_browser_icon($row['ua_browser_type']);?>
				</TD>
				<TD class="owa_visitInfoboxItem">
					<span class="owa_largeNumber">
						<a href="<?php echo $this->makeLink(array('session_id' => $row['session_id'], 'do' => 'base.reportVisit'), true);?>">
							<?php echo $row['session_num_pageviews'];?>
						</a>
					</span>
					<BR>
					<span class="info_text">Pages</span>
					
				</TD>
				<TD class="owa_visitInfoboxItem">
					<span class="owa_largeNumber">
						<?php echo date("G:i:s",mktime(0,0,($row['session_last_req'] - $row['session_timestamp'])));?>
					</span>
					<BR>
					<span class="info_text">Length</span>
				</TD>
			</TR>
		</table>
		
		<table class="owa_visitInfoboxDocContainer">		
			<TR>					
				<TD class="owa_icon16x16" align="" valign="top"><span class="h_label">
					<img src="<?php echo $this->makeImageLink('base/i/document_icon.gif');?>" alt="Entry Page"></span>
				</TD>
										
				<TD valign="top">
					<span class="inline_h4">
						<a href="<?php echo $row['document_url'];?>"><?php echo $row['document_page_title'];?></a>
					</span>
					<span class="info_text">
						<?php if($row['document_page_type']):?> 
						(<?php echo $row['document_page_type'];?>)
						<?php endif;?>
					</span>
					<BR>
					<span class="info_text"><?php echo $this->truncate($row['document_url'],80,'...');?></span>
				</TD>							
			</tr>
			
			<?php if (!empty($row['referer_url'])):?>		
			<TR>
				<TD class="owa_icon16x16" rowspan="2" align="right" valign="top">
				
					<span class="h_label"><img src="<?php echo $this->makeImageLink('base/i/referer_icon.gif');?>" alt="Refering URL"></span>
				</TD>

				<TD valign="top" colspan="2">
					<a href="<?php echo $row['referer_url'];?>"><?php if (!empty($row['referer_page_title'])):?><span class="inline_h4"><?php echo $this->truncate($row['referer_page_title'], 80, '...');?></span></a><BR><span class="info_text"><?php echo $this->truncate($row['referer_url'], 80, '...');?></span><?php else:?><?php echo $this->truncate($row['referer_url'], 80, '...');?><?php endif;?></a>
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