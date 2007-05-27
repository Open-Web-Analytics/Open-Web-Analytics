<h2><?=$headline;?></h2>

<fieldset id="" class="options">
	<legend>Visit Detail</legend>
	<? include('report_latest_visits.tpl');?>
	
</fieldset>
  
  
<fieldset id="" class="options">
	<legend>Click-stream</legend>
		
		<div>		
			<table>
				<TR>
					<TH>Time</TH>
					<TH>Page</TH>
				</TR>
				<?php foreach($clickstream as $s): ?>
				<TR>
					<TD valign="top"><?=$s['request_hour'];?>:<?=$s['request_minute'];?>:<?=$s['request_second'];?></TD>
					<TD>
						<a href="<?=$this->makeLink(array('do' => 'base.reportDocument', 'document_id' => $s['document_id']));?>"><span class="inline_h2"><?=$s['document_page_title'];?></span></a> <span class="h_label">(<?=$s['document_page_type'];?>)</span><BR>
						<span class="info_text"><?=$s['document_url'];?></span>
					</TD>
				</TR>
				<?php endforeach; ?>
			</table>
		</div>
</fieldset>
 