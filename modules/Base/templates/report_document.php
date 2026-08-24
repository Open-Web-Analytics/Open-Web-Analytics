<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php if ($view->dimension_properties): ?>
<div class="owa_reportSectionContent">
    <?php echo $view->renderDimension($view->dimension_template, $view->dimension_properties);?>
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
                    <div class="section_header">Visitors</div>
                    <div id="pagevisitors"></div>
                </div>
            </TD>

            <TD width="50%" valign="top">
                <div class="owa_reportSectionContent">
                    <div class="owa_reportSectionHeader">Next Pages Viewed</div>
                    <div id="nextpages"></div>
                </div>

                <div class="owa_reportSectionContent">
                    <div class="owa_reportSectionHeader">More analytics for this Page:</div>

                    <P>
                        <span class="inline_h3"><a href="<?php 
	                        echo ( 
	                        	$view->makeLink(
		                        	[
			                        	'do' => 'base.overlayLauncher', 
				                        'document_id' =>$view->document->get('id'), 
					                    'overlay_params' => base64_encode(
											$view->makeParamString(
							                    
								                [
								                    'action' 		=> 'loadHeatmap', 
									                'api_url' 		=> $view->makeOverlayApiLink(
									                	
										                [
										                	'document_id' 	=> $view->document->get('id'),
														    'module' 		=> 'base',
														    'version'		=> 'v1',
														    'do'			=> 'reports',
														    'report_name'	=> 'clicks'    	
									                	], 
										                'document_id'
									                ), 
										            
											        'document_id' 	=> $view->document->get('id')
												], 
												false, 
												'json'
											)
										)
									]
								)
							);?>" target="_blank">Heatmap Overlay</a></span> - click visualization map.
                    </P>

                    <P>
                        <span class="inline_h3"><a href="<?php echo $view->makeLink(array('do' => 'base.report', 'reportId' => 'domstreams', 'document_id' => $view->document->get('id')), true);?>">Domstreams</a></span> - mouse movement recordings.
                    </P>

                    <P>
                        <span class="inline_h3"><a href="<?php echo $view->makeLink(array('do' => 'base.report', 'reportId' => 'dom-clicks', 'document_id' => $view->document->get('id')), true);?>">Dom Clicks</a></span> - analysis of dom clicks.
                    </P>
                </div>
            </TD>
        </TR>
    </table>
</div>



<script>
        var trurl = '<?php echo $view->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                      'metrics' => 'visits',
                                                      'dimensions' => 'pagePath,pageTitle',
                                                      'sort' => 'visits-',
                                                      'resultsPerPage' => 15,
                                                      'constraints'            => 'priorPageUrl=='.urlencode((string) $view->dimension_properties->get('url')),
                                                      'format' => 'json'), true);?>';

        var trshre = new OWA.resultSetExplorer('nextpages');
        var link = '<?php echo $view->makeLink(array('do' => 'base.report', 'reportId' => 'document', 'pagePath' => '%s'), true);?>';
        trshre.addLinkToColumn('pagePath', link, ['pagePath']);
        trshre.asyncQueue.push(['refreshGrid']);
        trshre.load(trurl);

        var prurl = '<?php echo $view->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                      'metrics' => 'visits',
                                                      'dimensions' => 'priorPagePath,priorPageTitle',
                                                      'sort' => 'visits-',
                                                      'resultsPerPage' => 15,
                                                      'constraints'            => urlencode('pageUrl=='.(string) $view->dimension_properties->get('url')),
                                                      'format' => 'json'), true);?>';

        var prshre = new OWA.resultSetExplorer('priorpages');
        var link = '<?php echo $view->makeLink(array('do' => 'base.report', 'reportId' => 'document', 'pagePath' => '%s'), true);?>';
        prshre.addLinkToColumn('priorPagePath', link, ['priorPagePath']);
        prshre.asyncQueue.push(['refreshGrid']);
        prshre.load(prurl);

        var vrurl = '<?php echo $view->makeApiLink(['do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                    'metrics'           => 'visits,pageViews',
                                                    'dimensions'        => 'visitorId',
                                                    'sort'              => 'visits-',
                                                    'resultsPerPage'    => 15,
                                                    'constraints'       => urlencode('pageUrl=='.(string) $view->dimension_properties->get('url')),
                                                    'format'            => 'json'], true);?>';

        var vrshre = new OWA.resultSetExplorer('pagevisitors');
        var link = '<?php echo $view->makeLink(['do' => 'base.report', 'reportId' => 'visitor', 'visitorId' => '%s'], true);?>';
        vrshre.addLinkToColumn('visitorId', link, ['visitorId']);
        vrshre.asyncQueue.push(['refreshGrid']);
        vrshre.load(vrurl);
</script>

