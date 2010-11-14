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
			<TH>
				Enter a Date Range:
			</TH>
		</TR>
		<TR>
			<TD>
				<input type="text" id="owa_report-datepicker-start" size="10"> to <input type="text" id="owa_report-datepicker-end"  size="10">
			</TD>
		</TR>	
		<TR>
			<TH colspan="2">
				Or choose a predefined date range below:
			</TH>
		</TR>
		<TR>
			<TD colspan="2">
				<SELECT id="owa_reportPeriodFilter" name="owa_reportPeriodFilter">
	<?php foreach ($reporting_periods as $reporting_period => $value):?>
					<OPTION VALUE="<?php echo $reporting_period;?>" <?php if ($params['period'] == $reporting_period): echo 'selected'; endif; ?>><?php echo $value['label'];?></OPTION>
	<?php endforeach;?>
				</SELECT>	
			</TD>
		</TR>
		<TR>
			<TD colspan="2"><INPUT type="submit" id="owa_reportPeriodFilterSubmit" name="" value="Change Date Period"></TD>
		</TR>
	</table>
</div>