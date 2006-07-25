<h2><?=$headline;?></h2>

<fieldset id="" class="options">
	<legend>Details</legend>
	<?=$visit_data?>
	
</fieldset>
  
  
<fieldset id="" class="options">
	<legend>Click-Stream</legend>
		
		<div>		
			<table>
				<TR>
					<TH>Time</TH>
					<TH>Page</TH>
				</TR>
				<?php foreach($session_data as $s): ?>
				<TR>
					<TD valign="top"><?=$s['hour'];?>:<?=$s['minute'];?>:<?=$s['second'];?></TD>
					<TD>
						<a href="<?=$this->make_report_link('document_report.php', array('document_id' => $s['document_id']));?>"><span class="inline_h2"><?=$s['page_title'];?></span></a> <span class="h_label">(<?=$s['page_type'];?>)</span><BR>
						<span class="info_text"><?=$s['page_uri'];?></span>
					</TD>
				</TR>
				<?php endforeach; ?>
			</table>
		</div>
</fieldset>