
<div>
	
	Choose a goal: <select id="goalChooser">
		<?php for ($i = 1; $i <= $numGoals; $i++):?>
		<option <?php if ($i == $goal_number): echo 'SELECTED'; endif;?> value="<?php $this->out($i, false); ?>">Goal <?php $this->out($i, false); ?></option>
		<?php endfor; ?>
	</select>
</div>

<?php if ( $this->get('funnel') ):?>
<table class="funnel" border="0" style="min-width:100%;">
	<tr>
		<td class="funnelLeft"></td>
		<td class="funnelMiddle"><h2><?php $this->out($goal_conversion_rate);?> conversion rate</h2></td>
		<td class="funnelRight"></td>
	</tr>
	<?php foreach ($funnel as $k => $step):?>
	<tr>
		<td width="33%" valign="top" class="funnelLeft" id="entrances_step_<?php $this->out($step['step_number']);?>">
			<div class="funnelLargeNumber entranceCount" style="text-align: right;" id="prior_page_count_step_<?php $this->out($step['step_number']);?>">
				
			</div>
		</td>
		<td width="33%" valign="top" class="funnelMiddle funnelStep" id="step_<?php $this->out($step['step_number']);?>">
			<div class="funnelStepName">Step <?php $this->out($step['step_number']);?>: <?php $this->out($step['name']);?></div>
			<div class="funnelStepCount"><?php $this->out($step['visitors']);?> <span class="visitorCountLabel">visitors</span></div>
			<div class="funnelStepUrl"><?php $this->out($step['url']);?></div>
		</td>
		<td width="33%" valign="top" class="funnelRight" id="exits_step_<?php $this->out($step['step_number']);?>">
			<div class="funnelLargeNumber exitCount" id="next_page_count_step_<?php $this->out($step['step_number']);?>"></div>
		</td>
	</tr>
	<?php if (array_key_exists($k+1, $funnel)):?>
	<tr>
		<td class="funnelLeft"></td>
		<td class="funnelMiddle funnelLargeNumber funnelFlow">
			<?php $this->out($funnel[$k+1]['visitor_percentage']);?><BR>
			<span class="secondaryText">Proceeded to step: <?php $this->out($funnel[$k+1]['name']); ?></span>
		</td>
		<td class="funnelRight"></td>
	</tr>
	<?php endif;?>
	<?php endforeach;?>
</table>

<script>
var funnel_json = <?php $this->out($funnel_json, false);?>;
for (step in funnel_json) {
	var operator = '==';
	// prior pages
	var name = 'entrances_step_' + funnel_json[step]['step_number'] ;															  
	OWA.items[name] = new OWA.resultSetExplorer(name);
	OWA.items[name].setDataLoadUrl(
		OWA.items[name].makeApiRequestUrl( 'getResultSet',{
			metrics: 'visitors',
			dimensions: 'priorPagePath',
			sort: 'visitors-',
			format: 'json',
			constraints: 'pagePath' + operator + funnel_json[step]['url'],
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
		OWA.items[name].makeApiRequestUrl( 'getResultSet',{
			metrics: 'visitors',
			dimensions: 'pagePath',
			sort: 'visitors-',
			format: 'json',
			constraints: 'priorPagePath' + operator + funnel_json[step]['url'],
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
	
	// jquery binding for select list
	// Bind event handlers
	jQuery(document).ready(function(){   
	
		jQuery('#goalChooser').change(function() {
				var num = jQuery("#goalChooser option:selected").val();
				alert(num);
				OWA.items['base-reportGoalFunnel'].setRequestProperty('goalNumber', num);
				OWA.items['base-reportGoalFunnel'].reload();
		});
	});
	
}
</script>
<?php else: ?>
No Funnel has been configured for this goal. <a href="<?php echo $this->makeLink(array('do' => 'base.optionsGoalEntry', 'goal_number' => $goal_number, 'siteId' => $params['site_id']));?>">Add a funnel</a>
<?php endif;?>