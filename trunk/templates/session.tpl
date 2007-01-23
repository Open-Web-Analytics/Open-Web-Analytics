<h2><?=$headline;?></h2>

<fieldset id="" class="options">
	<legend>Details</legend>
	<? include('report_latest_visits.tpl');?>
	
</fieldset>
  
  
<fieldset id="" class="options">
	<legend>Click-Stream</legend>
		
		<div>		
			<table>
				<TR>
					<TH>Time</TH>
					<TH>Page</TH>
				</TR>
				<?php foreach($clickstream as $s): ?>
				<TR>
					<TD valign="top"><?=$s['hour'];?>:<?=$s['minute'];?>:<?=$s['second'];?></TD>
					<TD>
						<a href="<?=$this->makeLink(array('view' => 'base.report', 'subview' => 'base.reportDocument', 'document_id' => $s['document_id']));?>"><span class="inline_h2"><?=$s['page_title'];?></span></a> <span class="h_label">(<?=$s['page_type'];?>)</span><BR>
						<span class="info_text"><?=$s['document_url'];?></span>
					</TD>
				</TR>
				<?php endforeach; ?>
			</table>
		</div>
</fieldset>