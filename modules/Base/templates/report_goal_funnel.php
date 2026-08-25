<?php /** @var \OWA\Core\ViewScope $view */ ?>

<div>

    Choose a goal: <select id="goalChooser">
        <?php for ($i = 1; $i <= $view->numGoals; $i++):?>
        <option <?php if ($i == $view->goal_number): echo 'SELECTED'; endif;?> value="<?php $view->out($i, false); ?>">Goal <?php $view->out($i, false); ?></option>
        <?php endfor; ?>
    </select>

    <?php
        /*
         * Counting scope, on the URL and nowhere else.
         *
         * A funnel scope is a way of LOOKING at the report, not a property of
         * the site: persisting it would make the same link mean different
         * things to two people. So the toggle is just a link that carries the
         * other value, and every other parameter rides along with it.
         */
        $owa_other = $view->get('funnel_scope_other');
    ?>
    &nbsp; Counting:
    <span class="funnelScope"><?php $view->out( $view->get('funnel_scope') === 'session' ? 'visits' : 'visitors' ); ?></span>
    <a href="<?php echo $view->makeLink( array(
        'do'          => 'base.report',
        'reportId'    => 'goal-funnel',
        'goalNumber'  => $view->goal_number,
        'funnelScope' => $owa_other,
    ), true ); ?>">show <?php $view->out( $owa_other === 'session' ? 'visits' : 'visitors' ); ?> instead</a>
</div>

<?php if ( $view->get('funnel_segment_error') ): ?>
<?php
    /*
     * The segment was refused, so the funnel below is empty on purpose.
     * Drawing zeroes with no explanation would read as "nobody converted".
     */
?>
<div class="notice" role="status"><?php $view->out( $view->get('funnel_segment_error') ); ?></div>
<?php endif; ?>

<?php if ( $view->get('funnel') ):?>
<table class="funnel" border="0" style="min-width:100%;">
    <tr>
        <td class="funnelLeft">Prior Page Viewed</td>
        <td class="funnelMiddle"><h2><?php $view->out($view->goal_conversion_rate);?> conversion rate</h2></td>
        <td class="funnelRight" style="text-align:right;">Next Page Viewed</td>
    </tr>
    <?php foreach ($view->funnel as $k => $step):?>
    <tr>
        <td width="33%" valign="top" class="funnelLeft" id="entrances_step_<?php $view->out($step['step_number']);?>">
            <div class="funnelLargeNumber entranceCount" style="text-align: right;" id="prior_page_count_step_<?php $view->out($step['step_number']);?>">

            </div>
        </td>
        <td width="33%" valign="top" class="funnelMiddle funnelStep" id="step_<?php $view->out($step['step_number']);?>">
            <div class="funnelStepName">Step <?php $view->out($step['step_number']);?>: <?php $view->out($step['name']);?></div>
            <div class="funnelStepCount"><?php $view->out($step['visitors']);?> <span class="visitorCountLabel"><?php $view->out( $view->get('funnel_scope_label') ); ?></span></div>
            <div class="funnelStepPath"><?php $view->out($step['path']);?></div>
            <div class="genericHorizontalList" style="padding-top:10px;font-size:12px;">
                <ul class="">


                    <li>
                        <span class="inline_h4"><a href="<?php echo $view->makeLink(array('do' => 'base.report', 'reportId' => 'domstreams', 'pagePath' => $step['path']), true);?>">Watch Domstreams</a></span>
                    </li>

                    <li>
                        <span class="inline_h4"><a href="<?php echo $view->makeLink(array('do' => 'base.report', 'reportId' => 'dom-clicks', 'pagePath' => $step['path']), true);?>">Analyze Dom Clicks</a></span>
                    </li>
                </ul>
            </div>
        </td>
        <td width="33%" valign="top" class="funnelRight" id="exits_step_<?php $view->out($step['step_number']);?>">
            <div class="funnelLargeNumber exitCount" id="next_page_count_step_<?php $view->out($step['step_number']);?>"></div>
        </td>
    </tr>
    <?php if (array_key_exists($k+1, $view->funnel)):?>
    <tr>
        <td class="funnelLeft"></td>
        <td class="funnelMiddle funnelLargeNumber funnelFlow">
            <?php $view->out($view->funnel[$k+1]['visitor_percentage']);?><BR>
            <span class="secondaryText">Proceeded to step: <?php $view->out($view->funnel[$k+1]['name']); ?></span>
        </td>
        <td class="funnelRight"></td>
    </tr>
    <?php endif;?>
    <?php endforeach;?>
</table>

<script>
var funnel_json = <?php $view->out($view->funnel_json, false);?>;
var i = 1;
for (step in funnel_json) {
    step = parseInt(step);

    var total_steps = OWA.util.countObjectProperties(funnel_json);
    var operator = '==';
    if (i < total_steps ) {
        next_step = step + 1;
    } else {
        next_step = step;
    }

    if (i == 1) {
        prior_step = step;
    } else {
        prior_step = step - 1 ;
    }

    // prior pages
    var name = 'entrances_step_' + funnel_json[step]['step_number'] ;
    OWA.items[name] = new OWA.resultSetExplorer(name);
    OWA.items[name].setDataLoadUrl(
        OWA.items[name].makeApiRequestUrl( 'reports',{
            module: 'base',
            version: 'v1',
            metrics: 'visitors',
            dimensions: 'priorPagePath',
            sort: 'visitors-',
            format: 'json',
            constraints: 'pagePath' + operator + funnel_json[step]['url'] + ',priorPagePath!=' + funnel_json[prior_step]['url'],
            resultsPerPage: 5,
            siteId: OWA.items['base-reportGoalFunnel'].getSiteId(),
            period: OWA.items['base-reportGoalFunnel'].getPeriod(),
            startDate: OWA.items['base-reportGoalFunnel'].getStartDate(),
            endDate: OWA.items['base-reportGoalFunnel'].getEndDate()
    }));
    OWA.items[name].asyncQueue.push(['refreshGrid']);
    OWA.items[name].asyncQueue.push([
            'renderTemplate',
            '<*= this.d.resultSet.aggregates.visitors.formatted_value *>',
            {d: OWA.items[name]},
            'replace',
            'prior_page_count_step_' + funnel_json[step]['step_number']
    ]);
    OWA.items[name].load();
    // next page
    var name = 'exits_step_' + funnel_json[step]['step_number'] ;
    OWA.items[name] = new OWA.resultSetExplorer(name);
    OWA.items[name].setDataLoadUrl(
        OWA.items[name].makeApiRequestUrl( 'reports',{
            module: 'base',
            version: 'v1',
            metrics: 'visitors',
            dimensions: 'pagePath',
            sort: 'visitors-',
            format: 'json',
            constraints: 'priorPagePath' + operator + funnel_json[step]['url'] + ',pagePath!=' + funnel_json[next_step]['url'],
            resultsPerPage: 5,
            siteId: OWA.items['base-reportGoalFunnel'].getSiteId(),
            period: OWA.items['base-reportGoalFunnel'].getPeriod(),
            startDate: OWA.items['base-reportGoalFunnel'].getStartDate(),
            endDate: OWA.items['base-reportGoalFunnel'].getEndDate()
    }));
    OWA.items[name].asyncQueue.push([
            'renderTemplate',
            '<*= this.d.resultSet.aggregates.visitors.formatted_value *>',
            {d: OWA.items[name]},
            'replace',
            'next_page_count_step_' + funnel_json[step]['step_number']
    ]);
    OWA.items[name].asyncQueue.push(['refreshGrid']);
    OWA.items[name].load();
    i++;
}
</script>
<?php else: ?>
No Funnel has been configured for this goal.
<?php if ($view->getCurrentUser()->isCapable('edit_settings')): ?>
    <a href="<?php echo $view->makeLink(array('do' => 'base.optionsGoalEntry', 'goal_number' => $view->goal_number, 'siteId' => $view->params['siteId']));?>">Add a funnel</a>
<?php endif; ?>
<?php endif;?>

<script>
// jquery binding for select list
// Bind event handlers
jQuery(document).ready(function(){

    jQuery('#goalChooser').change(function() {
            var num = jQuery("#goalChooser option:selected").val();
            OWA.items['base-reportGoalFunnel'].setRequestProperty('goalNumber', num);
            OWA.items['base-reportGoalFunnel'].reload();
    });
});
</script>