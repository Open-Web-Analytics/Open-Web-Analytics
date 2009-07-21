<div class="owa_reportSectionHeader">Visit Summary</div>
<div class="owa_reportSectionContent">
	<? include('report_latest_visits.tpl');?>
</div>	

<div class="owa_reportSectionHeader">Visit Clickstream</div>
<div class="owa_reportSectionContent">  
  

		<div>		
			<table size="100%">
				<TR>
					<TH>Time</TH>
					<TH>Page</TH>
				</TR>
				<?php foreach($clickstream as $s): ?>
				<TR>
					<TD colspan="2">
						<table class="owa_infobox" size="100%">
							<TR>
								<TD valign="top"><?=$s['hour'];?>:<?=$s['minute'];?>:<?=$s['second'];?></TD>
								<TD>
									<a href="<?=$this->makeLink(array('do' => 'base.reportDocument', 'document_id' => $s['document_id']));?>"><span class="inline_h2"><?=$s['page_title'];?></span></a> <span class="h_label">(<?=$s['page_type'];?>)</span><BR>
									<span class="info_text"><?=$s['url'];?></span>
								</TD>
							</TR>
						</table>
					</TD>
				</TR>
				<?php endforeach; ?>
			</table>
		</div>
</div>
 