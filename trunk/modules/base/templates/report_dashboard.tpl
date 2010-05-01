<div class="owa_reportSectionHeader">Metrics</div>
<div class="owa_reportSectionContent">
<?php include ('report_dashboard_summary_stats.tpl');?>
</div>

<?php if ($actions->getDataRows()):?>
<div class="section_header">Actions</div>
<div class="owa_reportSectionContent">
<table cellpadding="0" cellspacing="0" width="100%">
	<tr>
		<td valign="top">
		<?php foreach($actions->getDataRows() as $k => $row):?>
			<div class="owa_metricInfobox">
				<p class="owa_metricInfoboxLabel"><?php echo $row['actionName']['value'];?></p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo $row['actions']['value'];?></p>	
			</div>
		<?php endforeach;?>
		</td>
	</tr>
</table>
</div>
<?php endif;?>

<div class="section_header">Trends</div>
<div class="owa_reportSectionContent">
	<BR>
	<div class="owa_reportElement">
	<?php echo $this->getInpageWidget('base.widgetSiteStats', array('height' => '100px', 'width' => '', 'period' => 'last_thirty_days'));?>	
	</div>
	<P></P>
	<table class="owa_reportElement" size="100%">
		<TR>	
			<TD valign="top" width="50%">
					<?php echo $this->getWidget('base.widgetTopPages', array( 'width' => '100%', 'period' => $params['period']));?>
			</TD>
			<TD valign="top" width="50%">
				<?php echo $this->getWidget('base.widgetVisitorTypes', array('height' => '200px', 'width' => '100%', 'period' => $params['period']));?>
			</TD>
		</TR>
	</table>
</div>


<div class="section_header">Traffic Sources</div>
<div class="owa_reportSectionContent">
	<table class="owa_reportElement">
		<TR>
			<TD valign="top" width="50%">	
				<?php echo $this->getWidget('base.widgetVisitorSources', array('height' => '320px', 'width' => '100%', 'period' => $params['period']));?>
	
			</TD>
			
			<TD valign="top" width="50%">	
				<?php echo $this->getWidget('base.widgetTopReferers', array('height' => '', 'width' => '100%', 'period' => $params['period']));?>
			</TD>
		</TR>
	</table>
</div>

<div class="section_header">Site Activity</div>
<div class="owa_reportSectionContent">

	<table class="owa_reportElement">
		<TR>
			<TD valign="top" width="50%">
				<?php echo $this->getWidget('base.widgetLatestVisits', array('height' => '', 'width' => '', 'period' => $params['period']));?>
			</TD>
			<TD valign="top" width="50%">
				<?php echo $this->getWidget('base.widgetOwaNews');?>
			</TD>
		</TR>
	</table>
</div>

