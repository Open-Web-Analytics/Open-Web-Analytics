<?php require('report_dimensionDetail.php');?>
	
<div class="owa_reportSectionContent">
	<table>
		<TR>
			
			<TD width="" valign="top">
				<div class="owa_reportSectionHeader">More reports for this document:</div>
				
				<P>
					<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.overlayLauncher', 'document_id' =>$document->get('id'), 'overlay_params' => urlencode($this->makeParamString(array('action' => 'loadHeatmap'), true, 'cookie'))));?>" target="_blank">Heatmap Overlay</a></span> (Firefox 3.5+ required)
				</P>
				
				<P>
					<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.reportDomstreams', 'document_id' => $document->get('id')), true);?>">Domstreams</a></span> - mouse movement recordings.
				</P>
					
			</TD>
		</TR>
	</table>	
</div>
