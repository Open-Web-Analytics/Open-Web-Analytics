<?php if ($dimension_properties): ?>
<div class="owa_reportSectionContent">
	<?php echo $this->renderDimension($dimension_template, $dimension_properties);?>
</div>
<?php endif;?>

<?php require('report_trend_section.php');?>
	
<div class="owa_reportSectionContent">
	<table style="width:100%;">
		<TR>
		
			<TD width="50%" valign="top">
				
				<div class="owa_reportSectionContent">
					<div class="owa_reportSectionHeader">Prior Pages Viewed</div>
					<div id="priorpages"></div>
				</div>
				
				<div class="owa_reportSectionContent">	
					<div class="owa_reportSectionHeader">Related Reports:</div>
				
					<P>
						<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.overlayLauncher', 'document_id' =>$document->get('id'), 'overlay_params' => base64_encode($this->makeParamString(array('action' => 'loadHeatmap', 'api_url' => owa_coreAPI::getSetting('base', 'api_url'), 'document_id' => $document->get('id') ), true, 'json'))));?>" target="_blank">Heatmap Overlay</a></span> (Firefox 3.5+ required)
					</P>
					
					<P>
						<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.reportDomstreams', 'document_id' => $document->get('id')), true);?>">Domstreams</a></span> - mouse movement recordings.
					</P>
					
					<P>
						<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.reportDomClicks', 'document_id' => $document->get('id')), true);?>">Dom Clicks</a></span> - analysis of dom clicks.
					</P>
				</div>
			</TD>
			<div class="owa_reportSectionContent">
			
			<TD width="50%" valign="top">
				<div class="owa_reportSectionHeader">Next Pages Viewed</div>
					<div id="nextpages"></div>
				</div>
			</TD>
		</TR>
	</table>	
</div>



<script>
		var trurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
													  'metrics' => 'visits', 
													  'dimensions' => 'pagePath,pageTitle', 
													  'sort' => 'visits-', 
													  'resultsPerPage' => 15,
													  'constraints'			=> 'priorPageUrl=='.urlencode($dimension_properties->get('url')),
													  'format' => 'json'), true);?>';
													  
		var trshre = new OWA.resultSetExplorer('nextpages');
		var link = '<?php echo $this->makeLink(array('do' => 'base.reportDocument', 'pagePath' => '%s'), true);?>';
		trshre.addLinkToColumn('pagePath', link, ['pagePath']);
		trshre.asyncQueue.push(['refreshGrid']);
		trshre.load(trurl);
		
		var prurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
													  'metrics' => 'visits', 
													  'dimensions' => 'priorPagePath,priorPageTitle', 
													  'sort' => 'visits-', 
													  'resultsPerPage' => 15,
													  'constraints'			=> urlencode('pageUrl=='.$dimension_properties->get('url')),
													  'format' => 'json'), true);?>';
													  
		var prshre = new OWA.resultSetExplorer('priorpages');
		var link = '<?php echo $this->makeLink(array('do' => 'base.reportDocument', 'pagePath' => '%s'), true);?>';
		prshre.addLinkToColumn('priorPagePath', link, ['priorPagePath']);
		prshre.asyncQueue.push(['refreshGrid']);
		prshre.load(prurl);
</script>

<?php require_once('js_report_templates.php');?>