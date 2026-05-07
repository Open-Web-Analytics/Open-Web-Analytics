<div class="owa_infobox">
    <table cellpadding="0" cellspacing="0" width="" border="0" class="visit_summary" style="">
        <TR>
            <!-- left col -->
            <TD valign="top" class="owa_visitSummaryLeftCol">
                <span class="h_label"><?php echo $visit['session_month'];?>/<?php echo $visit['session_day'];?> @ at <?php echo $visit['session_hour'];?>:<?php echo $visit['session_minute'];?></span> | <span class="info_text"><?php echo $visit['host_host'];?> <?php if ($visit['host_city']):?>- <?php echo $visit['host_city'];?>, <?php echo $visit['host_country'];?><?php endif;?></span> <?php echo $this->choose_browser_icon($visit['ua_browser_type']);?><BR>
                <table>
                    <TR>
                        <TD class="visit_icon" align="right" valign="bottom">
                            <span class="h_label">
                                <?php if ($visit['session_is_new_visitor'] == true): ?>
                                <img src="<?php echo $this->makeImageLink('base/i/newuser_icon_small.png');?>" alt="New Visitor" >
                                <?php else:?>
                                <img src="<?php echo $this->makeImageLink('base/i/user_icon_small.png');?>" alt="Repeat Visitor">
                                <?php endif;?>
                            </span>
                        </TD>

                        <TD valign="bottom">
                             <a href="<?php echo $this->makeLink(array('do' => 'base.reportVisitor', 'visitor_id' => $visit['visitor_id'], 'site_id' => $site_id));?>">
                                 <span class="inline_h2"><?php if (!empty($visit['visitor_user_name'])):?><?php echo $visit['visitor_user_name'];?><?php elseif (!empty($visit['visitor_user_email'])):?><?php echo $visit['visitor_user_email'];?><?php else: ?><?php echo $visit['visitor_id'];?><?php endif; ?></span>
                             </a>
                            <?php if ($visit['session_is_new_visitor'] == false): ?>
                                <?php if (!empty($visit['session_prior_session_id'])): ?>
                                - <span class="info_text">(<a href="<?php echo $this->makeLink(array('session_id' => $visit['session_prior_session_id'], 'do' => 'base.reportVisit'), true);?>">Last visit was</a>    <?php echo round($visit['session_time_sinse_priorsession']/(3600*24));?>
                                    <?php if (round($visit['session_time_sinse_priorsession']/(3600*24)) == 1): ?>
                                        day ago.
                                    <?php else: ?>
                                        days ago.
                                    <?php endif; ?>
                                    )</span>
                                <?php endif;?>
                            <?php endif;?>
                        </TD>
                    </TR>
                    <TR>
                        <TD class="visit_icon" align="right" valign="top"><span class="h_label">
                            <img src="<?php echo $this->makeImageLink('base/i/document_icon.gif');?>" alt="Entry Page"></span>
                        </TD>

                        <TD valign="top">
                            <a href="<?php echo $visit['document_url'];?>"><span class="inline_h4"><?php echo $visit['document_page_title'];?></span></a><?php if($visit['document_page_type']):?> (<?php echo $visit['document_page_type'];?>)<?php endif;?><BR><span class="info_text"><?php echo $visit['document_url'];?></span>
                        </TD>
                    </TR>
                    <?php if (!empty($visit['referer_url'])):?>
                    <TR>
                        <TD class="visit_icon" rowspan="2" align="right" valign="top">

                            <span class="h_label"><img src="<?php echo $this->makeImageLink('base/i/referer_icon.gif');?>" alt="Refering URL"></span>
                        </TD>

                        <TD valign="top" colspan="2">
                            <a href="<?php echo $visit['referer_url'];?>"><?php if (!empty($visit['referer_page_title'])):?><span class="inline_h4"><?php echo $this->truncate($visit['referer_page_title'], 80, '...');?></span></a><BR><span class="info_text"><?php echo $this->truncate($visit['referer_url'], 80, '...');?></span><?php else:?><?php echo $this->truncate($visit['referer_url'], 50, '...');?><?php endif;?></a>
                        </TD>

                    </TR>
                    <?php endif;?>
                    <?php if (!empty($visit['referer_snippet'])):?>
                    <TR>
                        <TD colspan="1">
                            <span class="snippet_text"><?php echo $visit['referer_snippet'];?></span>
                        </TD>

                    </TR>
                    <?php endif;?>
                </table>

            </TD>
            <!-- right col -->
            <TD valign="top" align="right" class="owa_visitSummaryRightCol">

                <div class="visitor_info_box pages_box">
                    <a href="<?php echo $this->makeLink(array('session_id' => $visit['session_id'], 'do' => 'base.reportVisit'), true);?>"><span class="large_number"><?php echo $visit['session_num_pageviews'];?></span></a>
                    <br />
                    <span class="info_text">Pages</span>
                </div>
                <BR>
                <?php if (!empty($visit['session_num_comments'])):?>
                <div class="comments_info_box">
                    <span class="large_number"><?php echo $visit['session_num_comments'];?></span><br /><span class="info_text"></span></a>
                </div>
                <?php endif;?>

            </TD>
        </TR>
    </table>

</div>