<TD>
	<div class="owa_visitInfobox" style="width:auto;">
	
		<p class="owa_visitInfoboxTitle"><?php echo date("D M j G:i:s T",$row['session_timestamp']);?> &raquo; <?php echo $row['host_host'];?></p>
		
		<table class="owa_visitInfoboxItemContainer" cellspacing="0" width="100%">
			<TR>
				<TD>
					<table class="owa_userInfobox">
						<TD valign="top">
							
							<?php if ($row['session_is_new_visitor'] == true): ?>
							 <img src="<?php echo $this->makeImageLink('base/i/icon_new.png');?>" alt="New Visitor"><BR>
							<?php endif;?>
							<img class="owa_avatar" src="<?php echo $this->getAvatarImage($row['visitor_user_email']);?>" width="30" height="30">
						</TD>
						<TD valign="top" class="owa_userLabel" style="width:auto;">
							
							<span class="inline_h4">
							
							<?php
							if ( ! empty( $row[ 'session_user_name' ] ) ) {
								echo $row[ 'session_user_name' ];
							} else {
								echo $row['visitor_id'];
							}?></span> 
							
							<BR>
							<?php if ($row['location_city']):?> 
							<span class="owa_userGeoLabel"><?php echo $row['location_city'];?>, <?php echo $row['location_country'];?></span>
							<?php endif;?>
							<BR>
							<span class="owa_moreLinks"><a href="<?php echo $this->makeLink(array('do' => 'base.reportVisitor', 'visitor_id' => $row['visitor_id'], 'site_id' => $this->get('site_id')),true);?>">Visitor Detail &raquo</a></span>
							&nbsp<span class="owa_moreLinks"><a href="<?php echo $this->makeLink(array('session_id' => $row['session_id'], 'do' => 'base.reportVisit'), true);?>">Visit Detail &raquo</a></span>
						</TD>
					</table>
				</td>
				<TD class="owa_visitInfoboxItem">
					
					<?php $this->renderKpiInfobox(
						$this->choose_browser_icon($row['browser_type']),
						'Browser Type',
						$this->makeLink(array('session_id' => $row['session_id'], 'do' => 'base.reportVisit'), true),
						'visitSummaryKpi'	
					);?>
				
				</TD>
				<TD class="owa_visitInfoboxItem">
				
					<?php $this->renderKpiInfobox(
						$row['session_num_pageviews'],
						'Pages Viewed',
						$this->makeLink(array('session_id' => $row['session_id'], 'do' => 'base.reportVisit'), true),
						'visitSummaryKpi'	
					);?>
					
				</TD>
				<TD class="owa_visitInfoboxItem">
					<?php $this->renderKpiInfobox(
						date("G:i:s",mktime(0,0,($row['session_last_req'] - $row['session_timestamp']))),
						'Visit Length',
						'',
						'visitSummaryKpi'	
					);?>
					
				</TD>
			</TR>
		</table>
		
		<table class="owa_visitInfoboxDocContainer">		
			
			<TR style="border-top: 1px solid #cccccc;">	
				
				<td valign="top"colspan="2">
					
					<?php include('documentNavSum.php'); ?>
				
				</td>	
								
			</tr>
								
			<TR style="border-top: 1px solid #cccccc;">
			
				<TD valign="top" colspan="2">
					<?php include('trafficSourceSum.php');?>
				</TD>
																
			</TR>
									
		</table>
	
</div>
</TD>