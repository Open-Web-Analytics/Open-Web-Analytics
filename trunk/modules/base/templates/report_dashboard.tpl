<div class="section_header">Site Usage</div>
<div class="owa_reportSectionContent">
<? include ('report_dashboard_summary_stats.tpl');?>

	<BR>
	<div class="owa_reportElement">
	<?=$this->getInpageWidget('base.widgetSiteStats', array('height' => '100px', 'width' => '', 'period' => 'last_thirty_days'));?>	
	</div>
	<P></P>
	<table class="owa_reportElement" size="100%">
		<TR>	
			<TD valign="top" width="50%">
					<?=$this->getWidget('base.widgetTopPages', array( 'width' => '100%', 'period' => $params['period']));?>
			</TD>
			<TD valign="top" width="50%">
				<?=$this->getWidget('base.widgetVisitorTypes', array('height' => '200px', 'width' => '100%', 'period' => $params['period']));?>
			</TD>
		</TR>
	</table>
</div>

<div class="section_header">Traffic Sources</div>
<div class="owa_reportSectionContent">
	<table class="owa_reportElement">
		<TR>
			<TD valign="top" width="50%">	
				<?=$this->getWidget('base.widgetVisitorSources', array('height' => '320px', 'width' => '100%', 'period' => $params['period']));?>
	
			</TD>
			
			<TD valign="top" width="50%">	
				<?=$this->getWidget('base.widgetTopReferers', array('height' => '', 'width' => '100%', 'period' => $params['period']));?>
			</TD>
		</TR>
	</table>
</div>

<div class="section_header">Site Activity</div>
<div class="owa_reportSectionContent">

	<table class="owa_reportElement">
		<TR>
			<TD valign="top" width="66%">
				<?=$this->getWidget('base.widgetLatestVisits', array('height' => '', 'width' => '', 'period' => $params['period']));?>
			</TD>
			<TD valign="top" width="33%">
				<?=$this->getWidget('base.widgetOwaNews');?>
			</TD>
		</TR>
	</table>
</div>

