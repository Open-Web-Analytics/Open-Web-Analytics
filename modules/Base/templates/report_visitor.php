<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div>
    <div style="display: table-cell; vertical-align: middle">
        <span>
        
         <?php            
                
            $avatar = '';
            if ( isset($row) && is_array( $row ) && array_key_exists('visitor_user_email', $row) ) {
                
                $view->getAvatarImage($row['visitor_user_email']);
            }
            if ( $avatar ) {
        		
        		echo '<img class="owa_avatar" src="'. $avatar.'" style="vertical-align:middle;">';        
                
            } else {
                
                echo '<i class="owa_avatar fas fa-user fa-3x"></i>';
            }
            
        
            ?>
        
        </span>
        <span class="inline_h2"><?php $view->out( $view->visitor_label );?></span>
    </div>
    <BR>
    <div>
        <?php $view->renderKpiInfobox( $view->first_visit_date, 'First Visit' ); ?>

    </div>
</div>

<div style="clear:both;"></div>


<table width="100%">
        <TR>
            <td valign="top">
                <div class="owa_reportSectionContent" style="min-width:500px;">
                    <div class="owa_reportSectionHeader">Latest Visits</div>
                    <?php include('report_latest_visits.php')?>
                    <?php echo $view->makePaginationFromResultSet($view->visits, array('do' => 'base.report', 'reportId' => 'visitors'), true);?>
                </div>
            </td>
            <td valign="top">
                <div class="owa_reportSectionContent" style="min-width:;">
                    <div class="owa_reportSectionHeader">Latest Actions</div>
						<div id="latest-actions"></div>
                </div>
            </td>
        </TR>
</table>


<script>
	
var burl = '<?php echo $view->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1', 
                                                          'metrics' => 'actions', 
                                                          'dimensions' => 'actionGroup,actionName', 
                                                          'constraints' => 'visitorId=='.$view->get('visitor_id'),
                                                          'sort' => 'actions-', 
                                                          'resultsPerPage' => 5,
                                                          'format' => 'json'), true);?>';
	
var bsh = new OWA.resultSetExplorer('latest-actions');
	bsh.options.grid.showRowNumbers = false;
	bsh.addLinkToColumn('actionGroup', '<?php echo $view->makeLink(array('do' => 'base.report', 'reportId' => 'action-group', 'actionGroup' => '%s'), true);?>', ['actionGroup']);
	bsh.asyncQueue.push(['refreshGrid']);
	bsh.load(burl);
	OWA.items['<?php echo $view->dom_id;?>'].registerResultSetExplorer('bsh', bsh);

</script>