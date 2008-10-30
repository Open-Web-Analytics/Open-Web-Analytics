
<? //$this->getWidget('base.dashboardTrendWidget', array('height' => 100, 'width' => 900, 'period' => 'last_thirty_days', 'format' => 'sparkline'), false);?>


<?=$this->getWidget('base.dashboardTrendWidget', array('height' => 200, 'width' => '', 'period' => 'last_thirty_days'));?>		
<BR>


<BR>

<table class="owa_reportElement">
	<TR>
		<TD valign="top" width="50%">	
			<?=$this->getWidget('base.widgetTopPages', array('height' => '', 'width' => '', 'period' => $params['period']));?>
		</TD>
		
		<TD valign="top" width="50%">	
			<?=$this->getWidget('base.widgetTopReferers', array('height' => '', 'width' => '', 'period' => $params['period']));?>
		</TD>
	</TR>
</table>
<BR>

<table class="owa_reportElement">
	<TR>	
		<TD width="50%">
			<?=$this->getWidget('base.widgetVisitorSources', array('height' => '', 'width' => '', 'period' => $params['period']));?>

		</TD>
		<TD>
			<fieldset id="visitor_types">	
				<legend>Visitor Types</legend>
				<img src="<?=$this->graphLink(array('view' => 'base.graphVisitorTypes'), true); ?>">
			</fieldset>
		</TD>
	</TR>
</table>
<BR>
<BR>

<? include ('report_dashboard_summary_stats.tpl');?>
<table class="">
	<TR>
		
		<TD valign="top">
			
			<fieldset id="recent_visitors">
				<legend>Recent Visitors</legend>
				<? include ('report_latest_visits.tpl');?>
			</fieldset>
			
			<?=$this->makePagination($pagination, $params['do']);?>
		</TD>
	</TR>
</table>


