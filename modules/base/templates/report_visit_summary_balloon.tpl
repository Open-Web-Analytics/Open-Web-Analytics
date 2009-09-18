<P>
<div>

<?php if ($visit['session_is_new_visitor'] == true): ?>
New Visitor
<?php else: ?>
Returning Visitor <span class="info_text">(<a href="<?php echo $this->makeLink(array('session_id' => $visit['session_prior_session_id'], 'do' => 'base.reportVisit'), true,'',true);?>">Last visit was</a>	<?php echo round($visit['session_time_sinse_priorsession']/(3600*24));?> 
<?php if (round($visit['session_time_sinse_priorsession']/(3600*24)) == 1): ?>
day ago.
<?php else: ?>
days ago.
<?php endif; ?>
)</span>
<?php endif;?>
<?php echo $this->choose_browser_icon($visit['ua_browser_type']);?><P>

<span class="inline_h2"><?php echo $visit['host_host'];?> - <?php echo $visit['session_month'];?>/<?php echo $visit['session_day'];?> at <?php echo $visit['session_hour'];?>:<?php echo $visit['session_minute'];?></span>
<P>

<?php if ($visit['host_city']):?>
<?php echo $visit['host_city'];?>, <?php echo $visit['host_country'];?> 
<?php endif;?>
<P>			
<table cellpadding="0" cellspacing="0" width="250" border="0" class="visit_summary">
	<TR>
		<TD class="visit_icon" align="left" valign="top" width="20">
			<img src="<?php echo $this->makeImageLink('base/i/user_icon_small.gif', true);?>" alt="Visitor"> 
		</TD>	
		<TD valign="top">
			<a href="<?php echo $this->makeLink(array('do' => 'base.reportVisitor', 'visitor_id' => $visit['visitor_id']), true,'',true);?>">
			<span class="inline_h2"><?php if (!empty($visit['visitor_user_name'])):?><?php echo $visit['visitor_user_name'];?><?php elseif (!empty($visit['visitor_user_email'])):?><?php echo $visit['visitor_user_email'];?><?php else: ?><?php echo $visit['visitor_id'];?><?php endif; ?></span></a>
			
		</TD>
	</TR>							
	<TR>					
		<TD class="visit_icon" align="left" width="20" valign="top"><span class="h_label">
			<img src="<?php echo $this->makeImageLink('base/i/document_icon.gif', true);?>" alt="Entry Page"> </span>
		</TD>
		<TD valign="top">
			<a href="<?php echo $visit['document_url'];?>"><span class="inline_h4"><?php echo $this->escapeForXml($visit['document_page_title']);?></span></a><?php if($visit['document_page_type']):?> (<?php echo $visit['document_page_type'];?>)<?php endif;?><BR> 
			<span class="info_text"><?php echo $visit['document_url'];?></span>
		</TD>							
	</TR>
	<?php if (!empty($visit['referer_url'])):?>					
	<TR>
		<TD class="visit_icon" rowspan="2" align="left" width="20" valign="top">
			<span class="h_label"><img src="<?php echo $this->makeImageLink('base/i/referer_icon.gif', true);?>" alt="Refering URL"> </span>
		</TD>
		<TD valign="top" colspan="2">
			<a href="<?php echo $visit['referer_url'];?>"><?php if (!empty($visit['referer_page_title'])):?><span class="inline_h4"><?php echo $this->escapeForXml($this->truncate($visit['referer_page_title'], 80, '...'));?></span></a> <span class="info_text"><?php echo $this->truncate($visit['referer_url'], 35, '...');?></span><?php else:?><?php echo $this->truncate($visit['referer_url'], 50, '...');?><?php endif;?></a>
		</TD>													
	</TR>								
	<?endif;?>		
</table>
		
<P><a href="<?php echo $this->makeLink(array('session_id' => $visit['session_id'], 'do' => 'base.reportVisit'), true,'',true);?>"><span class="">View Visit Details</a></P>

</div>