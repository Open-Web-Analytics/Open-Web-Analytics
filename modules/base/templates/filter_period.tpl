<div id="owa_reportPeriodControl">

	<table id="owa_reportPeriodLabelContainer" cellpadding="0" cellspacing="0">
		<TR>
			<TD class="owa_reportPeriodLabelText">
				<span><?php $this->out( $this->get( 'period_label' ) );?><?php $this->out( $this->get( 'date_label' ) );?></span>						
			</TD>
			<TD class="owa_reportRevealControl"></TD>	
		</TR>
	</table>
	
	<table id="owa_reportPeriodFiltersContainer" style="display:none;" cellpadding="0" cellspacing="0">
		<TR>
			<TH colspan="3">
				Enter a Date Range:
			</TH>
		</TR>
		<TR>
			<TD class="picker">
				<div>Start: <input type="text" id="owa_report-datepicker-start-display" size="10"></div>
				<div id="owa_report-datepicker-start"></div>
			<TD class="picker">
				<div>End: <input type="text" id="owa_report-datepicker-end-display"  size="10"></div>
				<div id="owa_report-datepicker-end"></div>
			</TD>
			<TD>
			
			</TD>
		
			<TD valign="top">
				Predefined Periods:<BR>
				<SELECT id="owa_reportPeriodFilter" name="owa_reportPeriodFilter">
	<?php foreach ($reporting_periods as $reporting_period => $value):?>
					<OPTION VALUE="<?php echo $reporting_period;?>" <?php if ($params['period'] == $reporting_period): echo 'selected'; endif; ?>><?php echo $value['label'];?></OPTION>
	<?php endforeach;?>
				</SELECT>	
				<P><INPUT class="submit-button" type="submit" id="owa_reportPeriodFilterSubmit" name="" value="Change Date Range"></P>
			</TD>
		</TR>
		<TR>
			<TD colspan="3"></TD>
		</TR>
	</table>
</div>