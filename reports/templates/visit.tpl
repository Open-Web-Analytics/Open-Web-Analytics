<?php foreach($visits as $visit): ?>
		
		<div>
			
			<table>
				<TR>
					<td>
						<table cellpadding="0" cellspacing="0" size="100%">
							<tr>
								<TD><span class="h_label"><?=$visit['month'];?>. <?=$visit['day'];?><!--<?=$visit['year'];?>--></span></TD>
							</tr>
							<tr>
								<td><!-- at <?=$visit['hour'];?>:<?=$visit['minute'];?>--></td>
							</tr>
						</table>
					</td>
					<TD>	
						<div class="visitor_info_box pages_box">
							<span class="large_number"><a href="<?=WA_REPORTING_URL;?>/session_report.php&wa_s=<?=$visit['session_id']?>"><?=$visit['num_pageviews'];?></span><br /><span class="info_text">Pages</span></a>
						</div>
						
					</td>
					<TD>
						<? if (!empty($visit['num_comments'])):?>
						
						<div class="comments_info_box">
							<span class="large_number"><?=$visit['num_comments'];?></span><br /><span class="info_text"></span></a>
						</div>
						 
						<?endif;?>
					
					</TD>
					<TD>
					<span class="h_label">Visitor:</span> <a href="<?=WA_REPORTING_URL;?>/visitor_report.php&wa_v=<?=$visit['visitor_id'] ?>"><span class="inline_h2"><? if (!empty($visit['user_name'])):?><?=$visit['user_name'];?><?elseif (!empty($visit['user_email'])):?><?=$visit['user_email'];?><? else: ?><?=$visit['visitor_id'];?><? endif; ?></span></a> via <span class="info_text"><?=$visit['host'];?></span> located in <span class="info_text"><?=$visit['city'];?>, <?=$visit['country'];?></span>
				
				<? if ($visit['is_new_visitor'] == true): ?>
				
				| New Visitor
					
				<? else: ?>
				
				| <a href="<?=WA_REPORTING_URL;?>/session_report.php&wa_s=<?=$visit['prior_session_id']?>">Last visit</a> was <?=round($visit['time_sinse_priorsession']/(3600*24));?> 
				
					<? if (round($visit['time_sinse_priorsession']/(3600*24)) == 1): ?>
						day ago.
					<? else: ?>
						days ago.
					<? endif; ?>
				<?endif;?>
				
				
			
				<BR />
				
				<span class="h_label">Entry:</span> <a href="<?=$visit['first_page_uri'];?>"><?=$visit['first_page_title'];?></a> <span class="info_text">(<?=$visit['first_page_type'];?>: <?=$visit['first_page_uri'];?>)</span>
				
				<BR />
				
				<span class="h_label">Referer: </span> 
				
				<? if (!empty($visit['referer'])):?>
				
				<a href="<?=$visit['referer'];?>"><? if (!empty($visit['referrer_page_title'])):?><?=$visit['referrer_page_title']?><? else:?><?=$this->truncate($visit['referer'], 70, '...');?><? endif;?></a> <span class="info_text">(<?=$this->truncate($visit['referer'], 70, '...');?>)</span>
				
				<? else: ?>
				
				None.
				
				<? endif; ?>
					
					</TD>
				</TR>
			</table>
				
		</div>
	
<?php endforeach; ?>