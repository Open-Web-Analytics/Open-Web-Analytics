<?php
/**
 * @var \OWA\Core\ViewScope $view
 * @var array $row  from the includer's scope -- see below
 *
 * trafficSourceSum.php is included from row_visitSummary.php, which is itself
 * included inside report_latest_visits.php's `foreach (... as $row)`. $row is
 * therefore the loop value from the INCLUDER's scope, not a view var.
 *
 * PHPStan analyses every template standalone, so it cannot see an includer's
 * locals; the @var above declares the contract rather than suppressing it.
 */
?>
<div class="propertyList" style="background-image: url('<?php $view->out( $view->makeImageLink('base/i/referer_icon.gif') );?>');background-repeat: no-repeat; padding:5px 5px 5px 35px; background-position:0px 5px;">
    <dl>
    
        <dt>Medium:</dt>
        <dd><?php $view->out( $row['medium'] );?></dd>
        
        <?php if ( isset( $row['source'] ) && $row['source'] != '(none)' ): ?>
        <dt>Source:</dt>
        <dd>
            <a href="<?php $view->out( $view->makeLink(
            array(
                'do' => 'base.reportSourceDetail',
                'source' => urlencode($row['source']),
                'site_id' => $view->get('site_id')
                ),
            true
        ) );?>">
        <?php $view->out( $row['source']);?>
            </a>
        </dd>
        <?php endif;?>
    </dl>
    <dl>
        <?php if ( isset( $row['search_term'] ) && $row['search_term'] != '(none)' ): ?>
        
        <dt>Search Term:</dt>
        <dd>
            <a href="<?php $view->out( $view->makeLink(
            array(
                'do' => 'base.reportKeywordDetail',
                'referralSearchTerms' => urlencode($row['search_term']),
                'site_id' => $view->get('site_id')
                ),
            true
        ) );?>">
                <?php $view->out( $row['search_term'] );?>
            </a>
        </dd>
        <?php endif;?>
        
    </dl>
        
    <?php if ( $row['medium'] === 'referral' ):?>
    <div style="line-height:120%; width:inherit; padding-left:20px; padding-top:15px;">
        <span class="inline_h4">
            <a href="<?php $view->safeHref( $row['referer_url'] );?>">
                <?php if (!empty($row['referer_page_title'])):?><?php $view->out( $view->truncate($row['referer_page_title'], 80, '…') );?></span></a><BR><span class="externalUrl"><?php $view->out( $view->truncate($row['referer_url'], 80, '…') );?><?php else:?><?php $view->out( $view->truncate($row['referer_url'], 80, '…') );?><?php endif;?>
            </a>
        </span>
        
        <?php if ( ! empty( $row['referer_snippet'] ) ):?>
        <br><span class="snippet_text"><?php $view->out( $row['referer_snippet'] );?></span>
        <?php endif;?>
    </div>
    <?php endif;?>
</div>