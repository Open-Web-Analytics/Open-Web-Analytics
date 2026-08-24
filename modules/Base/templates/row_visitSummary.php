<?php
/**
 * @var \OWA\Core\ViewScope $view
 * @var array $row  from the includer's scope -- see below
 *
 * row_visitSummary.php is included from inside report_latest_visits.php's
 * `foreach ($view->visits->resultsRows as $row)`, so $row is the loop value from
 * the INCLUDER's scope, not a view var. It is also this file's own contract: it
 * includes documentNavSum.php and trafficSourceSum.php, which read the same $row.
 *
 * PHPStan analyses every template standalone, so it cannot see an includer's
 * locals; the @var above declares the contract rather than suppressing it.
 */
?>
<TD>
    <div class="owa_visitInfobox" style="width:auto;">

        <p class="owa_visitInfoboxTitle"><?php echo date("D M j G:i:s T",$row['session_timestamp']);?> &raquo; <?php echo $row['host_host']; if (! empty($row['ip_address']) ) { echo ' ('.$row['ip_address'].')';}?></p>

        <table class="owa_visitInfoboxItemContainer" cellspacing="0" width="100%">
            <TR>
                <TD>
                    <table class="owa_userInfobox">
                        <TD valign="top">

                            <?php 
	                            
	                        if ($row['session_is_new_visitor'] == true) {
	             		    
	             		        echo '<i class="owa_avatar fas fa-user-plus fa-2x"></i>';
                            
                            } else { 
	                            
	                            $avatar = $view->getAvatarImage($row['visitor_user_email']);
	                            if ( $avatar ) {
		                    		
		                    		echo '<img class="owa_avatar" src="'. $avatar.'" width="30" height="30">';        
		                            
	                            } else {
		                            
		                            echo '<i class="owa_avatar fas fa-user fa-2x"></i>';
	                            }
	                            
                            }
                            
                            ?>
                        </TD>
                        <TD valign="top" class="owa_userLabel" style="width:auto;">

                            <span class="inline_h4">

                            <?php
                            if ( $view->isValueSet( $row[ 'session_user_name' ] ) ) {
                                $view->out( $row[ 'session_user_name' ] );
                            } else {
                                $view->out( $row['visitor_id'] );
                            }?></span>
                            <BR>
                            <?php if ( $view->isValueSet( $row['location_city'] ) || $view->isValueSet( $row['location_country'] ) ):?>
                            <span class="owa_userGeoLabel"><?php echo $row['location_city'];?>, <?php echo $row['location_country'];?></span>
                            <?php endif;?>
                            <BR>
                            <span class="owa_moreLinks"><a href="<?php echo $view->makeLink(array('do' => 'base.report', 'reportId' => 'visitor', 'visitor_id' => $row['visitor_id'], 'site_id' => $view->get('site_id')),true);?>">Visitor Detail &raquo</a></span>
                            &nbsp<span class="owa_moreLinks"><a href="<?php echo $view->makeLink(array('session_id' => $row['session_id'], 'do' => 'base.report', 'reportId' => 'visit'), true);?>">Visit Detail &raquo</a></span>
                        </TD>
                    </table>
                </td>
                <TD class="owa_visitInfoboxItem">

                    <?php $view->renderKpiInfobox(
	                        '<i title="'. $row['browser_user_agent'] .'" class=" '. $view->choose_browser_icon($row['browser_type']) . '"></i>',
                        'Browser Type',
                        $view->makeLink(array('session_id' => $row['session_id'], 'do' => 'base.report', 'reportId' => 'visit'), true),
                        'visitSummaryKpi'
                    );?>

                </TD>
                <TD class="owa_visitInfoboxItem">

                    <?php $view->renderKpiInfobox(
                        $row['session_num_pageviews'],
                        'Pages Viewed',
                        $view->makeLink(array('session_id' => $row['session_id'], 'do' => 'base.report', 'reportId' => 'visit'), true),
                        'visitSummaryKpi'
                    );?>

                </TD>
                <TD class="owa_visitInfoboxItem">
                    <?php $view->renderKpiInfobox(
                        date("G:i:s",mktime(0,0,($row['session_last_req'] - $row['session_timestamp']))),
                        'Visit Length',
                        '',
                        'visitSummaryKpi'
                    );?>

                </TD>
                <TD class="owa_visitInfoboxItem">
                    <?php $view->renderKpiInfobox(
                        $row['session_num_prior_visits'],
                        'Prior Visits',
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